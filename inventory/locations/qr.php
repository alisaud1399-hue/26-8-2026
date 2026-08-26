<?php
/**
 * inventory/locations/qr.php — ملصقات QR للغرف + لوحة إعدادات كاملة
 * الاتجاه/اللغة/الشعار/الإطار/الأعمدة/الحجوم — كلها قابلة للتحكم وتُحفظ بالنظام
 */
require_once dirname(__DIR__, 2) . '/config.php';
if (file_exists(__DIR__ . '/_helpers.php')) require_once __DIR__ . '/_helpers.php';
page_guard('inventory.index');
if (!(is_admin() || (function_exists('can') && can('inventory.locations', 'manage')))) abort(403);
$rtl = is_rtl();

/* ── helper: حفظ إعداد إن غابت الدالة ── */
if (!function_exists('set_setting')) {
    function set_setting($k, $v) {
        global $pdo;
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k, $v]);
    }
}

/* ═══ إعدادات الملصق (افتراضيات + محفوظ) ═══ */
$QL_DEF = [
    'orientation'  => 'landscape',   // landscape | portrait
    'lang'         => 'both',        // ar | en | both
    'cols'         => '3',           // 2 | 3 | 4
    'qr_size'      => '84',          // px
    'logo_show'    => '1',
    'logo_pos'     => 'top',         // top | right | left
    'logo_size'    => '26',          // px
    'logo_path'    => 'assets/img/cluster_logo.png',
    'border'       => '1',
    'border_color' => '#94a3b8',
    'border_width' => '1',
    'border_style' => 'dashed',      // solid | dashed | dotted
    'show_code'    => '1',
    'show_path'    => '1',
];
$QL = [];
foreach ($QL_DEF as $k => $dv) $QL[$k] = get_setting('qr_' . $k, $dv);

/* ═══ حفظ الإعدادات ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'reset_settings') {
        foreach ($QL_DEF as $k => $dv) set_setting('qr_' . $k, $dv);
        flash('success', 'تمت استعادة الإعدادات الافتراضية.');
        header('Location: ' . BASE_URL . '/inventory/locations/qr.php'); exit;
    }
    if ($act === 'save_settings') {
        foreach ($QL_DEF as $k => $dv) {
            if ($k === 'logo_path') continue;
            if (isset($_POST['ql_' . $k])) set_setting('qr_' . $k, trim($_POST['ql_' . $k]));
        }
        if (!empty($_FILES['ql_logo']['name']) && $_FILES['ql_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['ql_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','svg','webp'], true)) {
                $dir = BASE_PATH . '/assets/img';
                if (!is_dir($dir)) @mkdir($dir, 0777, true);
                $file = 'qr_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['ql_logo']['tmp_name'], $dir . '/' . $file)) {
                    set_setting('qr_logo_path', 'assets/img/' . $file);
                }
            }
        }
        flash('success', 'تم حفظ إعدادات الملصقات.');
        header('Location: ' . BASE_URL . '/inventory/locations/qr.php'); exit;
    }
}
/* إعادة قراءة القيم بعد الحفظ */
foreach ($QL_DEF as $k => $dv) $QL[$k] = get_setting('qr_' . $k, $dv);

$logo_ok  = file_exists(BASE_PATH . '/' . $QL['logo_path']);
$logo_url = BASE_URL . '/' . $QL['logo_path'];
$border_css = $QL['border'] === '1' ? ((int)$QL['border_width'] . 'px ' . $QL['border_style'] . ' ' . $QL['border_color']) : 'none';
$portrait = $QL['orientation'] === 'portrait';

