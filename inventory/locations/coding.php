<?php
/**
 * inventory/locations/coding.php — التكويد الذكي للمواقع (v2)
 * • صفحة فارغة + 3 قوائم تصفية متتالية (مبنى ← طابق ← غرفة)
 * • 4 طرق تكويد: ذكي / يدوي / نمط تسلسلي / صيغة مخصصة
 * • أزرار: تطبيق + مسح التكويدات + حفظ يدوي
 */
require_once dirname(__DIR__, 2) . '/config.php';
if (file_exists(__DIR__ . '/_helpers.php')) require_once __DIR__ . '/_helpers.php';
page_guard('inventory.locations');
if (!(is_admin() || (function_exists('can') && can('inventory.locations', 'manage')))) abort(403);
$rtl = is_rtl();

/* ═══ معالجة POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    
    // ── تطبيق تكويد تلقائي/نمطي ──
    if ($action === 'apply_codes') {
        $method = $_POST['method'] ?? 'smart';
        $building_id = (int)($_POST['building_id'] ?? 0);
        $floor_id = (int)($_POST['floor_id'] ?? 0);
        $room_id = (int)($_POST['room_id'] ?? 0);
        $ids = array_map('intval', $_POST['ids'] ?? []);
        
        if (!$ids) {
            flash('error', $rtl ? 'لم يتم اختيار أي عنصر.' : 'No items selected.');
        } else {
            try {
                $pdo->beginTransaction();
                $updated = 0;
                
                if ($method === 'manual') {
                    // حفظ يدوي: كل ID له code خاص
                    foreach ($ids as $id) {
                        $code = trim($_POST['code_' . $id] ?? '');
                        if ($code !== '') {
                            $pdo->prepare("UPDATE item_locations SET location_code=? WHERE id=?")->execute([$code, $id]);
                            $updated++;
                        }
                    }
                } elseif ($method === 'pattern') {
                    // نمط تسلسلي: AC1, AC2, AC3...
                    $prefix = trim($_POST['prefix'] ?? '');
                    $start = (int)($_POST['start'] ?? 1);
                    $step = (int)($_POST['step'] ?? 1);
                    $pad = (int)($_POST['pad'] ?? 0);
                    $sep = $_POST['separator'] ?? '';
                    $counter = $start;
                    
                    foreach ($ids as $id) {
                        $num = $pad > 0 ? str_pad($counter, $pad, '0', STR_PAD_LEFT) : $counter;
                        $code = $prefix . $sep . $num;
                        $pdo->prepare("UPDATE item_locations SET location_code=? WHERE id=?")->execute([$code, $id]);
                        $counter += $step;
                        $updated++;
                    }
                } elseif ($method === 'formula') {
                    // صيغة مخصصة: [B]-[F]-[R]
                    $formula = $_POST['formula'] ?? '[B]-[F]-[R]';
                    $start = (int)($_POST['start'] ?? 1);
                    $step = (int)($_POST['step'] ?? 1);
                    $counter = $start;
                    
                    foreach ($ids as $id) {
                        $loc = $pdo->prepare("SELECT r.name rn, f.name fn, b.name bn FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
                        $loc->execute([$id]);
                        $row = $loc->fetch(PDO::FETCH_ASSOC);
                        if (!$row) continue;
                        
                        $num = str_pad($counter, 3, '0', STR_PAD_LEFT);
                        $code = str_replace(
                            ['[B]', '[F]', '[R]', '[N]'],
                            [$row['bn'] ?: '', $row['fn'] ?: '', $row['rn'] ?: '', $num],
                            $formula
                        );
                        $pdo->prepare("UPDATE item_locations SET location_code=? WHERE id=?")->execute([$code, $id]);
                        $counter += $step;
                        $updated++;
                    }
                } else {
                    // smart: اقتراح النظام (حرف أول المبنى + رقم الطابق + رقم تسلسلي)
                    $counter = 1;
                    foreach ($ids as $id) {
                        $loc = $pdo->prepare("SELECT r.name rn, f.name fn, b.name bn FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
                        $loc->execute([$id]);
                        $row = $loc->fetch(PDO::FETCH_ASSOC);
                        if (!$row) continue;
                        
                        $b_letter = mb_substr($row['bn'] ?: 'X', 0, 1);
                        $f_num = preg_replace('/\D/', '', $row['fn'] ?: '0') ?: '0';
                        $code = strtoupper($b_letter) . $f_num . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
                        $pdo->prepare("UPDATE item_locations SET location_code=? WHERE id=?")->execute([$code, $id]);
                        $counter++;
                        $updated++;
                    }
                }
                
                $pdo->commit();
                flash('success', ($rtl ? "تم تحديث $updated عنصر." : "Updated $updated items."));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $e->getMessage());
            }
        }
        $redir = '?building_id=' . $building_id . '&floor_id=' . $floor_id . '&room_id=' . $room_id;
        header('Location: ' . BASE_URL . '/inventory/locations/coding.php' . $redir); exit;
    }
    
    // ── مسح التكويدات ──
    if ($action === 'clear_codes') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("UPDATE item_locations SET location_code=NULL WHERE id IN ($ph)");
            $st->execute($ids);
            flash('success', ($rtl ? 'تم مسح التكويدات من ' . count($ids) . ' عنصر.' : 'Cleared codes from ' . count($ids) . ' items.'));
        }
        header('Location: ' . BASE_URL . '/inventory/locations/coding.php'); exit;
    }
}

/* ═══ قوائم التصفية ═══ */
$buildings = $pdo->query("SELECT id, name, name_en, location_code FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$sel_b = (int)($_GET['building_id'] ?? 0);
$sel_f = (int)($_GET['floor_id'] ?? 0);
$sel_r = (int)($_GET['room_id'] ?? 0);

$floors = [];
if ($sel_b) {
    $st = $pdo->prepare("SELECT id, name, name_en, location_code FROM item_locations WHERE location_type='floor' AND is_active=1 AND parent_id=? ORDER BY name");
    $st->execute([$sel_b]);
    $floors = $st->fetchAll(PDO::FETCH_ASSOC);
}

$rooms = [];
if ($sel_f) {
    $st = $pdo->prepare("SELECT id, name, name_en, location_code, dept_id FROM item_locations WHERE location_type='room' AND is_active=1 AND parent_id=? ORDER BY name");
    $st->execute([$sel_f]);
    $rooms = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── النتائج (تظهر فقط عند وجود فلترة) ──
$results = [];
$result_type = '';
if ($sel_r) {
    // غرفة محددة
    $st = $pdo->prepare("SELECT r.*, f.name f_name, b.name b_name, d.name dept_name FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id LEFT JOIN departments d ON d.id=r.dept_id WHERE r.id=?");
    $st->execute([$sel_r]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    $result_type = 'room';
} elseif ($sel_f) {
    // طابق وكل غرفه
    $st = $pdo->prepare("SELECT r.*, f.name f_name, b.name b_name, d.name dept_name FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id LEFT JOIN departments d ON d.id=r.dept_id WHERE r.location_type='room' AND r.is_active=1 AND r.parent_id=? ORDER BY r.name");
    $st->execute([$sel_f]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    $result_type = 'floor';
} elseif ($sel_b) {
    // مبنى وكل طوابقه
    $st = $pdo->prepare("SELECT f.*, b.name b_name FROM item_locations f LEFT JOIN item_locations b ON b.id=f.parent_id WHERE f.location_type='floor' AND f.is_active=1 AND f.parent_id=? ORDER BY f.name");
    $st->execute([$sel_b]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    $result_type = 'building';
}

/* ═══ KPIs ═══ */
$kTotal = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE is_active=1")->fetchColumn();
$kCoded = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE is_active=1 AND location_code IS NOT NULL AND location_code!=''")->fetchColumn();
$kPct = $kTotal ? round($kCoded / $kTotal * 100) : 0;
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl ? 'التكويد الذكي' : 'Smart Coding' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,input,select,textarea{font-family:'Tajawal',sans-serif}
.cd-wrap{max-width:1400px;margin:0 auto;padding:18px}
.cd-hero{background:linear-gradient(135deg,#7c3aed,#a855f7 55%,#c084fc);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;box-shadow:0 12px 32px rgba(124,58,237,.25);display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.cd-hero .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.cd-hero h1{margin:0;font-size:24px;font-weight:900}
.cd-hero p{margin:4px 0 0;font-size:13px;opacity:.9}
.cd-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
@media(max-width:920px){.cd-stats{grid-template-columns:1fr}}
.cd-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.cd-stat .ic{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.cd-stat .v{font-size:22px;font-weight:900;line-height:1}.cd-stat .l{font-size:12px;color:#64748b;margin-top:4px;font-weight:700}
.cd-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:20px;margin-bottom:16px}
.cd-card h3{margin:0 0 16px;font-size:15px;font-weight:900;display:flex;gap:9px;align-items:center}
.cd-card h3 i{color:#7c3aed;background:#f5f3ff;padding:8px;border-radius:9px;font-size:13px}
.cd-step{display:flex;gap:10px;align-items:center;margin-bottom:10px;font-size:12px;font-weight:800;color:#7c3aed}
.cd-step .n{width:28px;height:28px;border-radius:50%;background:#7c3aed;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px}
.cd-filters{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
@media(max-width:900px){.cd-filters{grid-template-columns:1fr}}
.cd-filters select{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px;font-size:13px;background:#fff}
.cd-methods{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
@media(max-width:900px){.cd-methods{grid-template-columns:repeat(2,1fr)}}
.cd-meth{border:1.5px solid #e2e8f0;border-radius:12px;padding:14px;cursor:pointer;transition:.2s;background:#fff}
.cd-meth:hover{border-color:#c084fc}
.cd-meth.sel{border-color:#7c3aed;background:#f5f3ff;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.cd-meth .ic{font-size:20px;color:#7c3aed;margin-bottom:6px}
.cd-meth b{display:block;font-size:13px;font-weight:800;margin-bottom:2px}
.cd-meth small{font-size:11px;color:#64748b;line-height:1.4}
.cd-config{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:16px;display:none}
.cd-config.on{display:block}
.cd-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px}
@media(max-width:700px){.cd-row{grid-template-columns:repeat(2,1fr)}}
.cd-row label{font-size:11px;font-weight:800;color:#475569;margin-bottom:4px;display:block}
.cd-row input,.cd-row select{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:12.5px}
table.cd{width:100%;border-collapse:collapse;font-size:12.5px}
table.cd th{background:#f8fafc;padding:10px 12px;text-align:right;font-size:11px;font-weight:900;color:#475569;border-bottom:1.5px solid #e2e8f0}
table.cd td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.cd tr:hover td{background:#faf5ff}
table.cd .code{font-family:'Inter',monospace;font-size:12px;background:#f5f3ff;color:#7c3aed;padding:3px 10px;border-radius:6px;font-weight:700}
table.cd .code.empty{background:#fef3c7;color:#92400e}
table.cd input[type=text]{width:100%;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:12px;font-family:'Inter',monospace}
.cd-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.cd-btn{border:none;border-radius:11px;padding:12px 22px;font-weight:900;font-size:13px;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.cd-btn.go{background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;box-shadow:0 4px 14px rgba(124,58,237,.3)}
.cd-btn.clr{background:#fee2e2;color:#b91c1c;border:1.5px solid #fecaca}
.cd-btn.hub{background:#f1f5f9;color:#475569}
.cd-empty{text-align:center;padding:60px 20px;color:#94a3b8;background:#fff;border:1.5px dashed #cbd5e1;border-radius:18px}
.cd-empty i{font-size:42px;display:block;margin-bottom:10px;color:#cbd5e1}
.flash{background:#fff;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-weight:800;font-size:13px;border-right:4px solid #16a34a;color:#065f46}
.flash.err{border-right-color:#dc2626;color:#991b1b}
.cd-hint{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;font-size:12px;color:#1e40af;margin-top:10px;font-weight:700}
.cd-hint code{background:#dbeafe;padding:2px 6px;border-radius:4px;font-family:'Inter',monospace}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="cd-wrap">
<?php foreach (get_flash() as $fm): ?><div class="flash <?= $fm['type']==='error'?'err':'' ?>"><?= e($fm['message']) ?></div><?php endforeach; ?>

<section class="cd-hero">
<div class="ic"><i class="fa-solid fa-barcode"></i></div>
<div style="flex:1;min-width:220px"><h1><?= $rtl ? 'التكويد الذكي للمواقع' : 'Smart Location Coding' ?></h1>
<p><?= $rtl ? 'فلترة دقيقة + 4 طرق تكويد مرنة (ذكي/يدوي/نمط/صيغة)' : 'Precise filtering + 4 flexible coding methods' ?></p></div>
<a class="cd-btn hub" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl ? 'الداشبورد' : 'Hub' ?></a>
</section>

<div class="cd-stats">
<div class="cd-stat"><div class="ic" style="background:#ede9fe;color:#7c3aed"><i class="fa-solid fa-layer-group"></i></div><div><div class="v"><?= number_format($kTotal) ?></div><div class="l"><?= $rtl ? 'إجمالي المواقع' : 'Total locations' ?></div></div></div>
<div class="cd-stat"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-barcode"></i></div><div><div class="v"><?= number_format($kCoded) ?></div><div class="l"><?= $rtl ? 'مواقع مكوّدة' : 'Coded' ?></div></div></div>
<div class="cd-stat"><div class="ic" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-percent"></i></div><div><div class="v"><?= $kPct ?>%</div><div class="l"><?= $rtl ? 'نسبة التكويد' : 'Coverage' ?></div></div></div>
</div>

<!-- ═══ الخطوة 1: الفلترة ═══ -->
<div class="cd-card">
<h3><i class="fa-solid fa-filter"></i> <?= $rtl ? 'الخطوة 1: اختر النطاق' : 'Step 1: Select Scope' ?></h3>
<form method="GET" class="cd-filters">
<select name="building_id" onchange="this.form.submit()">
<option value=""><?= $rtl ? '— اختر المبنى —' : '— Select Building —' ?></option>
<?php foreach ($buildings as $b): ?>
<option value="<?= $b['id'] ?>" <?= $sel_b==$b['id']?'selected':'' ?>><?= e($rtl ? $b['name'] : ($b['name_en'] ?: $b['name'])) ?><?= $b['location_code'] ? ' [' . e($b['location_code']) . ']' : '' ?></option>
<?php endforeach; ?>
</select>
<select name="floor_id" onchange="this.form.submit()" <?= $sel_b?'':'disabled' ?>>
<option value=""><?= $rtl ? '— اختر الطابق —' : '— Select Floor —' ?></option>
<?php foreach ($floors as $f): ?>
<option value="<?= $f['id'] ?>" <?= $sel_f==$f['id']?'selected':'' ?>><?= e($rtl ? $f['name'] : ($f['name_en'] ?: $f['name'])) ?><?= $f['location_code'] ? ' [' . e($f['location_code']) . ']' : '' ?></option>
<?php endforeach; ?>
</select>
<select name="room_id" onchange="this.form.submit()" <?= $sel_f?'':'disabled' ?>>
<option value=""><?= $rtl ? '— كل غرف الطابق —' : '— All rooms in floor —' ?></option>
<?php foreach ($rooms as $r): ?>
<option value="<?= $r['id'] ?>" <?= $sel_r==$r['id']?'selected':'' ?>><?= e($rtl ? $r['name'] : ($r['name_en'] ?: $r['name'])) ?><?= $r['location_code'] ? ' [' . e($r['location_code']) . ']' : '' ?></option>
<?php endforeach; ?>
</select>
</form>
</div>

<?php if (!$results): ?>
<div class="cd-empty"><i class="fa-solid fa-filter-circle-xmark"></i><h3><?= $rtl ? 'اختر مبنى أو طابقاً أو غرفة لعرض النتائج' : 'Select a building, floor, or room to view results' ?></h3></div>
<?php else: ?>

<!-- ═══ الخطوة 2: طريقة التكويد ═══ -->
<div class="cd-card">
<h3><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl ? 'الخطوة 2: اختر طريقة التكويد' : 'Step 2: Choose Coding Method' ?></h3>
<div class="cd-methods">
<div class="cd-meth sel" onclick="pickMethod('smart')"><div class="ic"><i class="fa-solid fa-wand-magic-sparkles"></i></div><b><?= $rtl ? 'ذكي (تلقائي)' : 'Smart Auto' ?></b><small><?= $rtl ? 'النظام يقترح أكواداً بناءً على اسم المبنى والطابق' : 'System suggests codes based on building/floor names' ?></small></div>
<div class="cd-meth" onclick="pickMethod('manual')"><div class="ic"><i class="fa-solid fa-pen-to-square"></i></div><b><?= $rtl ? 'إدخال يدوي' : 'Manual Input' ?></b><small><?= $rtl ? 'اكتب الكود يدوياً لكل عنصر' : 'Type code for each item manually' ?></small></div>
<div class="cd-meth" onclick="pickMethod('pattern')"><div class="ic"><i class="fa-solid fa-list-ol"></i></div><b><?= $rtl ? 'نمط تسلسلي' : 'Pattern Sequence' ?></b><small><?= $rtl ? 'AC1, AC2, AC3... أو A-001, A-002' : 'AC1, AC2, AC3... or A-001, A-002' ?></small></div>
<div class="cd-meth" onclick="pickMethod('formula')"><div class="ic"><i class="fa-solid fa-code"></i></div><b><?= $rtl ? 'صيغة مخصصة' : 'Custom Formula' ?></b><small><?= $rtl ? 'مثال: [B]-[F]-[R] أو [B][F]/[N]' : 'e.g., [B]-[F]-[R] or [B][F]/[N]' ?></small></div>
</div>

<!-- ═══ إعدادات النمط/الصيغة ═══ -->
<div class="cd-config" id="cfg-smart">
<div class="cd-hint"><i class="fa-solid fa-circle-info"></i> <?= $rtl ? 'سيقوم النظام ببناء كود تلقائي من: حرف أول المبنى + رقم الطابق + رقم تسلسلي (مثال: م1-001)' : 'System builds code from: first letter of building + floor number + sequential (e.g., M1-001)' ?></div>
</div>

<div class="cd-config" id="cfg-pattern">
<div class="cd-row">
<div><label><?= $rtl ? 'البادئة (Prefix)' : 'Prefix' ?></label><input type="text" name="prefix" value="AC" placeholder="AC"></div>
<div><label><?= $rtl ? 'الفاصل' : 'Separator' ?></label><select name="separator"><option value="">—</option><option value="-" selected>-</option><option value="/">/</option><option value="_">_</option></select></div>
<div><label><?= $rtl ? 'البدء من' : 'Start From' ?></label><input type="number" name="start" value="1" min="0"></div>
<div><label><?= $rtl ? 'الخطوة' : 'Step' ?></label><input type="number" name="step" value="1" min="1"></div>
</div>
<div class="cd-row">
<div><label><?= $rtl ? 'حشو الأصفار' : 'Zero Padding' ?></label><select name="pad"><option value="0"><?= $rtl ? 'بدون' : 'None' ?></option><option value="2">01, 02, 03</option><option value="3" selected>001, 002, 003</option><option value="4">0001, 0002</option></select></div>
</div>
<div class="cd-hint"><i class="fa-solid fa-lightbulb"></i> <?= $rtl ? 'مثال النتيجة:' : 'Example:' ?> <code>AC-001</code>, <code>AC-002</code>, <code>AC-003</code></div>
</div>

<div class="cd-config" id="cfg-formula">
<div class="cd-row">
<div style="grid-column:1/-1"><label><?= $rtl ? 'الصيغة (Formula)' : 'Formula' ?></label><input type="text" name="formula" value="[B]-[F]-[R]" placeholder="[B]-[F]-[R]"></div>
</div>
<div class="cd-row">
<div><label><?= $rtl ? 'البدء من' : 'Start From' ?></label><input type="number" name="start" value="1" min="0"></div>
<div><label><?= $rtl ? 'الخطوة' : 'Step' ?></label><input type="number" name="step" value="1" min="1"></div>
</div>
<div class="cd-hint"><i class="fa-solid fa-lightbulb"></i> <?= $rtl ? 'المتغيرات المتاحة:' : 'Available variables:' ?> <code>[B]</code> <?= $rtl ? 'اسم المبنى' : 'Building' ?> · <code>[F]</code> <?= $rtl ? 'اسم الطابق' : 'Floor' ?> · <code>[R]</code> <?= $rtl ? 'اسم الغرفة' : 'Room' ?> · <code>[N]</code> <?= $rtl ? 'رقم تسلسلي' : 'Sequential number' ?></div>
</div>

</div>

<!-- ═══ الخطوة 3: النتائج ═══ -->
<form method="POST" id="codingForm">
<?= csrf_input() ?>
<input type="hidden" name="building_id" value="<?= $sel_b ?>">
<input type="hidden" name="floor_id" value="<?= $sel_f ?>">
<input type="hidden" name="room_id" value="<?= $sel_r ?>">
<input type="hidden" name="action" id="formAction" value="apply_codes">
<input type="hidden" name="method" id="formMethod" value="smart">

<div class="cd-card">
<h3><i class="fa-solid fa-table-list"></i> <?= $rtl ? 'الخطوة 3: النتائج' : 'Step 3: Results' ?> <span style="font-size:11px;color:#94a3b8;font-weight:600">(<?= count($results) ?> <?= $rtl ? 'عنصر' : 'items' ?>)</span></h3>
<div style="overflow-x:auto">
<table class="cd">
<thead><tr>
<th style="width:30px"><input type="checkbox" id="chkAll" checked></th>
<th><?= $rtl ? 'الاسم' : 'Name' ?></th>
<th><?= $rtl ? 'المسار' : 'Path' ?></th>
<th><?= $rtl ? 'الكود الحالي' : 'Current Code' ?></th>
<th id="colManual" style="display:none"><?= $rtl ? 'الكود الجديد (يدوي)' : 'New Code (manual)' ?></th>
</tr></thead>
<tbody>
<?php foreach ($results as $r): 
$path = trim(($r['b_name'] ?? '') . ' / ' . ($r['f_name'] ?? ''), ' /');
?>
<tr>
<td><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" checked class="item-chk"></td>
<td><b><?= e($rtl ? $r['name'] : ($r['name_en'] ?: $r['name'])) ?></b></td>
<td style="color:#64748b;font-size:11.5px"><?= e($path) ?></td>
<td><?= $r['location_code'] ? '<span class="code">'.e($r['location_code']).'</span>' : '<span class="code empty">'.($rtl?'بدون':'Empty').'</span>' ?></td>
<td class="td-manual" style="display:none"><input type="text" name="code_<?= $r['id'] ?>" placeholder="<?= $rtl ? 'اكتب الكود' : 'Enter code' ?>"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="cd-actions">
<input type="hidden" name="prefix" id="inp_prefix" value="AC">
<input type="hidden" name="separator" id="inp_separator" value="-">
<input type="hidden" name="start" id="inp_start" value="1">
<input type="hidden" name="step" id="inp_step" value="1">
<input type="hidden" name="pad" id="inp_pad" value="3">
<input type="hidden" name="formula" id="inp_formula" value="[B]-[F]-[R]">

<button type="submit" class="cd-btn go" onclick="document.getElementById('formAction').value='apply_codes'"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl ? 'تطبيق التكويد' : 'Apply Coding' ?></button>
<button type="submit" class="cd-btn clr" onclick="return confirm('<?= $rtl ? 'مسح التكويدات من كل العناصر المحددة؟' : 'Clear codes from all selected items?' ?>') && (document.getElementById('formAction').value='clear_codes', true)"><i class="fa-solid fa-eraser"></i> <?= $rtl ? 'مسح التكويدات' : 'Clear Codes' ?></button>
</div>
</div>
</form>
<?php endif; ?>

</div></main>
</div>
<script>
let curMethod = 'smart';
function pickMethod(m) {
    curMethod = m;
    document.querySelectorAll('.cd-meth').forEach(el => el.classList.remove('sel'));
    event.currentTarget.classList.add('sel');
    document.querySelectorAll('.cd-config').forEach(el => el.classList.remove('on'));
    document.getElementById('cfg-' + m)?.classList.add('on');
    document.getElementById('formMethod').value = m;
    
    // إظهار/إخفاء عمود الإدخال اليدوي
    const manualCols = document.querySelectorAll('.td-manual');
    const manualHead = document.getElementById('colManual');
    if (m === 'manual') {
        manualCols.forEach(c => c.style.display = '');
        manualHead.style.display = '';
    } else {
        manualCols.forEach(c => c.style.display = 'none');
        manualHead.style.display = 'none';
    }
    
    // نسخ القيم من الحقول المرئية إلى hidden inputs
    syncConfig();
}

function syncConfig() {
    const cfg = document.getElementById('cfg-' + curMethod);
    if (!cfg) return;
    cfg.querySelectorAll('input, select').forEach(el => {
        const target = document.getElementById('inp_' + el.name);
        if (target) target.value = el.value;
    });
}

// نسخ القيم عند تغيير أي حقل في الإعدادات
document.querySelectorAll('.cd-config input, .cd-config select').forEach(el => {
    el.addEventListener('change', syncConfig);
    el.addEventListener('input', syncConfig);
});

// تحديد الكل / إلغاء الكل
document.getElementById('chkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.item-chk').forEach(c => c.checked = this.checked);
});

// عند إرسال النموذج: تأكد من مزامنة الإعدادات
document.getElementById('codingForm')?.addEventListener('submit', syncConfig);

// تفعيل الطريقة الافتراضية
pickMethod('smart');
</script>
</body>
</html>