<?php
/**
 * inventory/locations/occupancy.php — سجل إشغال الغرف (Occupancy Log)
 * • الإشغال الحالي (مشتق من item_locations.dept_id)
 * • سجل التغييرات التاريخي (room_occupancy_history) مع فلاتر وترقيم
 */
require_once dirname(__DIR__, 2) . '/config.php';
page_guard('inventory.index');
$rtl = is_rtl();

/* ═══ ضمان وجود جدول السجل (يعمل حتى قبل relocate) ═══ */
$pdo->exec("CREATE TABLE IF NOT EXISTS room_occupancy_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  dept_id INT UNSIGNED NULL,
  change_type ENUM('assign','vacate','move_in','move_out','swap') NOT NULL,
  decision_ref VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  changed_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_roh_room (room_id), INDEX idx_roh_dept (dept_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$TYPE_META = [
    'assign'   => ['ar'=>'إسناد أولي','en'=>'Initial Assign','color'=>'#2563eb','icon'=>'fa-link'],
    'move_in'  => ['ar'=>'دخول قسم','en'=>'Move In','color'=>'#16a34a','icon'=>'fa-right-to-bracket'],
    'move_out' => ['ar'=>'خروج قسم','en'=>'Move Out','color'=>'#f59e0b','icon'=>'fa-right-from-bracket'],
    'vacate'   => ['ar'=>'إخلاء / شاغر','en'=>'Vacate','color'=>'#64748b','icon'=>'fa-door-open'],
    'swap'     => ['ar'=>'تبادل','en'=>'Swap','color'=>'#7c3aed','icon'=>'fa-right-left'],
];

/* ═══ KPIs ═══ */
$kpi_events   = (int)$pdo->query("SELECT COUNT(*) FROM room_occupancy_history")->fetchColumn();
$kpi_occupied = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND dept_id IS NOT NULL")->fetchColumn();
$kpi_vacant   = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND (dept_id IS NULL OR dept_id=0)")->fetchColumn();
$kpi_depts    = (int)$pdo->query("SELECT COUNT(DISTINCT dept_id) FROM item_locations WHERE location_type='room' AND is_active=1 AND dept_id IS NOT NULL")->fetchColumn();

/* ═══ الإشغال الحالي (حسب القسم) ═══ */
$cur_occ = $pdo->query("SELECT d.id, d.name, COUNT(r.id) rooms
    FROM item_locations r JOIN departments d ON d.id=r.dept_id
    WHERE r.location_type='room' AND r.is_active=1
    GROUP BY d.id, d.name ORDER BY rooms DESC")->fetchAll(PDO::FETCH_ASSOC);

/* ═══ فلاتر السجل ═══ */
$f_b    = (int)($_GET['b'] ?? 0);
$f_d    = (int)($_GET['d'] ?? 0);
$f_t    = $_GET['t'] ?? '';
$f_from = trim($_GET['from'] ?? '');
$f_to   = trim($_GET['to'] ?? '');
$f_q    = trim($_GET['q'] ?? '');
if ($f_t && !isset($TYPE_META[$f_t])) $f_t = '';

$where = ['1=1']; $params = [];
if ($f_b)    { $where[] = 'b.id=?';            $params[] = $f_b; }
if ($f_d)    { $where[] = 'h.dept_id=?';       $params[] = $f_d; }
if ($f_t)    { $where[] = 'h.change_type=?';   $params[] = $f_t; }
if ($f_from) { $where[] = 'h.created_at >= ?'; $params[] = $f_from . ' 00:00:00'; }
if ($f_to)   { $where[] = 'h.created_at <= ?'; $params[] = $f_to . ' 23:59:59'; }
if ($f_q !== '') {
    $where[] = '(r.name LIKE ? OR r.name_en LIKE ? OR d.name LIKE ? OR h.notes LIKE ? OR h.decision_ref LIKE ?)';
    $like = "%$f_q%";
    $params = array_merge($params, [$like,$like,$like,$like,$like]);
}
$where_sql = implode(' AND ', $where);

$page_n = max(1, (int)($_GET['p'] ?? 1));
$per = 30;
$c = $pdo->prepare("SELECT COUNT(*) FROM room_occupancy_history h
    LEFT JOIN item_locations r ON r.id=h.room_id
    LEFT JOIN item_locations f ON f.id=r.parent_id
    LEFT JOIN item_locations b ON b.id=f.parent_id
    LEFT JOIN departments d ON d.id=h.dept_id
    WHERE $where_sql");
$c->execute($params);
$total = (int)$c->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$q = $pdo->prepare("SELECT h.*, r.name AS room_name, r.name_en AS room_name_en,
    f.name AS floor_name, b.name AS building_name, d.name AS dept_name, u.full_name AS actor_name
    FROM room_occupancy_history h
    LEFT JOIN item_locations r ON r.id=h.room_id
    LEFT JOIN item_locations f ON f.id=r.parent_id
    LEFT JOIN item_locations b ON b.id=f.parent_id
    LEFT JOIN departments d ON d.id=h.dept_id
    LEFT JOIN users u ON u.id=h.changed_by
    WHERE $where_sql
    ORDER BY h.created_at DESC, h.id DESC
    LIMIT $per OFFSET " . (($page_n - 1) * $per));
$q->execute($params);
$log = $q->fetchAll(PDO::FETCH_ASSOC);

$buildings = $pdo->query("SELECT id, name FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$depts     = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

function time_ago(?string $dt, bool $rtl): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return $rtl ? 'الآن' : 'now';
    if ($diff < 3600)      return floor($diff/60) . ' ' . ($rtl?'د':'m');
    if ($diff < 86400)     return floor($diff/3600) . ' ' . ($rtl?'س':'h');
    if ($diff < 86400*30)  return floor($diff/86400) . ' ' . ($rtl?'ي':'d');
    return date('Y-m-d', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl ? 'سجل إشغال الغرف' : 'Room Occupancy Log' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,input,select{font-family:'Tajawal',sans-serif}
.oc-wrap{max-width:1280px;margin:0 auto;padding:18px}
.oc-hero{background:linear-gradient(135deg,#0e7490,#0891b2 55%,#06b6d4);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;box-shadow:0 12px 32px rgba(8,145,178,.25);display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.oc-hero .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.oc-hero h1{margin:0;font-size:24px;font-weight:900}
.oc-hero p{margin:4px 0 0;font-size:13px;opacity:.9}
.oc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:920px){.oc-stats{grid-template-columns:repeat(2,1fr)}}
.oc-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.oc-stat .ic{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.oc-stat .v{font-size:22px;font-weight:800;line-height:1}
.oc-stat .l{font-size:12px;color:#64748b;margin-top:4px;font-weight:600}
.oc-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
@media(max-width:900px){.oc-grid{grid-template-columns:1fr}}
.oc-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:18px}
.oc-card h3{margin:0 0 14px;font-size:15px;font-weight:900;display:flex;gap:9px;align-items:center}
.oc-card h3 i{color:#0891b2;background:#ecfeff;padding:8px;border-radius:9px;font-size:13px}
.oc-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.oc-filters select,.oc-filters input{border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:12.5px;background:#fff}
.oc-filters input[type=text]{flex:1;min-width:160px}
.oc-btn{border:none;border-radius:10px;padding:9px 16px;font-weight:800;font-size:12.5px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.oc-btn.go{background:#0891b2;color:#fff}.oc-btn.cl{background:#f1f5f9;color:#475569}
table.oc{width:100%;border-collapse:collapse}
table.oc th{background:#f8fafc;padding:10px 12px;text-align:right;font-size:11px;font-weight:900;color:#475569;border-bottom:1.5px solid #e2e8f0}
table.oc td{padding:11px 12px;font-size:12.5px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.oc tr:hover td{background:#f8fafc}
.oc-badge{display:inline-flex;gap:5px;align-items:center;padding:4px 10px;border-radius:99px;color:#fff;font-size:11px;font-weight:800}
.oc-room b{display:block;font-size:12.5px}
.oc-room small{color:#64748b;font-size:10.5px}
.oc-empty{text-align:center;padding:40px 20px;color:#94a3b8;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px}
.oc-empty i{font-size:36px;display:block;margin-bottom:8px;color:#cbd5e1}
.oc-dept{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #eef2f7;border-radius:10px;margin-bottom:7px}
.oc-dept .nm{flex:1;font-weight:700;font-size:12.5px}
.oc-dept .ct{background:#ecfeff;color:#0e7490;font-weight:800;font-size:12px;padding:3px 10px;border-radius:99px}
.oc-pag{display:flex;justify-content:center;gap:6px;margin-top:14px}
.oc-pag a,.oc-pag span{padding:7px 13px;border-radius:8px;background:#fff;border:1px solid #e2e8f0;font-size:12px;font-weight:800;color:#475569;text-decoration:none}
.oc-pag .cur{background:#0e7490;border-color:#0e7490;color:#fff}
.oc-pag .dis{opacity:.4;pointer-events:none}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="oc-wrap">

<section class="oc-hero">
<div class="ic"><i class="fa-solid fa-book-open"></i></div>
<div style="flex:1;min-width:220px">
<h1><?= $rtl ? 'سجل إشغال الغرف' : 'Room Occupancy Log' ?></h1>
<p><?= $rtl ? 'الإشغال الحالي لكل قسم + التاريخ الكامل لتغيرات الإسناد والإخلاء والتبادل' : 'Current department occupancy + full history of assign/vacate/swap events' ?></p>
</div>
<a class="oc-btn" style="background:rgba(255,255,255,.18);color:#fff" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl ? 'الداشبورد' : 'Hub' ?></a>
</section>

<div class="oc-stats">
<div class="oc-stat"><div class="ic" style="background:#ecfeff;color:#0e7490"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="v"><?= number_format($kpi_events) ?></div><div class="l"><?= $rtl ? 'أحداث السجل' : 'Log events' ?></div></div></div>
<div class="oc-stat"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-door-closed"></i></div><div><div class="v"><?= number_format($kpi_occupied) ?></div><div class="l"><?= $rtl ? 'غرف مشغولة' : 'Occupied rooms' ?></div></div></div>
<div class="oc-stat"><div class="ic" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-door-open"></i></div><div><div class="v"><?= number_format($kpi_vacant) ?></div><div class="l"><?= $rtl ? 'غرف شاغرة' : 'Vacant rooms' ?></div></div></div>
<div class="oc-stat"><div class="ic" style="background:#ede9fe;color:#7c3aed"><i class="fa-solid fa-building"></i></div><div><div class="v"><?= number_format($kpi_depts) ?></div><div class="l"><?= $rtl ? 'أقسام تشغل غرفاً' : 'Depts with rooms' ?></div></div></div>
</div>

<div class="oc-grid">
<div class="oc-card">
<h3><i class="fa-solid fa-list-timeline"></i> <?= $rtl ? 'سجل التغييرات' : 'Change History' ?> <span style="font-size:11px;color:#94a3b8">(<?= number_format($total) ?>)</span></h3>
<form method="GET" class="oc-filters">
<select name="b"><option value=""><?= $rtl ? 'كل المباني' : 'All buildings' ?></option>
<?php foreach ($buildings as $b): ?><option value="<?= $b['id'] ?>" <?= $f_b==$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
<select name="d"><option value=""><?= $rtl ? 'كل الأقسام' : 'All depts' ?></option>
<?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= $f_d==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
<select name="t"><option value=""><?= $rtl ? 'كل الأنواع' : 'All types' ?></option>
<?php foreach ($TYPE_META as $tk=>$tm): ?><option value="<?= $tk ?>" <?= $f_t===$tk?'selected':'' ?>><?= $rtl ? $tm['ar'] : $tm['en'] ?></option><?php endforeach; ?></select>
<input type="date" name="from" value="<?= e($f_from) ?>">
<input type="date" name="to" value="<?= e($f_to) ?>">
<input type="text" name="q" value="<?= e($f_q) ?>" placeholder="<?= $rtl ? 'بحث (غرفة/قسم/ملاحظة/قرار)…' : 'Search…' ?>">
<button class="oc-btn go" type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl ? 'تصفية' : 'Filter' ?></button>
<a class="oc-btn cl" href="<?= BASE_URL ?>/inventory/locations/occupancy.php ?>"><i class="fa-solid fa-xmark"></i></a>
</form>
<?php if (!$log): ?>
<div class="oc-empty"><i class="fa-solid fa-book-open"></i><?= $rtl ? 'لا توجد أحداث مطابقة.' : 'No matching events.' ?></div>
<?php else: ?>
<table class="oc">
<thead><tr>
<th><?= $rtl ? 'الوقت' : 'Time' ?></th><th><?= $rtl ? 'الحدث' : 'Event' ?></th>
<th><?= $rtl ? 'الغرفة' : 'Room' ?></th><th><?= $rtl ? 'القسم' : 'Dept' ?></th>
<th><?= $rtl ? 'المرجع/ملاحظات' : 'Ref/Notes' ?></th><th><?= $rtl ? 'بواسطة' : 'By' ?></th>
</tr></thead>
<tbody>
<?php foreach ($log as $h):
$tm = $TYPE_META[$h['change_type']] ?? ['ar'=>$h['change_type'],'en'=>$h['change_type'],'color'=>'#64748b','icon'=>'fa-circle'];
?>
<tr>
<td><div style="font-weight:800"><?= e(time_ago($h['created_at'], $rtl)) ?></div><div style="font-size:10px;color:#94a3b8" class="eng"><?= e(date('Y-m-d H:i', strtotime($h['created_at']))) ?></div></td>
<td><span class="oc-badge" style="background:<?= $tm['color'] ?>"><i class="fa-solid <?= $tm['icon'] ?>"></i> <?= $rtl ? $tm['ar'] : $tm['en'] ?></span></td>
<td class="oc-room"><b><?= e($rtl ? ($h['room_name'] ?: '—') : ($h['room_name_en'] ?: $h['room_name'] ?: '—')) ?></b><small><?= e($h['building_name'] ?? '') ?> / <?= e($h['floor_name'] ?? '') ?></small></td>
<td><?= e($h['dept_name'] ?? ($rtl ? 'شاغر' : 'Vacant')) ?></td>
<td style="font-size:11px;color:#64748b"><?= e($h['decision_ref'] ?? '') ?><?= ($h['decision_ref'] && $h['notes']) ? ' · ' : '' ?><?= e($h['notes'] ?? '') ?></td>
<td style="font-size:11.5px;font-weight:700"><?= e($h['actor_name'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($pages > 1): ?>
<div class="oc-pag">
<?php $qs = http_build_query(array_filter(['b'=>$f_b,'d'=>$f_d,'t'=>$f_t,'from'=>$f_from,'to'=>$f_to,'q'=>$f_q], fn($v)=>$v!=='' && $v!==0)); ?>
<a class="<?= $page_n<=1?'dis':'' ?>" href="?<?= $qs ?>&p=<?= $page_n-1 ?>"><i class="fa-solid fa-chevron-<?= $rtl?'right':'left' ?>"></i></a>
<span class="cur eng"><?= $page_n ?> / <?= $pages ?></span>
<a class="<?= $page_n>=$pages?'dis':'' ?>" href="?<?= $qs ?>&p=<?= $page_n+1 ?>"><i class="fa-solid fa-chevron-<?= $rtl?'left':'right' ?>"></i></a>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<div class="oc-card">
<h3><i class="fa-solid fa-door-closed"></i> <?= $rtl ? 'الإشغال الحالي' : 'Current Occupancy' ?></h3>
<?php if (!$cur_occ): ?>
<div class="oc-empty"><i class="fa-solid fa-door-open"></i><?= $rtl ? 'لا غرف مسندة بعد.' : 'No rooms assigned yet.' ?></div>
<?php else: foreach ($cur_occ as $o): ?>
<div class="oc-dept"><span class="nm"><?= e($o['name']) ?></span><span class="ct"><?= number_format($o['rooms']) ?> <?= $rtl ? 'غرفة' : 'rooms' ?></span></div>
<?php endforeach; endif; ?>
<?php if ($kpi_vacant > 0): ?>
<div class="oc-dept" style="border-color:#fde68a;background:#fffbeb"><span class="nm" style="color:#92400e"><i class="fa-solid fa-door-open"></i> <?= $rtl ? 'شاغرة (بدون قسم)' : 'Vacant (no dept)' ?></span><span class="ct" style="background:#fef3c7;color:#92400e"><?= number_format($kpi_vacant) ?></span></div>
<?php endif; ?>
</div>
</div>

</div></main>
</div>
</body>
</html>