/* ═══ فلترة + بيانات ═══ */
$b = (int)($_GET['b'] ?? 0);
$buildings = $pdo->query("SELECT id, name, name_en FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sql = "SELECT r.id, r.name, r.name_en, r.location_code,
        f.name AS floor_name, f.name_en AS floor_en,
        b.name AS building_name, b.name_en AS building_en
        FROM item_locations r
        JOIN item_locations f ON f.id = r.parent_id
        JOIN item_locations b ON b.id = f.parent_id
        WHERE r.location_type='room' AND r.is_active=1";
$params = [];
if ($b) { $sql .= " AND b.id=?"; $params[] = $b; }
$sql .= " ORDER BY b.name, f.name, r.name";
$st = $pdo->prepare($sql); $st->execute($params);
$rooms = $st->fetchAll(PDO::FETCH_ASSOC);
$kCoded = 0; foreach ($rooms as $r) if (!empty($r['location_code'])) $kCoded++;
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl ? 'ملصقات QR' : 'QR Labels' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,select,input{font-family:'Tajawal',sans-serif}
.qr-wrap{max-width:1280px;margin:0 auto;padding:18px}
.qr-hero{background:linear-gradient(135deg,#b45309,#f59e0b 60%,#fbbf24);color:#fff;border-radius:22px;padding:22px 26px;margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.qr-hero .ic{width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
.qr-hero h1{margin:0;font-size:22px;font-weight:900}
.qr-hero p{margin:4px 0 0;font-size:12.5px;opacity:.92}
.qr-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.qr-tools select{border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 14px;font-size:13px;background:#fff}
.qr-btn{border:none;border-radius:10px;padding:11px 20px;font-weight:900;font-size:13px;cursor:pointer;display:inline-flex;gap:7px;align-items:center}
.qr-btn.amber{background:#f59e0b;color:#fff}
.qr-btn.slate{background:#f1f5f9;color:#475569}
/* ═══ لوحة الإعدادات ═══ */
.qr-setcard{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;margin-bottom:16px;overflow:hidden}
.qr-setcard summary{cursor:pointer;padding:14px 18px;font-weight:900;font-size:14px;background:#f8fafc;display:flex;gap:9px;align-items:center}
.qr-setcard summary i{color:#f59e0b}
.qr-setform{padding:16px 18px}
.sf-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:1000px){.sf-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.sf-grid{grid-template-columns:1fr}}
.sf label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:5px}
.sf select,.sf input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 10px;font-size:12.5px;background:#fff}
.sf input[type=color]{padding:3px;height:38px}
.sf-actions{display:flex;gap:10px;margin-top:14px}
/* ═══ شبكة الملصقات (تتبع الإعدادات) ═══ */
.qr-grid{display:grid;grid-template-columns:repeat(<?= (int)$QL['cols'] ?>,1fr);gap:4mm}
@media(max-width:900px){.qr-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.qr-grid{grid-template-columns:1fr}}
.qr-label{background:#fff;border:<?= $border_css ?>;border-radius:10px;padding:8px;page-break-inside:avoid}
.qr-body{display:flex;gap:8px;align-items:center;<?= $portrait ? 'flex-direction:column;text-align:center' : 'flex-direction:row' ?>}
.ql-qr{width:<?= (int)$QL['qr_size'] ?>px;height:<?= (int)$QL['qr_size'] ?>px;flex-shrink:0;border:1px solid #e2e8f0;border-radius:6px}
.ql-logo{object-fit:contain}
.ql-logo.pos-top{display:block;margin:0 auto 6px;height:<?= (int)$QL['logo_size'] ?>px;width:auto;max-width:100%}
.ql-logo.pos-side{height:<?= (int)$QL['logo_size'] ?>px;width:auto;flex-shrink:0}
.ql-txt{flex:1;min-width:0;<?= $portrait ? 'text-align:center' : '' ?>}
.ql-room{font-size:11px;font-weight:800;color:#0f172a;line-height:1.35;word-break:break-word}
.ql-sub{font-size:9px;color:#64748b;font-weight:600;font-family:'Inter';margin-top:1px}
.ql-path{font-size:9px;color:#64748b;font-weight:700;margin-top:3px}
.ql-sub2{color:#94a3b8;font-family:'Inter';font-weight:600}
.ql-code{font-family:'Inter',monospace;font-size:9.5px;font-weight:800;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:5px;padding:1px 6px;display:inline-block;margin-top:4px}
.qr-empty{text-align:center;padding:60px 20px;color:#94a3b8;background:#fff;border:1.5px dashed #cbd5e1;border-radius:18px}
.flash{background:#fff;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-weight:800;font-size:13px;border-right:4px solid #16a34a;color:#065f46}
/* ═══ طباعة ═══ */
@media print{
body *{visibility:hidden}
#printArea, #printArea *{visibility:visible}
#printArea{position:absolute;inset:0;padding:8mm}
.qr-grid{grid-template-columns:repeat(<?= (int)$QL['cols'] ?>,1fr);gap:4mm}
}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="qr-wrap">
<?php foreach (get_flash() as $fm): ?><div class="flash"><?= e($fm['message']) ?></div><?php endforeach; ?>

<section class="qr-hero">
<div class="ic"><i class="fa-solid fa-qrcode"></i></div>
<div style="flex:1;min-width:220px">
<h1><?= $rtl ? 'ملصقات QR للغرف' : 'Room QR Labels' ?></h1>
<p><?= $rtl ? 'ملصقات رسمية جاهزة للقص واللصق — الاتجاه واللغة والشعار والإطار كلها من الإعدادات أدناه' : 'Print-ready labels — orientation, language, logo & border configurable below' ?></p>
</div>
<a class="qr-btn slate" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl ? 'الداشبورد' : 'Hub' ?></a>
</section>

<!-- ═══ لوحة إعدادات الملصقات ═══ -->
<details class="qr-setcard">
<summary><i class="fa-solid fa-gear"></i> <?= $rtl ? 'إعدادات الملصقات (تُحفظ للنظام كاملاً)' : 'Label Settings' ?></summary>
<form method="POST" enctype="multipart/form-data" class="qr-setform">
<?= csrf_input() ?><input type="hidden" name="action" value="save_settings">
<div class="sf-grid">
<div class="sf"><label><?= $rtl ? 'اتجاه البطاقة' : 'Orientation' ?></label>
<select name="ql_orientation"><option value="landscape" <?= $QL['orientation']==='landscape'?'selected':'' ?>><?= $rtl ? 'أفقي (QR بجانب النص)' : 'Landscape' ?></option><option value="portrait" <?= $QL['orientation']==='portrait'?'selected':'' ?>><?= $rtl ? 'عمودي (QR فوق النص)' : 'Portrait' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'لغة الحقول' : 'Language' ?></label>
<select name="ql_lang"><option value="both" <?= $QL['lang']==='both'?'selected':'' ?>><?= $rtl ? 'عربي + إنجليزي' : 'Both' ?></option><option value="ar" <?= $QL['lang']==='ar'?'selected':'' ?>><?= $rtl ? 'عربي فقط' : 'Arabic' ?></option><option value="en" <?= $QL['lang']==='en'?'selected':'' ?>><?= $rtl ? 'إنجليزي فقط' : 'English' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'أعمدة الورقة' : 'Columns' ?></label>
<select name="ql_cols"><option value="2" <?= $QL['cols']==='2'?'selected':'' ?>>2</option><option value="3" <?= $QL['cols']==='3'?'selected':'' ?>>3</option><option value="4" <?= $QL['cols']==='4'?'selected':'' ?>>4</option></select></div>
<div class="sf"><label><?= $rtl ? 'حجم QR (px)' : 'QR size' ?></label><input type="number" name="ql_qr_size" min="56" max="140" value="<?= e($QL['qr_size']) ?>"></div>
<div class="sf"><label><?= $rtl ? 'إظهار الشعار' : 'Logo' ?></label>
<select name="ql_logo_show"><option value="1" <?= $QL['logo_show']==='1'?'selected':'' ?>><?= $rtl ? 'نعم' : 'Yes' ?></option><option value="0" <?= $QL['logo_show']==='0'?'selected':'' ?>><?= $rtl ? 'لا' : 'No' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'موضع الشعار' : 'Logo position' ?></label>
<select name="ql_logo_pos"><option value="top" <?= $QL['logo_pos']==='top'?'selected':'' ?>><?= $rtl ? 'أعلى' : 'Top' ?></option><option value="right" <?= $QL['logo_pos']==='right'?'selected':'' ?>><?= $rtl ? 'يمين' : 'Right' ?></option><option value="left" <?= $QL['logo_pos']==='left'?'selected':'' ?>><?= $rtl ? 'يسار' : 'Left' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'حجم الشعار (px)' : 'Logo size' ?></label><input type="number" name="ql_logo_size" min="16" max="80" value="<?= e($QL['logo_size']) ?>"></div>
<div class="sf"><label><?= $rtl ? 'رفع شعار جديد (اختياري)' : 'Upload logo' ?></label><input type="file" name="ql_logo" accept="image/*"></div>
<div class="sf"><label><?= $rtl ? 'إطار البطاقة' : 'Border' ?></label>
<select name="ql_border"><option value="1" <?= $QL['border']==='1'?'selected':'' ?>><?= $rtl ? 'نعم' : 'Yes' ?></option><option value="0" <?= $QL['border']==='0'?'selected':'' ?>><?= $rtl ? 'لا' : 'No' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'لون الإطار' : 'Border color' ?></label><input type="color" name="ql_border_color" value="<?= e($QL['border_color']) ?>"></div>
<div class="sf"><label><?= $rtl ? 'سماكة الإطار (px)' : 'Border width' ?></label><input type="number" name="ql_border_width" min="1" max="6" value="<?= e($QL['border_width']) ?>"></div>
<div class="sf"><label><?= $rtl ? 'نمط الإطار' : 'Border style' ?></label>
<select name="ql_border_style"><option value="solid" <?= $QL['border_style']==='solid'?'selected':'' ?>><?= $rtl ? 'مصمت' : 'Solid' ?></option><option value="dashed" <?= $QL['border_style']==='dashed'?'selected':'' ?>><?= $rtl ? 'متقطع' : 'Dashed' ?></option><option value="dotted" <?= $QL['border_style']==='dotted'?'selected':'' ?>><?= $rtl ? 'منقّط' : 'Dotted' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'إظهار الكود' : 'Show code' ?></label>
<select name="ql_show_code"><option value="1" <?= $QL['show_code']==='1'?'selected':'' ?>><?= $rtl ? 'نعم' : 'Yes' ?></option><option value="0" <?= $QL['show_code']==='0'?'selected':'' ?>><?= $rtl ? 'لا' : 'No' ?></option></select></div>
<div class="sf"><label><?= $rtl ? 'إظهار المسار (مبنى/طابق)' : 'Show path' ?></label>
<select name="ql_show_path"><option value="1" <?= $QL['show_path']==='1'?'selected':'' ?>><?= $rtl ? 'نعم' : 'Yes' ?></option><option value="0" <?= $QL['show_path']==='0'?'selected':'' ?>><?= $rtl ? 'لا' : 'No' ?></option></select></div>
</div>
<div class="sf-actions">
<button class="qr-btn amber" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl ? 'حفظ الإعدادات' : 'Save' ?></button>
<button class="qr-btn slate" type="submit" name="action" value="reset_settings" onclick="return confirm('<?= $rtl ? 'استعادة الإعدادات الافتراضية؟' : 'Reset to defaults?' ?>')"><i class="fa-solid fa-rotate-left"></i> <?= $rtl ? 'استعادة الافتراضي' : 'Reset' ?></button>
</div>
</form>
</details>

<div class="qr-tools">
<form method="GET" style="display:flex;gap:8px;align-items:center">
<select name="b" onchange="this.form.submit()">
<option value=""><?= $rtl ? 'كل المباني' : 'All buildings' ?></option>
<?php foreach ($buildings as $bd): ?>
<option value="<?= (int)$bd['id'] ?>" <?= $b==(int)$bd['id']?'selected':'' ?>><?= e($rtl ? $bd['name'] : ($bd['name_en'] ?: $bd['name'])) ?></option>
<?php endforeach; ?>
</select>
</form>
<button class="qr-btn amber" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= $rtl ? 'طباعة الملصقات' : 'Print' ?></button>
<span style="font-size:12.5px;color:#64748b;font-weight:700"><?= count($rooms) ?> <?= $rtl ? 'غرفة' : 'rooms' ?> · <?= $kCoded ?> <?= $rtl ? 'مكوّدة' : 'coded' ?></span>
</div>

<?php if (!$rooms): ?>
<div class="qr-empty"><i class="fa-solid fa-qrcode"></i><h3><?= $rtl ? 'لا توجد غرف' : 'No rooms' ?></h3></div>
<?php else: ?>
<div id="printArea">
<div class="qr-grid">
<?php foreach ($rooms as $r):
$payload = 'PMSH_ROOM:' . $r['id'] . ':' . ($r['location_code'] ?: ('R' . $r['id']));
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . rawurlencode($payload);
$arN = $r['name']; $enN = $r['name_en'] ?: $r['name'];
$arP = $r['building_name'] . ' — ' . $r['floor_name'];
$enP = ($r['building_en'] ?: $r['building_name']) . ' — ' . ($r['floor_en'] ?: $r['floor_name']);
if ($QL['lang'] === 'ar')      { $mainN=$arN; $subN='';   $mainP=$arP; $subP=''; }
elseif ($QL['lang'] === 'en') { $mainN=$enN; $subN='';   $mainP=$enP; $subP=''; }
else                          { $mainN=$arN; $subN=$enN; $mainP=$arP; $subP=$enP; }
$logoHtml = '';
if ($QL['logo_show'] === '1' && $logo_ok) {
    $pos = $portrait ? 'top' : $QL['logo_pos'];
    $logoHtml = '<img class="ql-logo ' . ($pos === 'top' ? 'pos-top' : 'pos-side') . '" src="' . e($logo_url) . '" alt="">';
}
?>
<div class="qr-label">
<?php if ($logoHtml && ($portrait || $QL['logo_pos'] === 'top')): ?><?= $logoHtml ?><?php endif; ?>
<div class="qr-body">
<?php if ($logoHtml && !$portrait && $QL['logo_pos'] === 'left'): ?><?= $logoHtml ?><?php endif; ?>
<img class="ql-qr" src="<?= e($qr_url) ?>" alt="QR" loading="lazy">
<div class="ql-txt">
<div class="ql-room"><?= e($mainN) ?></div>
<?php if ($subN): ?><div class="ql-sub"><?= e($subN) ?></div><?php endif; ?>
<?php if ($QL['show_path'] === '1'): ?><div class="ql-path"><?= e($mainP) ?><?php if ($subP): ?> <span class="ql-sub2"><?= e($subP) ?></span><?php endif; ?></div><?php endif; ?>
<?php if ($QL['show_code'] === '1'): ?><span class="ql-code"><?= e($r['location_code'] ?: ('R' . $r['id'])) ?></span><?php endif; ?>
</div>
<?php if ($logoHtml && !$portrait && $QL['logo_pos'] === 'right'): ?><?= $logoHtml ?><?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

</div></main>
</div>
</body>
</html>