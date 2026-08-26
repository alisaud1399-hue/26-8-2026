<?php
/**
 * settings/locations.php — إدارة المواقع + المعالج الذكي + ملصقات QR + الترميز
 * نهائي: هيكل app-layout + قوائم أقسام متتالية + node_code + ترميز ذكي
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/location_parser.php';

page_guard('settings.index');
if (!is_admin()) {
    flash('danger', is_rtl() ? 'المديرون فقط' : 'Admins only');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
$rtl = is_rtl();

/* ═══ ترحيل تلقائي: عمود node_code إن لم يوجد ═══ */
$icols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_locations'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('node_code', $icols)) {
    try { $pdo->exec("ALTER TABLE item_locations ADD COLUMN node_code VARCHAR(20) NULL AFTER name_en"); } catch (Throwable $e) {}
}

/* ═══ مساعدات محلية (بادئة loc_ لتفادي أي تعارض) ═══ */
function loc_dept_rows(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'")->fetchAll(PDO::FETCH_COLUMN);
    $pcol = null;
    foreach (['parent_id','parent_dept_id','pid','parent'] as $c) if (in_array($c, $cols)) { $pcol = $c; break; }
    $psel = $pcol ? "$pcol AS parent_id" : "NULL AS parent_id";
    $cache = $pdo->query("SELECT id, name, name_en, $psel FROM departments WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cache as &$d) $d['parent_id'] = $d['parent_id'] !== null ? (int)$d['parent_id'] : 0;
    return $cache;
}
function loc_dept_tree(): array {
    $rows = loc_dept_rows();
    $tree = [];
    foreach ($rows as $d) if (empty($d['parent_id']))
        $tree[(int)$d['id']] = ['id'=>(int)$d['id'], 'name'=>$d['name'], 'name_en'=>$d['name_en'], 'subs'=>[]];
    foreach ($rows as $d) {
        $pid = (int)$d['parent_id'];
        if ($pid && isset($tree[$pid])) $tree[$pid]['subs'][] = ['id'=>(int)$d['id'], 'name'=>$d['name'], 'name_en'=>$d['name_en']];
    }
    uasort($tree, function($a,$b){ return strcmp($a['name'],$b['name']); });
    foreach ($tree as &$m) usort($m['subs'], function($a,$b){ return strcmp($a['name'],$b['name']); });
    return array_values($tree);
}
function loc_node_path($id): array {
    global $pdo;
    $st = $pdo->prepare("SELECT id, parent_id, location_type, room_code, node_code FROM item_locations WHERE id = ?");
    $path = []; $cur = $id; $guard = 0;
    while ($cur && $guard < 10) {
        $st->execute([$cur]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) break;
        array_unshift($path, $n);
        $cur = $n['parent_id'] ? (int)$n['parent_id'] : 0;
        $guard++;
    }
    return $path;
}
function loc_node_fallback($n): string {
    if ($n['location_type'] === 'building') return 'B' . $n['id'];
    if ($n['location_type'] === 'floor')   return 'F' . $n['id'];
    return !empty($n['room_code']) ? $n['room_code'] : 'R' . $n['id'];
}
function loc_build_code($id): string {
    $parts = [];
    foreach (loc_node_path($id) as $n) {
        $c = trim((string)($n['node_code'] ?? ''));
        if ($c === '') $c = loc_node_fallback($n);
        $parts[] = $c;
    }
    return implode('-', $parts);
}
function loc_refresh_subtree($id): int {
    global $pdo;
    $queue = [$id]; $all = [];
    $st = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id = ?");
    while ($queue) {
        $cur = array_shift($queue); $all[] = $cur;
        $st->execute([$cur]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ch) $queue[] = (int)$ch;
    }
    $upd = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
    foreach ($all as $nid) $upd->execute([loc_build_code((int)$nid), (int)$nid]);
    return count($all);
}
function loc_rebuild_all(): int {
    global $pdo;
    $ids = $pdo->query("SELECT id FROM item_locations WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
    foreach ($ids as $id) $upd->execute([loc_build_code((int)$id), (int)$id]);
    return count($ids);
}

/* ═══ توليد QR (حمولة ثابتة بالمعرّف + كود وفق النهج) ═══ */
function location_qr_generate($id) {
    global $pdo;
    $id = (int)$id;
    $code = loc_build_code($id);
    $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?")->execute([$code, $id]);
    $dir = BASE_PATH . '/uploads/loc_qr';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . $id . '.png';
    $lib = BASE_PATH . '/includes/phpqrcode/qrlib.php';
    if (!class_exists('QRcode') && file_exists($lib)) require_once $lib;
    if (class_exists('QRcode')) {
        QRcode::png(BASE_URL . '/inventory/room.php?id=' . $id, $file, QR_ECC_M, 6, 2);
    }
    if (!file_exists($file)) return null;
    $rel = 'uploads/loc_qr/' . $id . '.png';
    $pdo->prepare("UPDATE item_locations SET qr_path = ? WHERE id = ?")->execute([$rel, $id]);
    return $rel;
}

/* ═══ معالجة POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $ids = array_map('intval', (array)(isset($_POST['ids']) ? $_POST['ids'] : array()));
    $back = isset($_POST['tab']) ? $_POST['tab'] : 'tree';
if (in_array($action, ['code_apply','code_manual'], true)) {
    require_once dirname(__FILE__) . '/_sections/coding_admin.php';
    exit;
}
    if ($action === 'add') {
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $name_en = trim(isset($_POST['name_en']) ? $_POST['name_en'] : '');
        $node = trim(isset($_POST['node_code']) ? $_POST['node_code'] : '');
        $type = isset($_POST['location_type']) ? $_POST['location_type'] : 'room';
        $parent = (int)(isset($_POST['parent_id']) ? $_POST['parent_id'] : 0);
        $parent = $parent > 0 ? $parent : null;
        if ($name === '') { flash('danger', 'الاسم مطلوب'); }
        else {
            $pdo->prepare("INSERT INTO item_locations (name, name_en, node_code, location_type, parent_id, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$name, $name_en !== '' ? $name_en : null, $node !== '' ? $node : null, $type, $parent]);
            $new = (int)$pdo->lastInsertId();
            loc_refresh_subtree($new);
            location_qr_generate($new);
            flash('success', 'تمت الإضافة وتوليد الرمز');
        }
    }
    elseif ($action === 'edit') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id) {
            $dept = (int)(isset($_POST['dept_id']) ? $_POST['dept_id'] : 0);
            $cust = (int)(isset($_POST['custodian_user_id']) ? $_POST['custodian_user_id'] : 0);
            $node = trim(isset($_POST['node_code']) ? $_POST['node_code'] : '');
            $pdo->prepare("UPDATE item_locations SET name=?, name_en=?, node_code=?, room_code=?, room_subtitle=?, dept_id=?, custodian_user_id=? WHERE id=?")
                ->execute([
                    trim($_POST['name']), trim($_POST['name_en']), $node !== '' ? $node : null,
                    trim($_POST['room_code']), trim($_POST['room_subtitle']),
                    $dept > 0 ? $dept : null, $cust > 0 ? $cust : null, $id
                ]);
            loc_refresh_subtree($id);
            flash('success', 'تم الحفظ وإعادة بناء الأكواد الفرعية');
        }
    }
    elseif ($action === 'delete') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM item_locations WHERE parent_id = ?"); $c->execute([$id]);
            $a = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id = ?"); $a->execute([$id]);
            if ((int)$c->fetchColumn() > 0) flash('danger', 'لا يمكن الحذف: يوجد مواقع فرعية');
            elseif ((int)$a->fetchColumn() > 0) flash('danger', 'لا يمكن الحذف: توجد أصول مرتبطة');
            else { $pdo->prepare("DELETE FROM item_locations WHERE id = ?")->execute([$id]); flash('success', 'تم الحذف'); }
        }
    }
    elseif ($action === 'confirm') {
        if ($ids) { location_set_room_dept($ids[0], (int)(isset($_POST['dept_id'])?$_POST['dept_id']:0) ?: null, 'verified'); flash('success', 'تم تأكيد القسم'); }
        $back = 'parser';
    }
    elseif ($action === 'bulk_dept') {
        $dept = (int)(isset($_POST['dept_id']) ? $_POST['dept_id'] : 0);
        foreach ($ids as $id) location_set_room_dept($id, $dept > 0 ? $dept : null, 'verified');
        flash('success', 'تم تعيين القسم لـ ' . count($ids) . ' غرفة');
    }
    elseif ($action === 'bulk_parse') {
        $r = location_bulk_parse_all(true);
        flash('success', "المعالجة: تلقائي {$r['auto']} / منخفض {$r['low_confidence']} / بدون {$r['failed']}");
        $back = 'parser';
    }
    elseif ($action === 'dry_run') {
        $r = location_bulk_parse_all(false);
        flash('success', "تجريبي: تلقائي {$r['auto']} / منخفض {$r['low_confidence']} / بدون {$r['failed']}");
        $back = 'parser';
    }
    elseif ($action === 'autofill_codes') {
        $level = isset($_POST['level']) ? $_POST['level'] : 'rooms';
        if ($level === 'buildings') {
            $rows = $pdo->query("SELECT id FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $i = 0;
            foreach ($rows as $rid) {
                $code = chr(65 + ($i % 26)); if ($i >= 26) $code .= intdiv($i, 26);
                $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?")->execute([$code, $rid]);
                $i++;
            }
            flash('success', 'تم ترميز المباني: A, B, C...');
        } elseif ($level === 'floors') {
            $blds = $pdo->query("SELECT id FROM item_locations WHERE location_type='building' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($blds as $b) {
                $st = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id=? AND is_active=1 ORDER BY name"); $st->execute([$b]);
                $n = 1;
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
                    $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?")->execute([(string)$n, $f]); $n++;
                }
            }
            flash('success', 'تم ترقيم الطوابق داخل كل مبنى');
        } else {
            $pdo->exec("UPDATE item_locations SET node_code = room_code WHERE location_type='room' AND room_code IS NOT NULL AND room_code != ''");
            flash('success', 'تم نسخ room_code إلى رمز العقدة للغرف');
        }
        $cnt = loc_rebuild_all();
        flash('success', "أعيد بناء $cnt كوداً مجمعاً ✅");
    }
    elseif ($action === 'rebuild_codes') {
        $cnt = loc_rebuild_all();
        flash('success', "أعيد بناء $cnt كوداً مجمعاً ✅");
    }
    elseif ($action === 'gen_qr') {
        $done = 0;
        foreach ($ids as $id) { if (location_qr_generate($id)) $done++; }
        flash('success', 'تم توليد ' . $done . ' QR');
        header('Location: ' . BASE_URL . '/settings/locations.php?tab=qr&toprint=' . implode(',', $ids));
        exit;
    }
    header('Location: ' . BASE_URL . '/settings/locations.php?tab=' . urlencode($back));
    exit;
}

/* ═══ تحميل البيانات ═══ */
$tree = location_get_tree();
$stats = location_get_stats();
$depts = location_load_departments();
$dept_tree = loc_dept_tree();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$unv = $pdo->query("SELECT * FROM item_locations WHERE is_active=1 AND location_type='room' AND parse_status != 'verified' ORDER BY name LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
$label_map = array();
$lm = $pdo->query("SELECT id, name, name_en, location_code, qr_path FROM item_locations WHERE location_type='room' AND qr_path IS NOT NULL AND qr_path != ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($lm as $r) $label_map[$r['id']] = array('code'=>$r['location_code'], 'name'=>($r['name_en']!==null && $r['name_en']!=='' ? $r['name_en'] : $r['name']), 'qr'=>$r['qr_path']);
$toprint = isset($_GET['toprint']) ? array_filter(array_map('intval', explode(',', $_GET['toprint']))) : array();
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'tree';

$page_title = $rtl ? 'إدارة المواقع' : 'Locations';
$page_icon  = 'fa-map-location-dot';
$active_nav = 'settings.index';
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.wrap{max-width:1200px;margin:0 auto;padding:20px 20px 110px}
.hd{background:linear-gradient(135deg,#0f2545,#1a3a6b);color:#fff;border-radius:16px;padding:20px 24px;margin-bottom:16px}
.hd h1{margin:0 0 6px;font-size:20px}.hd p{margin:0;opacity:.8;font-size:12.5px}
.pbar{background:rgba(255,255,255,.2);border-radius:99px;height:10px;margin-top:12px;overflow:hidden}
.pbar i{display:block;height:100%;background:#4ade80;width:<?= $stats['completion'] ?>%}
.tabs{display:flex;gap:8px;margin-bottom:14px}
.tabs a{padding:9px 16px;border-radius:10px;background:#e2e8f0;color:#334155;text-decoration:none;font-weight:700;font-size:13px}
.tabs a.on{background:#2563eb;color:#fff}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-bottom:12px}
.btn{border:none;border-radius:8px;padding:7px 12px;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}
.btn-p{background:#2563eb;color:#fff}.btn-g{background:#10b981;color:#fff}.btn-s{background:#e2e8f0;color:#334155}.btn-r{background:#ef4444;color:#fff}
.btn-door{background:#7c3aed;color:#fff;text-decoration:none;display:inline-block}
.bld{border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden}
.bld>h4{margin:0;background:#f8fafc;padding:12px 16px;cursor:pointer;font-size:14px;display:flex;justify-content:space-between}
.flr{background:#f8fafc;margin:8px 12px;border-radius:10px;padding:8px 12px}
.flr>h5{margin:0 0 6px;font-size:12.5px;cursor:pointer}
.rm{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:7px 10px;margin:5px 0;font-size:12.5px;flex-wrap:wrap}
.badge{padding:2px 8px;border-radius:6px;font-size:10.5px;font-weight:700}
.b-ok{background:#dcfce7;color:#166534}.b-wait{background:#fef9c3;color:#854d0e}.b-auto{background:#dbeafe;color:#1e40af}
.mono{font-family:monospace;font-size:10.5px;background:#eef2ff;color:#3730a3;padding:2px 6px;border-radius:5px}
.body{display:none}.open>.body{display:block}
.sug{background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:4px 9px;margin:2px;font-size:11.5px;cursor:pointer}
.bar{position:sticky;bottom:80px;background:#0f172a;color:#fff;border-radius:12px;padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;z-index:40;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.bar select{padding:6px;border-radius:8px;border:none}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;align-items:center;justify-content:center}
.modal.on{display:flex}.modal .box{background:#fff;border-radius:16px;padding:20px;width:min(480px,92%)}
.f{margin-bottom:10px}.f label{display:block;font-size:11.5px;font-weight:700;margin-bottom:4px}
.f input,.f select{width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit}
.flash{padding:10px 14px;border-radius:10px;margin-bottom:12px;font-size:13px;font-weight:700}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px;text-align:center}
.stat b{display:block;font-size:20px}.stat span{font-size:11px;color:#64748b}
.print-only{display:none}
@media print{body *{visibility:hidden}#printArea,#printArea *{visibility:visible}#printArea{display:block;position:absolute;inset:0}
.labels{display:grid;grid-template-columns:1fr 1fr;gap:4mm}
.label{height:64mm;border:1.5px dashed #888;border-radius:4mm;padding:3mm;display:flex;gap:3mm;align-items:center;page-break-inside:avoid}
.label img{width:26mm;height:26mm}.label .t b{display:block;font-size:11pt}.label .t span{display:block;font-size:8.5pt;color:#333}}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="wrap">

<div class="hd">
<h1><i class="fa-solid fa-map-location-dot"></i> إدارة المواقع والتفكيك الذكي</h1>
<p>تم تفتيك <?= $stats['verified'] ?> / <?= $stats['total'] ?> غرفة (<?= $stats['completion'] ?>%)</p>
<div class="pbar"><i></i></div>
</div>

<?php foreach (get_flash() as $f): ?>
<div class="flash" style="background:<?= $f['type']==='success' ? '#dcfce7' : '#fee2e2' ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<div class="tabs">
<a href="?tab=tree" class="<?= $tab==='tree'?'on':'' ?>">🌳 الشجرة</a>
<a href="?tab=parser" class="<?= $tab==='parser'?'on':'' ?>">🧠 المعالج (<?= count($unv) ?>)</a>
<a href="?tab=scheme" class="<?= $tab==='scheme'?'on':'' ?>">🔢 الترميز</a>
<a href="?tab=qr" class="<?= $tab==='qr'?'on':'' ?>">🏷️ QR</a>
</div>

<?php if ($tab === 'tree'): ?>
<div class="card">
<button class="btn btn-p" onclick="openAdd()"><i class="fa-solid fa-plus"></i> إضافة موقع</button>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="tree">
<select name="level" style="padding:6px;border-radius:8px;border:1px solid #e2e8f0">
<option value="buildings">رموز المباني (A,B,C)</option>
<option value="floors">أرقام الطوابق (1,2,3)</option>
<option value="rooms">الغرف من room_code</option>
</select>
<button class="btn btn-g" name="action" value="autofill_codes">⚡ تعبئة ذكية</button>
</form>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="tree">
<button class="btn btn-s" name="action" value="rebuild_codes">🔄 إعادة بناء الأكواد</button>
</form>
</div>
<?php foreach ($tree as $B): ?>
<div class="bld">
<h4 onclick="this.parentNode.classList.toggle('open')">
<span>🏢 <?= e($B['name']) ?> <small style="color:#64748b"><?= e($B['name_en'] ?? '') ?></small>
<?php if (!empty($B['node_code'])): ?><span class="mono">◈ <?= e($B['node_code']) ?></span><?php endif; ?>
<span class="mono"><?= e($B['location_code'] ?? '') ?></span></span>
<span class="badge b-auto"><?= $B['asset_count'] ?> أصل</span>
</h4>
<div class="body">
<?php foreach ($B['children'] as $F): ?>
<div class="flr">
<h5 onclick="this.parentNode.classList.toggle('open')">🏗️ <?= e($F['name']) ?> <small style="color:#64748b"><?= e($F['name_en'] ?? '') ?></small>
<?php if (!empty($F['node_code'])): ?><span class="mono">◈ <?= e($F['node_code']) ?></span><?php endif; ?>
<span class="mono"><?= e($F['location_code'] ?? '') ?></span></h5>
<div class="body">
<?php foreach ($F['children'] as $R): ?>
<div class="rm">
<b><?= e($R['name']) ?></b> <small style="color:#64748b"><?= e($R['name_en'] ?? '') ?></small>
<?php if (!empty($R['node_code'])): ?><span class="mono">◈ <?= e($R['node_code']) ?></span><?php endif; ?>
<?php if (!empty($R['room_code'])): ?><span class="mono"><?= e($R['room_code']) ?></span><?php endif; ?>
<span style="color:#64748b"><?= e($R['dept_name_en'] ?? $R['dept_name'] ?? 'بدون قسم') ?></span>
<span class="badge <?= $R['parse_status']==='verified'?'b-ok':($R['parse_status']==='auto'?'b-auto':'b-wait') ?>"><?= e($R['parse_status']) ?></span>
<span style="margin-inline-start:auto;display:flex;gap:4px">
<a class="btn btn-door" href="<?= BASE_URL ?>/inventory/room.php?id=<?= $R['id'] ?>" title="بطاقة الغرفة"><i class="fa-solid fa-door-open"></i></a>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="tree"><input type="hidden" name="action" value="gen_qr"><input type="hidden" name="ids[]" value="<?= $R['id'] ?>"><button class="btn btn-s" title="QR"><i class="fa-solid fa-qrcode"></i></button></form>
<button class="btn btn-s" data-id="<?= $R['id'] ?>" data-name="<?= e($R['name']) ?>" data-nameen="<?= e($R['name_en'] ?? '') ?>" data-nodecode="<?= e($R['node_code'] ?? '') ?>" data-roomcode="<?= e($R['room_code'] ?? '') ?>" data-subtitle="<?= e($R['room_subtitle'] ?? '') ?>" data-dept="<?= $R['dept_id'] ?? 0 ?>" data-cust="<?= $R['custodian_user_id'] ?? 0 ?>" onclick="openEdit(this)"><i class="fa-solid fa-pen"></i></button>
<form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')"><?= csrf_input() ?><input type="hidden" name="tab" value="tree"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $R['id'] ?>"><button class="btn btn-r"><i class="fa-solid fa-trash"></i></button></form>
</span>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'parser'): ?>
<div class="stats">
<div class="stat"><b><?= $stats['total'] ?></b><span>إجمالي الغرف</span></div>
<div class="stat"><b style="color:#10b981"><?= $stats['verified'] ?></b><span>موثقة</span></div>
<div class="stat"><b style="color:#2563eb"><?= $stats['auto'] ?></b><span>تلقائي</span></div>
<div class="stat"><b style="color:#f59e0b"><?= $stats['pending'] ?></b><span>قيد الانتظار</span></div>
</div>
<div class="card">
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="parser"><button class="btn btn-s" name="action" value="dry_run">تجربة بدون حفظ</button></form>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="parser"><button class="btn btn-p" name="action" value="bulk_parse">تطبيق المعالجة</button></form>
</div>
<form id="bulkForm" method="POST"><?= csrf_input() ?><input type="hidden" name="tab" value="parser">
<?php foreach ($unv as $R): $sugs = location_suggest_for_room($R['name'], $R['name_en'] ?? '', 3); ?>
<div class="card">
<div class="rm" style="border:none;padding:0">
<input type="checkbox" form="bulkForm" name="ids[]" value="<?= $R['id'] ?>">
<b><?= e($R['name']) ?></b>
<span class="badge b-wait"><?= e($R['parse_status']) ?></span>
</div>
<div>
<?php foreach ($sugs as $s): ?>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="parser"><input type="hidden" name="action" value="confirm"><input type="hidden" name="ids[]" value="<?= $R['id'] ?>"><input type="hidden" name="dept_id" value="<?= $s['dept_id'] ?>">
<button class="sug"><?= e($s['name_ar'] ?? $s['name']) ?> <b><?= $s['score'] ?>%</b></button>
</form>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
<div class="bar">
<select id="mainDept" onchange="fillSubs()">
<option value="0">— الإدارة / القسم الرئيسي —</option>
<?php foreach ($dept_tree as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['name_en'] ?: $m['name']) ?></option><?php endforeach; ?>
</select>
<select id="subDept" name="dept_id">
<option value="0">— القسم الفرعي (فارغ = الرئيسي) —</option>
</select>
<button class="btn btn-g" name="action" value="bulk_dept" onclick="return prepareBulkDept(this.form)">تعيين القسم للمحدد</button>
</div>
</form>
<?php elseif ($tab === 'scheme'): ?>
<?php require_once dirname(__FILE__) . '/_sections/coding_admin.php'; ?>
<?php else: ?>
<div class="card">
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="tab" value="qr"><button class="btn btn-p" name="action" value="gen_qr" onclick="return collectIds(this.form)">توليد QR للمحدد</button></form>
<button class="btn btn-g" onclick="printLabels()"><i class="fa-solid fa-print"></i> طباعة الملصقات</button>
</div>
<div class="card">
<?php foreach ($label_map as $id => $L): ?>
<div class="rm">
<input type="checkbox" class="qrchk" value="<?= $id ?>">
<b><?= e($L['name']) ?></b>
<span class="mono"><?= e($L['code']) ?></span>
<span class="badge b-ok">QR ✓</span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</main>
</div>

<!-- مودال -->
<div class="modal" id="mEdit"><div class="box">
<h3 style="margin:0 0 12px">إضافة / تحرير موقع</h3>
<form method="POST"><?= csrf_input() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
<input type="hidden" name="action" id="m_action" value="add">
<input type="hidden" name="id" id="m_id" value="0">
<div class="f"><label>الاسم (إنجليزي) *</label><input name="name" id="m_name" required></div>
<div class="f"><label>الترجمة (عربي)</label><input name="name_en" id="m_nameen"></div>
<div class="f"><label>رمز العقدة (مبنى/طابق/غرفة)</label><input name="node_code" id="m_nodecode" placeholder="مثال: A أو 1 أو 105 — اختياري"></div>
<div class="f"><label>النوع</label>
<select name="location_type" id="m_type"><option value="building">مبنى</option><option value="floor">طابق</option><option value="room" selected>غرفة</option></select>
</div>
<div class="f"><label>الأب</label>
<select name="parent_id" id="m_parent"><option value="0">—</option>
<?php foreach ($tree as $B): ?>
<option value="<?= $B['id'] ?>">🏢 <?= e($B['name']) ?></option>
<?php foreach ($B['children'] as $F): ?><option value="<?= $F['id'] ?>">&nbsp;&nbsp;↳ <?= e($F['name']) ?></option><?php endforeach; ?>
<?php endforeach; ?>
</select>
</div>
<div class="f"><label>رقم الغرفة</label><input name="room_code" id="m_roomcode"></div>
<div class="f"><label>الوصف الفرعي</label><input name="room_subtitle" id="m_subtitle"></div>
<div class="f"><label>القسم</label>
<select name="dept_id" id="m_dept">
<option value="0">—</option>
<?php foreach ($dept_tree as $m): ?>
<optgroup label="<?= e($m['name']) ?>">
<option value="<?= $m['id'] ?>">‹رئيسي› <?= e($m['name_en'] ?: $m['name']) ?></option>
<?php foreach ($m['subs'] as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name_en'] ?: $s['name']) ?></option><?php endforeach; ?>
</optgroup>
<?php endforeach; ?>
</select>
</div>
<div class="f"><label>الأمين</label>
<select name="custodian_user_id" id="m_cust"><option value="0">—</option><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?></select>
</div>
<div style="display:flex;gap:8px;justify-content:flex-end">
<button type="button" class="btn btn-s" onclick="closeM()">إلغاء</button>
<button class="btn btn-g">حفظ</button>
</div>
</form>
</div></div>

<div id="printArea"><div class="labels" id="printLabels">
<?php foreach ($toprint as $pid): if (isset($label_map[$pid])): $L = $label_map[$pid]; ?>
<div class="label"><img src="<?= BASE_URL ?>/<?= e($L['qr']) ?>"><div class="t"><b><?= e($L['name']) ?></b><span class="mono"><?= e($L['code']) ?></span></div></div>
<?php endif; endforeach; ?>
</div></div>

<script>
var DEPT_TREE = <?= json_encode($dept_tree, JSON_UNESCAPED_UNICODE) ?>;
function fillSubs(){
  var m = +document.getElementById('mainDept').value;
  var sub = document.getElementById('subDept');
  sub.innerHTML = '<option value="0">— القسم الفرعي (فارغ = الرئيسي) —</option>';
  if(!m) return;
  var node = DEPT_TREE.find(function(x){return x.id===m;});
  if(!node) return;
  node.subs.forEach(function(s){
    var o = document.createElement('option');
    o.value = s.id; o.textContent = (s.name_en || s.name);
    sub.appendChild(o);
  });
}
function prepareBulkDept(form){
  var m = +document.getElementById('mainDept').value;
  var s = +document.getElementById('subDept').value;
  if(!m){ alert('اختر الإدارة أولاً'); return false; }
  if(!form.querySelectorAll('input[name="ids[]"]:checked').length){ alert('حدد غرفاً أولاً'); return false; }
  if(!s) document.getElementById('subDept').value = m;
  return true;
}
function closeM(){document.getElementById('mEdit').classList.remove('on');}
function openAdd(){
document.getElementById('m_action').value='add';document.getElementById('m_id').value=0;
['m_name','m_nameen','m_nodecode','m_roomcode','m_subtitle'].forEach(function(i){document.getElementById(i).value='';});
document.getElementById('mEdit').classList.add('on');
}
function openEdit(b){
document.getElementById('m_action').value='edit';document.getElementById('m_id').value=b.dataset.id;
document.getElementById('m_name').value=b.dataset.name;
document.getElementById('m_nameen').value=b.dataset.nameen;
document.getElementById('m_nodecode').value=b.dataset.nodecode||'';
document.getElementById('m_roomcode').value=b.dataset.roomcode;
document.getElementById('m_subtitle').value=b.dataset.subtitle;
document.getElementById('m_dept').value=b.dataset.dept;
document.getElementById('m_cust').value=b.dataset.cust;
document.getElementById('mEdit').classList.add('on');
}
function collectIds(form){
var ids=[];document.querySelectorAll('.qrchk:checked').forEach(function(c){ids.push(c.value);});
if(!ids.length){alert('اختر غرفاً أولاً');return false;}
ids.forEach(function(id){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;form.appendChild(i);});
return true;
}
function printLabels(){
var ids=[];document.querySelectorAll('.qrchk:checked').forEach(function(c){ids.push(c.value);});
var use=ids.length?ids:Object.keys(<?= json_encode($label_map) ?>);
var area=document.getElementById('printLabels');
var map=<?= json_encode($label_map) ?>;
area.innerHTML=use.map(function(id){var L=map[id];if(!L)return '';
return '<div class="label"><img src="<?= BASE_URL ?>/'+L.qr+'"><div class="t"><b>'+L.name+'</b><span class="mono">'+L.code+'</span></div></div>';}).join('');
window.print();
}
<?php if ($toprint): ?>window.addEventListener('load',function(){setTimeout(function(){window.print();},400);});<?php endif; ?>
</script>
</body>
</html>