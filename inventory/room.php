<?php
/**
 * inventory/room.php — «جواز سفر الغرفة» / تقرير الغرفة الشامل
 * يُفتح عبر الرابط أو عبر مسح ملصق QR على باب الغرفة
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index');
$rtl = is_rtl();

/* ── تحديد الغرفة (id أو code) ── */
$code = trim($_GET['code'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$room = null;
if ($id) { $st=$pdo->prepare("SELECT * FROM item_locations WHERE id=?"); $st->execute([$id]); $room=$st->fetch(PDO::FETCH_ASSOC); }
elseif ($code!=='') { $st=$pdo->prepare("SELECT * FROM item_locations WHERE location_code=?"); $st->execute([$code]); $room=$st->fetch(PDO::FETCH_ASSOC); }

if (!$room || ($room['location_type'] ?? '') !== 'room') {
    echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><style>body{font-family:Tajawal,sans-serif;background:#f1f5f9;display:grid;place-items:center;min-height:100vh}.box{background:#fff;padding:40px;border-radius:20px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08)}h1{font-size:22px}a{color:#2563eb}</style></head><body><div class="box"><h1>⚠️ الغرفة غير موجودة</h1><p>الرابط غير صالح أو الغرفة محذوفة.</p><a href="'.BASE_URL.'/settings/locations.php">العودة لإدارة المواقع</a></div></body></html>';
    exit;
}

/* ── المسار: طابق ← مبنى ── */
$floor=null; $building=null;
if (!empty($room['parent_id'])) {
    $st=$pdo->prepare("SELECT * FROM item_locations WHERE id=?"); $st->execute([$room['parent_id']]); $floor=$st->fetch(PDO::FETCH_ASSOC);
    if ($floor && !empty($floor['parent_id'])) { $st=$pdo->prepare("SELECT * FROM item_locations WHERE id=?"); $st->execute([$floor['parent_id']]); $building=$st->fetch(PDO::FETCH_ASSOC); }
}

/* ── القسم + الأمين ── */
$dept=null;
if (!empty($room['dept_id'])) { $st=$pdo->prepare("SELECT * FROM departments WHERE id=?"); $st->execute([$room['dept_id']]); $dept=$st->fetch(PDO::FETCH_ASSOC); }
$custodian=null;
if (!empty($room['custodian_user_id'])) { $st=$pdo->prepare("SELECT id, full_name FROM users WHERE id=?"); $st->execute([$room['custodian_user_id']]); $custodian=$st->fetch(PDO::FETCH_ASSOC); }

/* ── الأصول + الإحصاءات ── */
$st=$pdo->prepare("SELECT * FROM assets WHERE location_id=? ORDER BY tag_number"); $st->execute([$room['id']]);
$assets=$st->fetchAll(PDO::FETCH_ASSOC);

$total=count($assets); $active=0; $verified=0; $critical=0; $high=0; $h_sum=0; $h_n=0; $by_type=[];
foreach ($assets as $a) {
    if (($a['status']??'')==='active') $active++;
    if (($a['verified_status']??'')==='تم التحقق') $verified++;
    $rb=$a['risk_band']??''; if ($rb==='critical') $critical++; elseif ($rb==='high') $high++;
    if (isset($a['health_score']) && $a['health_score']!=='' && $a['health_score']!==null) { $h_sum+=(float)$a['health_score']; $h_n++; }
    $t = trim((string)($a['asset_type']??'')); $by_type[$t?:'غير مصنف']=($by_type[$t?:'غير مصنف']??0)+1;
}
arsort($by_type); $by_type=array_slice($by_type,0,6,true);
$avg_health=$h_n?round($h_sum/$h_n):null;
$ver_pct=$total?round($verified*100/$total):0;
$h_color=$avg_health===null?'#94a3b8':($avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444'));
$max_type=$by_type?max($by_type):1;
$asset_ids=array_map('intval', array_filter(array_column($assets,'id')));
$in=$asset_ids?implode(',',$asset_ids):'0';

/* ── البلاغات (دفاعي) ── */
$comp_total=null; $comp_open=null; $comp_list=[];
if ($asset_ids) {
    try {
        $comp_total=(int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE asset_id IN ($in)")->fetchColumn();
        try { $comp_open=(int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE asset_id IN ($in) AND status NOT IN ('closed','resolved','cancelled')")->fetchColumn(); }
        catch (Throwable $e) { $comp_open=$comp_total; }
        $comp_list=$pdo->query("SELECT c.*, a.tag_number FROM complaints c JOIN assets a ON a.id=c.asset_id WHERE c.asset_id IN ($in) ORDER BY c.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $comp_total=null; }
}

/* ── آخر جرد (دفاعي) ── */
$room_audits=0; $last_audit=null;
if ($asset_ids) {
    try {
        $room_audits=(int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE asset_id IN ($in)")->fetchColumn();
        $last_audit=$pdo->query("SELECT ia.audited_at, ia.action, s.title s_title, u.full_name auditor FROM inventory_audits ia LEFT JOIN inventory_sessions s ON s.id=ia.session_id LEFT JOIN users u ON u.id=ia.user_id WHERE ia.asset_id IN ($in) ORDER BY ia.audited_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($room['name_en'] ?: $room['name']) ?> — <?= $rtl?'تقرير الغرفة':'Room Report' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--bg:#f1f5f9;--card:#fff;--bd:#e2e8f0;--tx:#0f172a;--mu:#64748b;--pr:#2563eb;--gn:#10b981;--am:#f59e0b;--rd:#ef4444}
*{box-sizing:border-box}body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tx);margin:0}
.page-content{padding:20px;max-width:1200px;margin:0 auto}
.hero{background:linear-gradient(135deg,#0f2545,#1a3a6b 60%,#2563eb);border-radius:20px;padding:26px 28px;color:#fff;display:flex;gap:20px;align-items:center;justify-content:space-between;box-shadow:0 12px 34px rgba(15,37,69,.25);margin-bottom:18px}
.crumb{font-size:12px;opacity:.75;margin-bottom:6px}
.hero h1{margin:0 0 6px;font-size:26px;font-weight:900}
.hero-sub{font-size:12.5px;opacity:.85;margin-bottom:12px}
.mono{font-family:monospace;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:6px}
.badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;display:inline-flex;gap:6px;align-items:center}
.hero-side{display:flex;flex-direction:column;align-items:center;gap:12px}
.hero-qr{width:110px;height:110px;background:#fff;border-radius:12px;padding:6px}
.btn-print{background:#fff;color:#0f2545;border:none;padding:10px 22px;border-radius:12px;font-family:inherit;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px}
.kpi{background:var(--card);border:1px solid var(--bd);border-radius:16px;padding:14px;text-align:center}
.kpi b{display:block;font-size:24px;font-weight:900}
.kpi span{font-size:11px;color:var(--mu);font-weight:700}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:16px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:16px;padding:18px}
.card h3{margin:0 0 14px;font-size:14px;font-weight:800;display:flex;gap:8px;align-items:center}
.donut-wrap{display:flex;gap:18px;align-items:center;justify-content:center}
.donut{position:relative;width:120px;height:120px;border-radius:50%;background:conic-gradient(var(--gn) calc(var(--p)*1%),#e2e8f0 0)}
.donut-hole{position:absolute;inset:16px;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center}
.donut-hole b{font-size:22px;font-weight:900}
.donut-hole span{font-size:10px;color:var(--mu);font-weight:700}
.gauge{height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:8px 0}
.gauge i{display:block;height:100%;border-radius:99px}
.tbar{margin-bottom:10px}
.tbar .lb{display:flex;justify-content:space-between;font-size:11.5px;font-weight:700;margin-bottom:4px}
.tbar .tr{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.tbar .tr i{display:block;height:100%;background:var(--pr);border-radius:99px}
.stat-line{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px dashed var(--bd);font-size:13px}
.stat-line:last-child{border:none}
table{width:100%;border-collapse:collapse;font-size:12px}
th{background:#f8fafc;text-align:start;padding:9px 10px;font-size:11px;color:var(--mu)}
td{padding:8px 10px;border-bottom:1px solid var(--bd)}
.pill{padding:2px 9px;border-radius:99px;font-size:10.5px;font-weight:800}
.p-ok{background:#dcfce7;color:#166534}.p-wait{background:#fef9c3;color:#854d0e}.p-crit{background:#fee2e2;color:#991b1b}.p-mut{background:#e2e8f0;color:#475569}
.comp{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px dashed var(--bd);font-size:12px}
.comp:last-child{border:none}
@media print{
  body *{visibility:hidden}
  .room-report,.room-report *{visibility:visible}
  .room-report{position:absolute;inset:0;width:100%;padding:0;max-width:none}
  .no-print{display:none!important}
  .hero{box-shadow:none}
  .card,.kpi{break-inside:avoid}
}
</style>
</head>
<body>
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="room-report">

  <div class="hero">
    <div>
      <div class="crumb"><?= e($building['name_en'] ?? $building['name'] ?? '—') ?> ← <?= e($floor['name_en'] ?? $floor['name'] ?? '—') ?></div>
      <h1><?= e($room['name_en'] ?: $room['name']) ?></h1>
      <div class="hero-sub"><?= e($room['name']) ?> • <span class="mono"><?= e($room['location_code']) ?></span></div>
      <div class="badges">
        <span class="badge"><i class="fa-solid fa-hospital"></i> <?= $dept ? e($dept['name_en'] ?: $dept['name']) : ($rtl?'بدون قسم':'No dept') ?></span>
        <span class="badge"><i class="fa-solid fa-user-shield"></i> <?= $custodian ? e($custodian['full_name']) : ($rtl?'بدون أمين':'No custodian') ?></span>
        <?php if (!empty($room['room_code'])): ?><span class="badge"><i class="fa-solid fa-door-open"></i> <?= e($room['room_code']) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="hero-side">
      <?php if (!empty($room['qr_path'])): ?><img class="hero-qr" src="<?= BASE_URL ?>/<?= e($room['qr_path']) ?>" alt="QR"><?php endif; ?>
      <button class="btn-print no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= $rtl?'طباعة التقرير':'Print' ?></button>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><b><?= $total ?></b><span><?= $rtl?'إجمالي الأصول':'Assets' ?></span></div>
    <div class="kpi"><b style="color:var(--pr)"><?= $active ?></b><span><?= $rtl?'نشطة':'Active' ?></span></div>
    <div class="kpi"><b style="color:var(--gn)"><?= $verified ?></b><span><?= $rtl?'تم التحقق':'Verified' ?></span></div>
    <div class="kpi"><b style="color:var(--rd)"><?= $critical ?></b><span><?= $rtl?'حرجة':'Critical' ?></span></div>
    <div class="kpi"><b style="color:<?= $h_color ?>"><?= $avg_health!==null?$avg_health.'%':'—' ?></b><span><?= $rtl?'متوسط الصحة':'Health' ?></span></div>
    <div class="kpi"><b style="color:var(--am)"><?= $comp_total!==null?$comp_open:'—' ?></b><span><?= $rtl?'بلاغات مفتوحة':'Open' ?></span></div>
  </div>

  <div class="grid">
    <div class="card">
      <h3><i class="fa-solid fa-circle-check" style="color:var(--gn)"></i> <?= $rtl?'حالة التحقق':'Verification' ?></h3>
      <div class="donut-wrap">
        <div class="donut" style="--p:<?= $ver_pct ?>"><div class="donut-hole"><b><?= $ver_pct ?>%</b><span><?= $rtl?'تم التحقق':'Verified' ?></span></div></div>
        <div>
          <div class="stat-line"><span><?= $rtl?'تم التحقق':'Verified' ?></span><b><?= $verified ?></b></div>
          <div class="stat-line"><span><?= $rtl?'لم يُتحقق':'Not verified' ?></span><b><?= $total-$verified ?></b></div>
        </div>
      </div>
    </div>
    <div class="card">
      <h3><i class="fa-solid fa-heart-pulse" style="color:<?= $h_color ?>"></i> <?= $rtl?'صحة الأصول':'Health' ?></h3>
      <div class="stat-line"><span><?= $rtl?'متوسط الصحة':'Average' ?></span><b style="color:<?= $h_color ?>"><?= $avg_health!==null?$avg_health.'%':'—' ?></b></div>
      <div class="gauge"><i style="width:<?= $avg_health??0 ?>%;background:<?= $h_color ?>"></i></div>
      <div class="stat-line"><span><?= $rtl?'حرجة':'Critical' ?></span><b style="color:var(--rd)"><?= $critical ?></b></div>
      <div class="stat-line"><span><?= $rtl?'عالية الخطورة':'High' ?></span><b style="color:var(--am)"><?= $high ?></b></div>
    </div>
    <div class="card">
      <h3><i class="fa-solid fa-chart-simple" style="color:var(--pr)"></i> <?= $rtl?'توزيع الأصول':'By type' ?></h3>
      <?php if (!$by_type): ?><p style="color:var(--mu);font-size:12px"><?= $rtl?'لا توجد أصول':'No assets' ?></p><?php endif; ?>
      <?php foreach ($by_type as $t=>$c): ?>
      <div class="tbar"><div class="lb"><span><?= e($t) ?></span><span><?= $c ?></span></div><div class="tr"><i style="width:<?= round($c*100/$max_type) ?>%"></i></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <h3><i class="fa-solid fa-bell" style="color:var(--am)"></i> <?= $rtl?'البلاغات':'Complaints' ?> <?= $comp_total!==null?"($comp_total)":'' ?></h3>
      <?php if ($comp_total===null): ?><p style="color:var(--mu);font-size:12px"><?= $rtl?'وحدة البلاغات غير متاحة':'N/A' ?></p>
      <?php elseif (!$comp_list): ?><p style="color:var(--mu);font-size:12px"><?= $rtl?'لا توجد بلاغات لهذه الغرفة':'No complaints' ?></p>
      <?php else: foreach ($comp_list as $c): ?>
        <div class="comp"><span class="mono"><?= e($c['tag_number']??'') ?></span><span style="flex:1"><?= e(mb_substr($c['description']??$c['title']??'',0,60)) ?></span><span class="pill p-wait"><?= e($c['status']??'') ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card">
      <h3><i class="fa-solid fa-clipboard-check" style="color:var(--gn)"></i> <?= $rtl?'آخر جرد':'Last audit' ?></h3>
      <?php if (!$last_audit): ?><p style="color:var(--mu);font-size:12px"><?= $rtl?'لم تُجرد أصول هذه الغرفة بعد':'Not audited yet' ?></p>
      <?php else: ?>
        <div class="stat-line"><span><?= $rtl?'الجلسة':'Session' ?></span><b><?= e($last_audit['s_title']??'') ?></b></div>
        <div class="stat-line"><span><?= $rtl?'التاريخ':'Date' ?></span><b><?= e($last_audit['audited_at']??'') ?></b></div>
        <div class="stat-line"><span><?= $rtl?'بواسطة':'By' ?></span><b><?= e($last_audit['auditor']??'') ?></b></div>
        <div class="stat-line"><span><?= $rtl?'إجمالي فحوصات الغرفة':'Audits' ?></span><b><?= $room_audits ?></b></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3><i class="fa-solid fa-boxes-stacked" style="color:var(--pr)"></i> <?= $rtl?'الأصول في هذه الغرفة':'Assets' ?> (<?= $total ?>)</h3>
    <?php if (!$assets): ?><p style="color:var(--mu);font-size:12px"><?= $rtl?'لا توجد أصول مسجلة في هذه الغرفة':'No assets' ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= $rtl?'التاج':'Tag' ?></th><th><?= $rtl?'الوصف':'Description' ?></th><th><?= $rtl?'الموديل':'Model' ?></th><th><?= $rtl?'الحالة':'Status' ?></th><th><?= $rtl?'الصحة':'Health' ?></th><th><?= $rtl?'التحقق':'Verified' ?></th></tr>
      <?php foreach ($assets as $a): $hh=(int)($a['health_score']??0); ?>
      <tr>
        <td class="mono"><?= e($a['tag_number']) ?></td>
        <td><?= e($a['description_ar'] ?: $a['description']) ?></td>
        <td><?= e($a['model_number']??'') ?></td>
        <td><span class="pill <?= ($a['status']??'')==='active'?'p-ok':'p-mut' ?>"><?= e($a['status']??'') ?></span></td>
        <td style="color:<?= $hh>=75?'var(--gn)':($hh>=50?'var(--am)':'var(--rd)') ?>;font-weight:800"><?= $hh ?>%</td>
        <td><?php if (($a['risk_band']??'')==='critical'): ?><span class="pill p-crit"><?= $rtl?'حرج':'Critical' ?></span><?php elseif (($a['verified_status']??'')==='تم التحقق'): ?><span class="pill p-ok"><?= $rtl?'مُتحقق':'OK' ?></span><?php else: ?><span class="pill p-wait"><?= $rtl?'معلّق':'Pending' ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

</div>
</main>
</div>
</body>
</html>