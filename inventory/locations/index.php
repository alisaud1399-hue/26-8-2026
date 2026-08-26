<?php
/**
* inventory/locations/index.php — داشبورد إدارة المواقع (Locations Hub)
* بطاقات وحدات مستقلة + KPIs + صلاحيات دقيقة (نفس فلسفة reports/assets)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/_helpers.php';
page_guard('inventory.index'); // بوابة الداشبورد (الأدمن/مصرّح الأصول)
$rtl = is_rtl();
$page_title = $rtl ? 'إدارة المواقع' : 'Locations Management';
$active_nav = 'inventory.locations';
$s = loc_stats($pdo);
$verify_pct = $s['rooms'] ? round($s['verified'] / $s['rooms'] * 100) : 0;
/* ═══ مؤشرات حية لوحدات المواقع ═══ */
$kR     = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1")->fetchColumn();
$kRv    = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND dept_id IS NOT NULL")->fetchColumn();
$pctR   = $kR ? round($kRv / $kR * 100) : 0;
$kRc    = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND location_code IS NOT NULL AND location_code!=''")->fetchColumn();
$kQr    = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND qr_path IS NOT NULL AND qr_path!=''")->fetchColumn();
try { $kMoves = (int)$pdo->query("SELECT COUNT(*) FROM room_occupancy_history")->fetchColumn(); } catch (Throwable $e) { $kMoves = 0; }
$kVac   = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND (dept_id IS NULL OR dept_id=0)")->fetchColumn();
/* ═══ وحدات إدارة المواقع (مرتّبة + مفعّلة) ═══ */
$units = [
['id'=>'tree',     'icon'=>'fa-sitemap',        'color'=>'#0891b2', 'grad'=>'linear-gradient(135deg,#0891b2,#06b6d4)',
't'=>'الشجرة والاستعراض', 'te'=>'Hierarchy & Browse',
'd'=>'استعراض هرمي للمباني والطوابق والغرف مع البحث والفلترة وحالة التوثيق.',
'perm'=>'inventory.locations.tree', 'href'=>'tree.php',
'kpi'=>number_format($kR), 'kl'=>'غرفة'],
['id'=>'verify',   'icon'=>'fa-link',           'color'=>'#16a34a', 'grad'=>'linear-gradient(135deg,#16a34a,#4ade80)',
't'=>'توثيق الأقسام', 'te'=>'Dept. Verification',
'd'=>'ربط الغرف بالأقسام يدوياً أو عبر المعالج الذكي مع الإسناد الجماعي.',
'perm'=>'inventory.locations.verify', 'href'=>'verify.php',
'kpi'=>$pctR.'%', 'kl'=>'نسبة التوثيق'],
['id'=>'coding',   'icon'=>'fa-barcode',        'color'=>'#7c3aed', 'grad'=>'linear-gradient(135deg,#7c3aed,#a855f7)',
't'=>'الترميز', 'te'=>'Coding',
'd'=>'رموز العقد للمباني والطوابق والغرف + بناء location_code بأي منهجية فاصل.',
'perm'=>'inventory.locations.coding', 'href'=>'coding.php',
'kpi'=>number_format($kRc), 'kl'=>'غرفة مكوّدة'],
['id'=>'qr',       'icon'=>'fa-qrcode',         'color'=>'#f59e0b', 'grad'=>'linear-gradient(135deg,#f59e0b,#fbbf24)',
't'=>'ملصقات QR', 'te'=>'QR Labels',
'd'=>'توليد وطباعة ملصقات QR للغرف مربوطة بالهوية الفيزيائية الثابتة.',
'perm'=>'inventory.locations.qr', 'href'=>'qr.php',
'kpi'=>number_format($kQr), 'kl'=>'ملصق مولّد'],
['id'=>'relocate', 'icon'=>'fa-right-left',     'color'=>'#dc2626', 'grad'=>'linear-gradient(135deg,#dc2626,#f97316)',
't'=>'نقل الأقسام', 'te'=>'Dept. Relocation',
'd'=>'معالج نقل قسم بين المواقع (إخلاء ← إسناد ← توزيع) مع سجل إشغال تاريخي.',
'perm'=>'inventory.locations.relocate', 'href'=>'relocate.php',
'kpi'=>number_format($kMoves), 'kl'=>'عملية نقل'],
['id'=>'occupancy','icon'=>'fa-book-open',      'color'=>'#0e7490', 'grad'=>'linear-gradient(135deg,#0e7490,#06b6d4)',
't'=>'سجل الإشغال', 'te'=>'Occupancy Log',
'd'=>'تاريخ إشغال الغرف بالأقسام (دخول/خروج/تبادل) + الغرف الشاغرة حالياً.',
'perm'=>'inventory.locations.occupancy', 'href'=>'occupancy.php',
'kpi'=>number_format($kVac), 'kl'=>'غرفة شاغرة'],
];
/* ═✅ التصحيح: كان foreach ($cards ...) والمصفوفة اسمها $units ═ */
$visible = [];
foreach ($units as $c) {
$c['exists']   = file_exists(__DIR__ . '/' . $c['href']);
$c['allowed']  = loc_can($c['perm']);
if ($c['allowed']) $visible[] = $c;
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button{font-family:'Tajawal',sans-serif}
.lh-wrap{max-width:1280px;margin:0 auto;padding:18px}
.lh-hero{position:relative;background:linear-gradient(135deg,#0f2545 0%,#0891b2 55%,#16a34a 100%);color:#fff;border-radius:22px;padding:26px 30px;margin-bottom:20px;box-shadow:0 12px 32px rgba(8,145,178,.25);overflow:hidden}
.lh-hero h1{margin:0 0 4px;font-size:26px;font-weight:900;display:flex;gap:12px;align-items:center}
.lh-hero p{margin:0;font-size:13px;opacity:.9}
.lh-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:920px){.lh-stats{grid-template-columns:repeat(2,1fr)}}
.lh-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 4px rgba(15,23,42,.05)}
.lh-stat .ic{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.lh-stat .v{font-size:22px;font-weight:900;color:#0f172a;line-height:1}
.lh-stat .l{font-size:12px;color:#64748b;margin-top:4px;font-weight:700}
.lh-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media(max-width:1100px){.lh-cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.lh-cards{grid-template-columns:1fr}}
.lh-card{position:relative;background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;text-decoration:none;color:inherit;display:flex;flex-direction:column;overflow:hidden;transition:.25s;box-shadow:0 2px 4px rgba(15,23,42,.05)}
.lh-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(15,23,42,.1);border-color:var(--ac)}
.lh-card.off{opacity:.55;cursor:default}
.lh-card.off:hover{transform:none;border-color:#e2e8f0}
.lh-head{padding:16px 18px 12px;background:var(--gr);color:#fff;display:flex;align-items:center;gap:12px;min-height:58px}
.lh-head .ic{width:42px;height:42px;border-radius:10px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.lh-head .t{font-size:15px;font-weight:800;flex:1}
.lh-body{padding:14px 18px;flex:1}
.lh-desc{font-size:12.5px;color:#475569;line-height:1.55;margin-bottom:12px;min-height:48px}
.lh-kpi{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;font-weight:700}
.lh-kpi .v{font-size:17px;font-weight:900;color:var(--ac)}
.lh-foot{padding:10px 18px 14px;border-top:1px solid #f1f5f9;display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700}
.lh-foot .open{color:var(--ac);margin-inline-start:auto}
.lh-foot .soon{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:10px;font-size:11px}
.lh-foot .lock{background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:10px;font-size:11px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="lh-wrap">
<section class="lh-hero">
<h1><i class="fa-solid fa-map-location-dot"></i> <?= e($page_title) ?></h1>
<p><?= $rtl ? 'مركز موحّد لإدارة المواقع: توثيق، ترميز، ملصقات، ونقل أقسام — بصلاحيات دقيقة لكل وحدة' : 'Unified hub for locations: verification, coding, QR labels & relocation — with per-unit permissions' ?></p>
</section>
<div class="lh-stats">
<div class="lh-stat"><div class="ic" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-building"></i></div><div><div class="v"><?= number_format($s['buildings']) ?></div><div class="l"><?= $rtl?'مبانٍ':'Buildings' ?></div></div></div>
<div class="lh-stat"><div class="ic" style="background:#ede9fe;color:#7c3aed"><i class="fa-solid fa-door-open"></i></div><div><div class="v"><?= number_format($s['rooms']) ?></div><div class="l"><?= $rtl?'غرف':'Rooms' ?></div></div></div>
<div class="lh-stat"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-link"></i></div><div><div class="v"><?= number_format($s['verified']) ?></div><div class="l"><?= $rtl?'غرف موثّقة':'Verified rooms' ?></div></div></div>
<div class="lh-stat"><div class="ic" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="v"><?= number_format($s['unverified']) ?></div><div class="l"><?= $rtl?'بانتظار التوثيق':'Pending verification' ?></div></div></div>
</div>
<h2 style="font-size:17px;font-weight:900;margin:0 0 14px;display:flex;gap:10px;align-items:center"><i class="fa-solid fa-table-list" style="color:#0891b2"></i> <?= $rtl?'وحدات إدارة المواقع':'Location Modules' ?></h2>
<div class="lh-cards">
<?php foreach ($visible as $c):
$ready = $c['exists'];
$tag = $ready ? 'a' : 'div';
?>
<<?= $tag ?> <?= $ready ? 'href="'.e($c['href']).'"' : '' ?> class="lh-card <?= $ready?'':'off' ?>" style="--ac:<?= e($c['color']) ?>;--gr:<?= e($c['grad']) ?>">
<div class="lh-head"><div class="ic"><i class="fa-solid <?= e($c['icon']) ?>"></i></div><div class="t"><?= e($rtl?$c['t']:$c['te']) ?></div></div>
<div class="lh-body">
<div class="lh-desc"><?= e($c['d']) ?></div>
<div class="lh-kpi"><i class="fa-solid fa-chart-line" style="color:<?= e($c['color']) ?>"></i><span class="v"><?= e($c['kpi']) ?></span><span><?= e($c['kl']) ?></span></div>
</div>
<div class="lh-foot">
<?php if (!$ready): ?><span class="soon"><i class="fa-solid fa-clock"></i> <?= $rtl?'قريباً':'Soon' ?></span>
<?php else: ?><i class="fa-solid fa-file-invoice"></i><span><?= $rtl?'وحدة مستقلة':'Standalone unit' ?></span><span class="open"><?= $rtl?'فتح':'Open' ?> <i class="fa-solid fa-arrow-left"></i></span><?php endif; ?>
</div>
</<?= $tag ?>>
<?php endforeach; ?>
</div>
</div></main>
</div>
</body>
</html>