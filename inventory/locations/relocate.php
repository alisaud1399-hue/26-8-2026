<?php
/**
 * inventory/locations/relocate.php — معالج نقل الأقسام (Dept Relocation)
 * إخلاء الغرف المصدر ← إسناد الغرف الهدف ← فصل/توزيع أصول القسم
 * مع سجل إشغال تاريخي (room_occupancy_history)
 */
require_once dirname(__DIR__, 2) . '/config.php';
if (file_exists(__DIR__ . '/_helpers.php')) require_once __DIR__ . '/_helpers.php';
page_guard('inventory.index');
if (!(is_admin() || (function_exists('can') && can('inventory.locations', 'manage')))) abort(403);
$rtl = is_rtl();
$uid = (int)(current_user()['id'] ?? 0);

/* ═══ جدول سجل الإشغال (إن لم يوجد) ═══ */
$pdo->exec("CREATE TABLE IF NOT EXISTS room_occupancy_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  dept_id INT UNSIGNED NULL,
  change_type ENUM('assign','vacate','move_in','move_out') NOT NULL,
  decision_ref VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  changed_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_roh_room (room_id), INDEX idx_roh_dept (dept_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══ معالجة POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'relocate') {
        $dept = (int)($_POST['dept_id'] ?? 0);
        $src  = array_map('intval', $_POST['src_rooms'] ?? []);
        $dst  = array_map('intval', $_POST['dst_rooms'] ?? []);
        $decision = trim($_POST['decision_ref'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $move_assets = !empty($_POST['move_assets']);
        if (!$dept || (!$src && !$dst)) {
            flash('error', $rtl ? 'اختر القسم وغرفة واحدة على الأقل.' : 'Select dept and at least one room.');
        } else {
            try {
                $pdo->beginTransaction();
                $h = $pdo->prepare("INSERT INTO room_occupancy_history (room_id,dept_id,change_type,decision_ref,notes,changed_by) VALUES (?,?,?,?,?,?)");
                // 1) إخلاء الغرف المصدر
                foreach ($src as $rid) {
                    $pdo->prepare("UPDATE item_locations SET dept_id=NULL WHERE id=?")->execute([$rid]);
                    $h->execute([$rid, $dept, 'move_out', $decision ?: null, $notes ?: null, $uid]);
                }
                // 2) إسناد الغرف الهدف
                foreach ($dst as $rid) {
                    $cur = $pdo->prepare("SELECT dept_id FROM item_locations WHERE id=?"); $cur->execute([$rid]);
                    $old = (int)$cur->fetchColumn();
                    if ($old && $old !== $dept) $h->execute([$rid, $old, 'vacate', $decision ?: null, $notes ?: null, $uid]);
                    $pdo->prepare("UPDATE item_locations SET dept_id=? WHERE id=?")->execute([$dept, $rid]);
                    $h->execute([$rid, $dept, 'move_in', $decision ?: null, $notes ?: null, $uid]);
                }
                // 3) فصل أصول القسم من الغرف المصدر (تظهر بدون موقع لإعادة التوزيع)
                $detached = 0;
                if ($move_assets && $src) {
                    $ph = implode(',', array_fill(0, count($src), '?'));
                    $st = $pdo->prepare("UPDATE assets SET location_id=NULL WHERE department_id=? AND location_id IN ($ph)");
                    $st->execute(array_merge([$dept], $src));
                    $detached = $st->rowCount();
                }
                $pdo->commit();
                flash('success', ($rtl ? "تم النقل بنجاح. أصول مفصولة للتوزيع: $detached" : "Relocated. Detached assets: $detached"));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/inventory/locations/relocate.php?dept=' . $dept); exit;
    }

    if ($action === 'assign_asset' || $action === 'assign_all') {
        $room_id = (int)($_POST['room_id'] ?? 0);
        $dept = (int)($_POST['dept_id'] ?? 0);
        $room = $pdo->prepare("SELECT r.id, r.name, f.name f_name, b.name b_name FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
        $room->execute([$room_id]); $rm = $room->fetch(PDO::FETCH_ASSOC);
        if (!$rm) { flash('error', $rtl ? 'غرفة غير صالحة.' : 'Invalid room.'); }
        else {
            if ($action === 'assign_asset') {
                $ids = [(int)($_POST['asset_id'] ?? 0)];
            } else {
                $ids = $pdo->prepare("SELECT id FROM assets WHERE department_id=? AND (location_id IS NULL OR location_id=0)");
                $ids->execute([$dept]); $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
            }
            $up = $pdo->prepare("UPDATE assets SET location_id=?, loc_building=?, loc_floor=?, loc_room=? WHERE id=?");
            $n = 0;
            foreach ($ids as $aid) { if (!$aid) continue; $up->execute([$room_id, $rm['b_name'], $rm['f_name'], $rm['name'], $aid]); $n++; }
            flash('success', ($rtl ? "تم توزيع $n أصل إلى: " : "Assigned $n assets to: ") . $rm['name']);
        }
        header('Location: ' . BASE_URL . '/inventory/locations/relocate.php?dept=' . $dept); exit;
    }
}

/* ═══ بيانات العرض ═══ */
$dept_sel = (int)($_GET['dept'] ?? 0);
$depts = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT r.id, r.name, r.name_en, r.dept_id, d.name dept_name, f.name f_name, b.name b_name
    FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id
    LEFT JOIN departments d ON d.id=r.dept_id WHERE r.location_type='room' AND r.is_active=1 ORDER BY b.name, f.name, r.name")->fetchAll(PDO::FETCH_ASSOC);
$vacant = 0; foreach ($rooms as $r) if (!$r['dept_id']) $vacant++;
$moves = (int)$pdo->query("SELECT COUNT(*) FROM room_occupancy_history")->fetchColumn();
$detached = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE department_id IS NOT NULL AND (location_id IS NULL OR location_id=0)")->fetchColumn();

$src_rooms = []; $dst_by_bld = [];
foreach ($rooms as $r) {
    if ($dept_sel && (int)$r['dept_id'] === $dept_sel) $src_rooms[] = $r;
    else $dst_by_bld[$r['b_name'] ?? '—'][] = $r;
}
$detached_assets = [];
if ($dept_sel) {
    $st = $pdo->prepare("SELECT id, tag_number, description FROM assets WHERE department_id=? AND (location_id IS NULL OR location_id=0) ORDER BY tag_number LIMIT 200");
    $st->execute([$dept_sel]); $detached_assets = $st->fetchAll(PDO::FETCH_ASSOC);
}
$history = $pdo->query("SELECT h.*, r.name room_name, d.name dept_name, u.full_name actor
    FROM room_occupancy_history h LEFT JOIN item_locations r ON r.id=h.room_id LEFT JOIN departments d ON d.id=h.dept_id LEFT JOIN users u ON u.id=h.changed_by
    ORDER BY h.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
$TYPE_AR = ['move_in'=>'دخول','move_out'=>'خروج','vacate'=>'إخلاء','assign'=>'إسناد'];
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl ? 'نقل الأقسام' : 'Dept Relocation' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,select,input,textarea{font-family:'Tajawal',sans-serif}
.rl-wrap{max-width:1280px;margin:0 auto;padding:18px}
.rl-hero{background:linear-gradient(135deg,#9a3412,#ea580c 55%,#f97316);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;box-shadow:0 12px 32px rgba(234,88,12,.25);display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.rl-hero .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.rl-hero h1{margin:0;font-size:24px;font-weight:900}.rl-hero p{margin:4px 0 0;font-size:13px;opacity:.9}
.rl-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
@media(max-width:920px){.rl-stats{grid-template-columns:1fr}}
.rl-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 18px;display:flex;align-items:center;gap:14px}
.rl-stat .ic{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.rl-stat .v{font-size:21px;font-weight:800;line-height:1}.rl-stat .l{font-size:12px;color:#64748b;margin-top:4px;font-weight:600}
.rl-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:18px;margin-bottom:16px}
.rl-card h3{margin:0 0 14px;font-size:15px;font-weight:900;display:flex;gap:9px;align-items:center}
.rl-card h3 i{color:#ea580c;background:#fff7ed;padding:8px;border-radius:9px;font-size:13px}
.rl-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:900px){.rl-grid{grid-template-columns:1fr}}
.rl-col{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;max-height:340px;overflow-y:auto}
.rl-col h4{margin:0 0 10px;font-size:12.5px;font-weight:900;color:#475569}
.rl-room{display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:9px;background:#fff;border:1px solid #eef2f7;margin-bottom:6px;font-size:12px}
.rl-room input{accent-color:#ea580c}
.rl-room .nm{flex:1;font-weight:700}.rl-room .pt{font-size:10.5px;color:#94a3b8}
details.rl-b{margin-bottom:6px}details.rl-b summary{cursor:pointer;font-weight:800;font-size:12px;padding:6px 8px;background:#fff;border:1px solid #eef2f7;border-radius:9px}
.rl-in{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;background:#fff}
.rl-btn{border:none;border-radius:11px;padding:12px 22px;font-weight:900;font-size:13.5px;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.rl-btn.go{background:linear-gradient(135deg,#ea580c,#f97316);color:#fff;box-shadow:0 4px 14px rgba(234,88,12,.3)}
.rl-btn.sm{padding:7px 14px;font-size:12px;border-radius:9px}
.rl-tag{font-family:'Inter',monospace;font-size:11px;background:#fff7ed;color:#9a3412;padding:2px 8px;border-radius:6px;font-weight:700}
.flash{background:#fff;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-weight:800;font-size:13px;border-right:4px solid #16a34a;color:#065f46}
.flash.err{border-right-color:#dc2626;color:#991b1b}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="rl-wrap">
<?php foreach (get_flash() as $fm): ?><div class="flash <?= $fm['type']==='error'?'err':'' ?>"><?= e($fm['message']) ?></div><?php endforeach; ?>

<section class="rl-hero">
<div class="ic"><i class="fa-solid fa-right-left"></i></div>
<div style="flex:1;min-width:220px"><h1><?= $rtl ? 'نقل الأقسام' : 'Dept Relocation' ?></h1>
<p><?= $rtl ? 'إخلاء ← إسناد ← توزيع الأصول، مع سجل إشغال تاريخي' : 'Vacate → assign → redistribute, with occupancy log' ?></p></div>
<a class="rl-btn" style="background:rgba(255,255,255,.18);color:#fff" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl ? 'الداشبورد' : 'Hub' ?></a>
</section>

<div class="rl-stats">
<div class="rl-stat"><div class="ic" style="background:#fff7ed;color:#ea580c"><i class="fa-solid fa-door-open"></i></div><div><div class="v"><?= $vacant ?></div><div class="l"><?= $rtl ? 'غرف شاغرة حالياً' : 'Currently vacant rooms' ?></div></div></div>
<div class="rl-stat"><div class="ic" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-box-open"></i></div><div><div class="v"><?= $detached ?></div><div class="l"><?= $rtl ? 'أصول بدون موقع (بانتظار التوزيع)' : 'Unmapped assets' ?></div></div></div>
<div class="rl-stat"><div class="ic" style="background:#ede9fe;color:#7c3aed"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="v"><?= $moves ?></div><div class="l"><?= $rtl ? 'عمليات في سجل الإشغال' : 'Occupancy events' ?></div></div></div>
</div>

<div class="rl-card">
<h3><i class="fa-solid fa-building"></i> <?= $rtl ? '1) اختر القسم المنقول' : '1) Select department' ?></h3>
<form method="GET"><select name="dept" class="rl-in" onchange="this.form.submit()">
<option value=""><?= $rtl ? '— اختر القسم —' : '— Select —' ?></option>
<?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= $dept_sel==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
</select></form>
</div>

<?php if ($dept_sel): ?>
<form method="POST">
<?= csrf_input() ?><input type="hidden" name="action" value="relocate"><input type="hidden" name="dept_id" value="<?= $dept_sel ?>">
<div class="rl-card">
<h3><i class="fa-solid fa-right-left"></i> <?= $rtl ? '2) الغرف المصدر (إخلاء) ← الغرف الهدف (إسناد)' : '2) Source → Target rooms' ?></h3>
<div class="rl-grid">
<div class="rl-col"><h4><?= $rtl ? 'الغرف الحالية للقسم (تُخلي):' : 'Current rooms (vacate):' ?></h4>
<?php if (!$src_rooms): ?><div style="color:#94a3b8;font-size:12px"><?= $rtl ? 'لا غرف حالياً لهذا القسم.' : 'No current rooms.' ?></div><?php endif; ?>
<?php foreach ($src_rooms as $r): ?>
<label class="rl-room"><input type="checkbox" name="src_rooms[]" value="<?= $r['id'] ?>" checked>
<span class="nm"><?= e($r['name']) ?></span><span class="pt"><?= e($r['b_name']) ?> / <?= e($r['f_name']) ?></span></label>
<?php endforeach; ?></div>
<div class="rl-col"><h4><?= $rtl ? 'الغرف الجديدة (تُسنَد):' : 'New rooms (assign):' ?></h4>
<?php foreach ($dst_by_bld as $b => $list): ?>
<details class="rl-b"><summary><?= e($b) ?> (<?= count($list) ?>)</summary>
<?php foreach ($list as $r): ?>
<label class="rl-room"><input type="checkbox" name="dst_rooms[]" value="<?= $r['id'] ?>">
<span class="nm"><?= e($r['name']) ?></span><span class="pt"><?= $r['dept_name'] ? e($r['dept_name']) : ($rtl?'شاغرة':'vacant') ?></span></label>
<?php endforeach; ?></details>
<?php endforeach; ?></div>
</div>
</div>
<div class="rl-card">
<h3><i class="fa-solid fa-file-signature"></i> <?= $rtl ? '3) القرار والخيارات' : '3) Decision & options' ?></h3>
<div class="rl-grid">
<div><label style="font-size:12px;font-weight:800"><?= $rtl ? 'مرجع القرار' : 'Decision ref' ?></label><input class="rl-in" name="decision_ref" placeholder="<?= $rtl ? 'قرار رقم ...' : 'Decision no.' ?>"></div>
<div><label style="font-size:12px;font-weight:800"><?= $rtl ? 'ملاحظات' : 'Notes' ?></label><input class="rl-in" name="notes"></div>
</div>
<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px;font-weight:700">
<input type="checkbox" name="move_assets" value="1" checked style="accent-color:#ea580c">
<?= $rtl ? 'فصل أصول القسم من الغرف المصدر (تظهر كأصول بدون موقع لإعادة التوزيع)' : 'Detach dept assets from source rooms (for redistribution)' ?>
</label>
<div style="margin-top:16px"><button class="rl-btn go" type="submit" onclick="return confirm('<?= $rtl ? 'تنفيذ النقل؟ سيُحدَّث إشغال الغرف ويُفصل الأصول المحددة.' : 'Execute relocation?' ?>')"><i class="fa-solid fa-right-left"></i> <?= $rtl ? 'تنفيذ النقل' : 'Execute' ?></button></div>
</div>
</form>

<div class="rl-card">
<h3><i class="fa-solid fa-box-open"></i> <?= $rtl ? '4) طابور إعادة التوزيع' : '4) Redistribution queue' ?> (<?= count($detached_assets) ?>)</h3>
<?php if ($detached_assets && $src_rooms): ?>
<form method="POST" style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
<?= csrf_input() ?><input type="hidden" name="action" value="assign_all"><input type="hidden" name="dept_id" value="<?= $dept_sel ?>">
<select name="room_id" class="rl-in" style="max-width:320px"><?php foreach ($rooms as $r) if ((int)$r['dept_id']===$dept_sel): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endif; ?></select>
<button class="rl-btn go sm" type="submit"><i class="fa-solid fa-layer-group"></i> <?= $rtl ? 'توزيع الكل لهذه الغرفة' : 'Assign all here' ?></button>
</form>
<?php foreach ($detached_assets as $a): ?>
<form method="POST" class="rl-room" style="background:#fff">
<?= csrf_input() ?><input type="hidden" name="action" value="assign_asset"><input type="hidden" name="asset_id" value="<?= $a['id'] ?>"><input type="hidden" name="dept_id" value="<?= $dept_sel ?>">
<span class="rl-tag"><?= e($a['tag_number'] ?: '—') ?></span><span class="nm"><?= e($a['description']) ?></span>
<select name="room_id" class="rl-in" style="max-width:200px;padding:6px 8px;font-size:12px"><?php foreach ($rooms as $r) if ((int)$r['dept_id']===$dept_sel): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endif; ?></select>
<button class="rl-btn go sm" type="submit"><?= $rtl ? 'توزيع' : 'Assign' ?></button>
</form>
<?php endforeach; ?>
<?php else: ?><div style="color:#94a3b8;font-size:12.5px"><?= $rtl ? 'لا أصول مفصولة لهذا القسم.' : 'No detached assets.' ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="rl-card">
<h3><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl ? 'سجل الإشغال الحديث' : 'Recent occupancy log' ?></h3>
<?php if (!$history): ?><div style="color:#94a3b8;font-size:12.5px"><?= $rtl ? 'لا عمليات بعد.' : 'No events yet.' ?></div><?php endif; ?>
<?php foreach ($history as $h): ?>
<div class="rl-room"><span class="rl-tag"><?= e($TYPE_AR[$h['change_type']] ?? $h['change_type']) ?></span>
<span class="nm"><?= e($h['room_name']) ?></span><span class="pt"><?= e($h['dept_name'] ?? '—') ?> · <?= e($h['actor'] ?? '') ?> · <?= e(substr($h['created_at'],0,10)) ?></span></div>
<?php endforeach; ?>
</div>

</div></main>
</div>
</body>
</html>