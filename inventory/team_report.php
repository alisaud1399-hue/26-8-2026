<?php
/**
 * inventory/team_report.php — تقرير إحصائيات الفرق/الأعضاء في جلسة الجرد
 * يعرض إحصائيات شاملة لكل عضو مع إمكانية التصدير
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index');

$sid = (int)($_GET['session_id'] ?? 0);
if (!$sid) abort(404);

$s = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
$s->execute([$sid]);
$session = $s->fetch(PDO::FETCH_ASSOC);
if (!$session) abort(404);

$rtl = is_rtl();
$page_title = ($rtl ? 'تقرير الفرق' : 'Team Report') . ' — ' . ($session['session_code'] ?? '');

/* جلب الأعضاء والإحصائيات */
$members = $pdo->prepare("
    SELECT m.user_id, m.role, u.full_name
    FROM inventory_session_members m
    JOIN users u ON u.id = m.user_id
    WHERE m.session_id = ?
    ORDER BY FIELD(m.role,'leader','member','observer'), u.full_name
");
$members->execute([$sid]);
$members_list = $members->fetchAll(PDO::FETCH_ASSOC);

/* إحصائيات الجرد لكل عضو */
$aud = $pdo->prepare("SELECT audited_by, action, COUNT(*) c, MAX(audited_at) last_at, MIN(audited_at) first_at FROM inventory_audits WHERE session_id=? GROUP BY audited_by, action");
$aud->execute([$sid]);
$stats = [];
foreach ($aud->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $u = (int)$r['audited_by'];
    $stats[$u]['total'] = ($stats[$u]['total'] ?? 0) + (int)$r['c'];
    $stats[$u][$r['action']] = ($stats[$u][$r['action']] ?? 0) + (int)$r['c'];
    $stats[$u]['first_at'] = min($stats[$u]['first_at'] ?? '9999', $r['first_at'] ?? '9999');
    $stats[$u]['last_at'] = max($stats[$u]['last_at'] ?? '', $r['last_at'] ?? '');
}

/* أقفال الغرف */
$done = $pdo->prepare("SELECT locked_by, COUNT(*) c FROM room_inventory_locks WHERE session_id=? AND status='completed' GROUP BY locked_by");
$done->execute([$sid]);
$doneByUser = [];
foreach ($done->fetchAll(PDO::FETCH_ASSOC) as $r) $doneByUser[(int)$r['locked_by']] = (int)$r['c'];

/* الوقت التراكمي */
require_once BASE_PATH . '/includes/session_controls.php';
smc_schema($pdo);

function team_cumulative(PDO $pdo, int $sid, int $uid): int {
    $total = 0;
    $locks = $pdo->prepare("SELECT id, status FROM room_inventory_locks WHERE session_id=? AND locked_by=? ORDER BY id");
    $locks->execute([$sid, $uid]);
    foreach ($locks->fetchAll(PDO::FETCH_ASSOC) as $lk) {
        $evts = $pdo->prepare("SELECT event_type, created_at FROM room_lock_events WHERE lock_id=? ORDER BY id");
        $evts->execute([(int)$lk['id']]);
        $events = $evts->fetchAll(PDO::FETCH_ASSOC);
        $started_at = null;
        foreach ($events as $ev) {
            $time = strtotime($ev['created_at']);
            if ($ev['event_type'] === 'opened' || $ev['event_type'] === 'resumed') $started_at = $time;
            elseif (in_array($ev['event_type'], ['suspended','completed','taken_over','expired']) && $started_at !== null) {
                $total += max(0, $time - $started_at);
                $started_at = null;
            }
        }
        if ($started_at !== null && $lk['status'] === 'active') $total += max(0, time() - $started_at);
    }
    return $total;
}

$role_lbl = $rtl ? ['leader' => 'قائد', 'member' => 'عضو', 'observer' => 'مراقب'] : ['leader' => 'Leader', 'member' => 'Member', 'observer' => 'Observer'];

$out_data = [];
$totals = ['total'=>0,'confirmed'=>0,'missing'=>0,'location_changed'=>0,'custody_changed'=>0,'condition_damaged'=>0,'surplus'=>0];
foreach ($members_list as $m) {
    $u = (int)$m['user_id'];
    $st = $stats[$u] ?? [];
    $cum = team_cumulative($pdo, $sid, $u);
    $confirmed = $st['confirmed'] ?? 0;
    $total_a = $st['total'] ?? 0;
    $pct = $total_a > 0 ? round($confirmed * 100 / $total_a) : 0;
    $out_data[] = [
        'user_id' => $u, 'name' => $m['full_name'], 'role' => $m['role'],
        'total' => $total_a, 'confirmed' => $confirmed, 'missing' => $st['missing'] ?? 0,
        'location_changed' => $st['location_changed'] ?? 0, 'custody_changed' => $st['custody_changed'] ?? 0,
        'condition_damaged' => $st['condition_damaged'] ?? 0, 'surplus' => $st['surplus'] ?? 0,
        'rooms_done' => $doneByUser[$u] ?? 0, 'cumulative_sec' => $cum,
        'pct' => $pct, 'first_at' => $st['first_at'] ?? null, 'last_at' => $st['last_at'] ?? null,
    ];
    foreach ($totals as $k => $v) $totals[$k] = $v + ($st[$k] ?? 0);
}
$total_members = count($out_data);
$total_pct = $totals['total'] > 0 ? round($totals['confirmed'] * 100 / $totals['total']) : 0;

$session_duration = ($session['start_date'] && $session['end_date']) ?
    max(0, strtotime($session['end_date']) - strtotime($session['start_date'])) : 0;
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Tajawal',sans-serif;}
body{background:#f8fafc;min-height:100vh;}
.eng{font-family:'Inter',sans-serif;}
.page{max-width:1100px;margin:0 auto;padding:20px;}
.page-title{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.page-title h1{font-size:20px;font-weight:900;color:#0f172a;}
.page-title .back{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:10px;padding:8px 14px;color:#475569;text-decoration:none;font-weight:800;font-size:13px;}
.page-title .back:hover{background:#e2e8f0;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px;}
.kpi{background:#fff;border-radius:14px;padding:16px;border:1px solid #e2e8f0;text-align:center;}
.kpi .val{font-size:28px;font-weight:900;font-family:'Inter';line-height:1;}
.kpi .lbl{font-size:11px;color:#64748b;font-weight:700;margin-top:4px;}
.kpi .sub{font-size:10px;color:#94a3b8;margin-top:2px;}
.c-blue{color:#2563eb;}.c-green{color:#10b981;}.c-red{color:#dc2626;}.c-amber{color:#d97706;}.c-purple{color:#7c3aed;}
.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:20px;}
.tbl-head{padding:14px 18px;border-bottom:1.5px solid #f1f5f9;font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px;}
table{width:100%;border-collapse:collapse;}
th{background:#f8fafc;padding:10px 14px;font-size:11px;font-weight:800;color:#64748b;text-align:center;border-bottom:1.5px solid #e2e8f0;}
td{padding:10px 14px;font-size:12px;color:#475569;text-align:center;border-bottom:1px solid #f1f5f9;}
tr:hover td{background:#faf5ff;}
td.name{text-align:right;font-weight:900;color:#0f172a;}
td.num{font-family:'Inter';font-weight:900;}
.prog{width:80px;height:6px;background:#e2e8f0;border-radius:99px;display:inline-block;vertical-align:middle;margin-inline-start:4px;overflow:hidden;}
.prog .fg{height:100%;border-radius:99px;transition:width .5s;}
.role-badge{font-size:9px;font-weight:800;padding:2px 6px;border-radius:99px;}
.role-leader{background:#ede9fe;color:#6d28d9;}
.role-member{background:#dbeafe;color:#1d4ed8;}
.role-observer{background:#f1f5f9;color:#64748b;}
@media print{.no-print{display:none!important;}.page{padding:0;}}
</style>
</head>
<body>
<div class="page">
<div class="page-title">
<a class="back no-print" href="<?= BASE_URL ?>/inventory/session.php?id=<?= $sid ?>"><i class="fa-solid fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i> <?= $rtl ? 'رجوع' : 'Back' ?></a>
<h1><i class="fa-solid fa-chart-bar" style="color:#7c3aed;"></i> <?= $page_title ?></h1>
<button class="back no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= $rtl ? 'طباعة' : 'Print' ?></button>
</div>

<div class="kpi-grid">
<div class="kpi"><div class="val c-purple eng"><?= $total_members ?></div><div class="lbl"><?= $rtl ? 'أعضاء' : 'Members' ?></div></div>
<div class="kpi"><div class="val c-blue eng"><?= number_format($totals['total']) ?></div><div class="lbl"><?= $rtl ? 'إجمالي الإجراءات' : 'Total Actions' ?></div></div>
<div class="kpi"><div class="val c-green eng"><?= number_format($totals['confirmed']) ?></div><div class="lbl"><?= $rtl ? 'مؤكّد' : 'Confirmed' ?></div></div>
<div class="kpi"><div class="val c-red eng"><?= number_format($totals['missing']) ?></div><div class="lbl"><?= $rtl ? 'مفقود' : 'Missing' ?></div></div>
<div class="kpi"><div class="val c-amber eng"><?= $total_pct ?>%</div><div class="lbl"><?= $rtl ? 'نسبة الإنجاز' : 'Completion' ?></div></div>
<div class="kpi"><div class="val c-blue eng"><?= count(array_filter($out_data, fn($x) => $x['rooms_done'] > 0)) ?>/<?= $total_members ?></div><div class="lbl"><?= $rtl ? 'أعضاء أكملوا غرفة' : 'Members w/ Rooms' ?></div></div>
</div>

<div class="tbl-wrap">
<div class="tbl-head"><i class="fa-solid fa-users" style="color:#7c3aed;"></i> <?= $rtl ? 'إحصائيات الأعضاء التفصيلية' : 'Detailed Member Stats' ?></div>
<div style="overflow-x:auto;">
<table>
<thead>
<tr>
<th><?= $rtl ? 'العضو' : 'Member' ?></th>
<th><?= $rtl ? 'الدور' : 'Role' ?></th>
<th><?= $rtl ? 'الإجراءات' : 'Actions' ?></th>
<th>✅ <?= $rtl ? 'مؤكّد' : 'Confirmed' ?></th>
<th>❌ <?= $rtl ? 'مفقود' : 'Missing' ?></th>
<th>📍 <?= $rtl ? 'موقع' : 'Loc' ?></th>
<th>🔁 <?= $rtl ? 'عهدة' : 'Custody' ?></th>
<th>🔧 <?= $rtl ? 'تلف' : 'Damage' ?></th>
<th>🏁 <?= $rtl ? 'غرف' : 'Rooms' ?></th>
<th>⏱ <?= $rtl ? 'الوقت' : 'Time' ?></th>
<th><?= $rtl ? 'التقدم' : 'Progress' ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($out_data as $d): ?>
<tr>
<td class="name"><?= e($d['name']) ?></td>
<td><span class="role-badge role-<?= $d['role'] ?>"><?= $role_lbl[$d['role']] ?? $d['role'] ?></span></td>
<td class="num eng"><?= number_format($d['total']) ?></td>
<td class="num eng" style="color:#10b981;"><?= number_format($d['confirmed']) ?></td>
<td class="num eng" style="color:#dc2626;"><?= number_format($d['missing']) ?></td>
<td class="num eng" style="color:#3b82f6;"><?= number_format($d['location_changed']) ?></td>
<td class="num eng" style="color:#f59e0b;"><?= number_format($d['custody_changed']) ?></td>
<td class="num eng" style="color:#ef4444;"><?= number_format($d['condition_damaged']) ?></td>
<td class="num eng"><?= $d['rooms_done'] ?></td>
<td class="eng" style="font-size:11px;font-weight:800;"><?= sprintf('%dh %dm', floor($d['cumulative_sec']/3600), floor(($d['cumulative_sec']%3600)/60)) ?></td>
<td>
<span class="eng" style="font-weight:900;font-size:12px;"><?= $d['pct'] ?>%</span>
<span class="prog"><span class="fg" style="width:<?= $d['pct'] ?>%;background:<?= ($d['pct']>=80?'#10b981':($d['pct']>=50?'#f59e0b':'#dc2626')) ?>"></span></span>
</td>
</tr>
<?php endforeach; ?>
<?php if (empty($out_data)): ?>
<tr><td colspan="11" style="color:#94a3b8;padding:30px;"><?= $rtl ? 'لا بيانات بعد' : 'No data yet' ?></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
</body>
</html>
