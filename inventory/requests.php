<?php
/**
 * inventory/requests.php — شاشة طلبات الاستثناءات المستقلة
 * عرض ومعالجة جميع الطلبات من كل الجلسات
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index');

$rtl = is_rtl();
$me = (int)($_SESSION['user_id'] ?? 0);

// صلاحيات
$can_view   = can('inventory.validate', 'approve') || can('inventory.view', 'requests');
$can_process = can('inventory.validate', 'approve');
if (!$can_view) abort(403);

// فلتر الحالة
$f_status = $_GET['status'] ?? 'pending';
$f_type   = $_GET['type']   ?? '';
$f_session = (int)($_GET['session'] ?? 0);
$valid_status = ['pending','approved','rejected','all'];
if (!in_array($f_status, $valid_status)) $f_status = 'pending';

// ═══ جلب الطلبات ═══
$sql = "SELECT r.*,
        a.tag_number, a.description AS asset_desc, a.description_ar AS asset_desc_ar,
        il.name AS room_name, il.name_en AS room_name_en,
        s.title AS session_title, s.session_code,
        u.full_name AS requester_name,
        d.full_name AS decider_name
    FROM inventory_reaudit_requests r
    LEFT JOIN assets a ON a.id = r.asset_id
    LEFT JOIN item_locations il ON il.id = r.room_id
    LEFT JOIN inventory_sessions s ON s.id = r.session_id
    LEFT JOIN users u ON u.id = r.requested_by
    LEFT JOIN users d ON d.id = r.decided_by
    WHERE 1=1";

$params = [];
if ($f_status !== 'all') {
    $sql .= " AND r.status = ?";
    $params[] = $f_status;
}
if ($f_type !== '' && in_array($f_type, ['re_audit_device','re_audit_room','data_conflict'])) {
    $sql .= " AND r.request_type = ?";
    $params[] = $f_type;
}
if ($f_session > 0) {
    $sql .= " AND r.session_id = ?";
    $params[] = $f_session;
}
$sql .= " ORDER BY r.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ═══ الإحصائيات ═══
$stats_q = $pdo->query("SELECT status, COUNT(*) c FROM inventory_reaudit_requests GROUP BY status");
$stats = [];
foreach ($stats_q->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['status']] = (int)$r['c'];
$pending_total = $stats['pending'] ?? 0;
$approved_total = $stats['approved'] ?? 0;
$rejected_total = $stats['rejected'] ?? 0;

// ═══ الجلسات (للفلتر) ═══
$sessions = $pdo->query("SELECT id, session_code, title FROM inventory_sessions ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$page_title = $rtl ? 'طلبات الاستثناءات' : 'Exception Requests';
$active_nav = 'inventory.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--bg:#f1f5f9;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;--primary:#2563eb}
*{font-family:'Tajawal',sans-serif}
body{background:var(--bg)}
.wrap{max-width:1400px;margin:0 auto;padding:22px}
.hero{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:22px;padding:22px 28px;color:#fff;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}
.hero h1{font-size:21px;font-weight:900;margin:0}
.hero p{font-size:12.5px;color:#cbd5e1;margin:4px 0 0}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.stat{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:16px;display:flex;align-items:center;gap:14px}
.stat .ic{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat .v{font-size:22px;font-weight:900}.stat .l{font-size:11.5px;color:var(--muted);margin-top:2px;font-weight:700}
.filters{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.f-chip{padding:8px 16px;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-size:12.5px;font-weight:800;cursor:pointer;text-decoration:none;color:var(--text);transition:.15s}
.f-chip:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.06)}
.f-chip.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.f-chip i{margin-left:4px}
.f-sel{border:1.5px solid var(--border);border-radius:10px;padding:8px 14px;font-size:12.5px;font-family:inherit;font-weight:700;background:#fff;min-width:140px}
.req-card{background:#fff;border-radius:14px;padding:18px;margin-bottom:12px;transition:.2s}
.req-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.06)}
.req-card.pending{border-left:4px solid #f59e0b}
.req-card.approved{border-left:4px solid #16a34a}
.req-card.rejected{border-left:4px solid #ef4444}
.req-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800;white-space:nowrap}
.badge.device{background:#fef3c7;color:#92400e}.badge.room{background:#dbeafe;color:#1d4ed8}.badge.conflict{background:#fef2f2;color:#991b1b}
.badge.pending{background:#fef3c7;color:#92400e}.badge.approved{background:#dcfce7;color:#166534}.badge.rejected{background:#fef2f2;color:#991b1b}
.req-body{margin-top:10px;font-size:13px;color:#475569;line-height:1.7}
.req-meta{font-size:11.5px;color:#94a3b8;margin-top:8px;display:flex;gap:12px;flex-wrap:wrap}
.req-actions{display:flex;gap:8px;margin-top:12px}
.btn{border:none;padding:10px 18px;border-radius:10px;font-weight:800;font-size:12.5px;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;transition:.15s}
.btn-approve{background:#16a34a;color:#fff}.btn-approve:hover{background:#15803d}
.btn-reject{background:#fff;color:#dc2626;border:1.5px solid #fca5a5}.btn-reject:hover{background:#fef2f2}
.btn-back{background:#f1f5f9;color:#475569}.btn-back:hover{background:#e2e8f0}
.empty{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:40px;text-align:center;color:var(--muted);font-size:14px;font-weight:700}
.eng{font-family:'Inter',sans-serif}
@media(max-width:768px){.stats{grid-template-columns:1fr 1fr}.hero{flex-direction:column;text-align:center}}
</style>
</head>
<body>
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<!-- Hero -->
<section class="hero">
<div>
<h1><i class="fa-solid fa-clipboard-list"></i> <?= $page_title ?></h1>
<p><?= $rtl ? 'عرض ومعالجة جميع طلبات الاستثناء من كل الجلسات' : 'View and process all exception requests across sessions' ?></p>
</div>
<a class="btn btn-back" href="<?= BASE_URL ?>/inventory/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl ? 'الجرد' : 'Audit' ?></a>
</section>

<!-- Stats -->
<div class="stats">
<div class="stat"><div class="ic" style="background:#fef3c7;color:#f59e0b"><i class="fa-solid fa-clock"></i></div><div><div class="v"><?= number_format($pending_total) ?></div><div class="l"><?= $rtl ? 'معلّق' : 'Pending' ?></div></div></div>
<div class="stat"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-check-circle"></i></div><div><div class="v"><?= number_format($approved_total) ?></div><div class="l"><?= $rtl ? 'مقبول' : 'Approved' ?></div></div></div>
<div class="stat"><div class="ic" style="background:#fef2f2;color:#ef4444"><i class="fa-solid fa-xmark-circle"></i></div><div><div class="v"><?= number_format($rejected_total) ?></div><div class="l"><?= $rtl ? 'مرفوض' : 'Rejected' ?></div></div></div>
<div class="stat"><div class="ic" style="background:#eef2ff;color:#6366f1"><i class="fa-solid fa-list"></i></div><div><div class="v"><?= number_format(count($requests)) ?></div><div class="l"><?= $rtl ? 'النتائج' : 'Results' ?></div></div></div>
</div>

<!-- Filters -->
<div class="filters">
<span style="font-weight:800;font-size:13px;color:var(--muted);margin-left:8px"><i class="fa-solid fa-filter"></i> <?= $rtl ? 'فلتر:' : 'Filter:' ?></span>
<a class="f-chip <?= $f_status==='pending'?'active':'' ?>" href="?status=pending&type=<?= e($f_type) ?>&session=<?= $f_session ?>"><i class="fa-solid fa-clock"></i> <?= $rtl ? 'معلّق' : 'Pending' ?></a>
<a class="f-chip <?= $f_status==='approved'?'active':'' ?>" href="?status=approved&type=<?= e($f_type) ?>&session=<?= $f_session ?>"><i class="fa-solid fa-check"></i> <?= $rtl ? 'مقبول' : 'Approved' ?></a>
<a class="f-chip <?= $f_status==='rejected'?'active':'' ?>" href="?status=rejected&type=<?= e($f_type) ?>&session=<?= $f_session ?>"><i class="fa-solid fa-xmark"></i> <?= $rtl ? 'مرفوض' : 'Rejected' ?></a>
<a class="f-chip <?= $f_status==='all'?'active':'' ?>" href="?status=all&type=<?= e($f_type) ?>&session=<?= $f_session ?>"><i class="fa-solid fa-layer-group"></i> <?= $rtl ? 'الكل' : 'All' ?></a>
<span style="width:1px;height:24px;background:#e2e8f0;margin:0 4px"></span>
<a class="f-chip <?= $f_type==='re_audit_device'?'active':'' ?>" href="?status=<?= e($f_status) ?>&type=re_audit_device&session=<?= $f_session ?>"><i class="fa-solid fa-microchip"></i> <?= $rtl ? 'جهاز' : 'Device' ?></a>
<a class="f-chip <?= $f_type==='re_audit_room'?'active':'' ?>" href="?status=<?= e($f_status) ?>&type=re_audit_room&session=<?= $f_session ?>"><i class="fa-solid fa-door-open"></i> <?= $rtl ? 'غرفة' : 'Room' ?></a>
<a class="f-chip <?= $f_type==='data_conflict'?'active':'' ?>" href="?status=<?= e($f_status) ?>&type=data_conflict&session=<?= $f_session ?>"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl ? 'تضارب' : 'Conflict' ?></a>
<span style="width:1px;height:24px;background:#e2e8f0;margin:0 4px"></span>
<select class="f-sel" onchange="location.href='?status=<?= e($f_status) ?>&type=<?= e($f_type) ?>&session='+this.value">
<option value="0"><?= $rtl ? 'كل الجلسات' : 'All Sessions' ?></option>
<?php foreach ($sessions as $ss): ?>
<option value="<?= $ss['id'] ?>" <?= $f_session==$ss['id']?'selected':'' ?>><?= e($ss['session_code']) ?> — <?= e(truncate($ss['title'],30)) ?></option>
<?php endforeach; ?>
</select>
</div>

<!-- Results -->
<?php if (!$requests): ?>
<div class="empty"><i class="fa-solid fa-inbox" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4"></i><?= $rtl ? 'لا توجد طلبات تطابق الفلتر.' : 'No requests match the filter.' ?></div>
<?php else: ?>
<div id="reqList">
<?php foreach ($requests as $req):
    $type_badge = match($req['request_type']) {
        're_audit_room' => '<span class="badge room"><i class="fa-solid fa-door-open"></i> '.($rtl?'غرفة':'Room').'</span>',
        'data_conflict' => '<span class="badge conflict"><i class="fa-solid fa-triangle-exclamation"></i> '.($rtl?'تضارب':'Conflict').'</span>',
        default => '<span class="badge device"><i class="fa-solid fa-microchip"></i> '.($rtl?'جهاز':'Device').'</span>',
    };
    $status_badge = '<span class="badge '.e($req['status']).'">'.match($req['status']) {
        'pending' => '<i class="fa-solid fa-clock"></i> '.($rtl?'معلّق':'Pending'),
        'approved' => '<i class="fa-solid fa-check"></i> '.($rtl?'مقبول':'Approved'),
        'rejected' => '<i class="fa-solid fa-xmark"></i> '.($rtl?'مرفوض':'Rejected'),
    }.'</span>';

    // الهدف
    if ($req['request_type'] === 're_audit_room') {
        $target = $req['room_name_en'] ?: $req['room_name'] ?: ('#'.$req['room_id']);
        $target_desc = $req['session_code'] ? ($req['session_title'] ?: '') : '';
    } elseif ($req['request_type'] === 'data_conflict') {
        $field = $req['conflict_field'] === 'serial' ? ($rtl?'السيريال':'Serial') : ($rtl?'التاج':'Tag');
        $target = $field.': '.e($req['conflict_value'] ?? '');
        $target_desc = '';
    } else {
        $target = $req['tag_number'] ?: ('#'.$req['asset_id']);
        $target_desc = $rtl ? ($req['asset_desc_ar'] ?: ($req['asset_desc'] ?: '')) : ($req['asset_desc'] ?: ($req['asset_desc_ar'] ?: ''));
    }
?>
<div class="req-card <?= e($req['status']) ?>">
<div class="req-header">
<div style="flex:1;min-width:200px">
<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
<?= $type_badge ?>
<strong style="font-size:14px;color:var(--text)"><?= $target ?></strong>
<?= $status_badge ?>
</div>
<?php if ($target_desc): ?><div style="font-size:12px;color:var(--muted);margin-bottom:4px;"><?= e($target_desc) ?></div><?php endif; ?>
<div style="background:#f8fafc;border-radius:8px;padding:8px 10px;font-size:12.5px;color:#475569;"><i class="fa-solid fa-quote-right" style="color:#94a3b8;font-size:10px;"></i> <?= e($req['reason']) ?></div>
<div class="req-meta">
<span><i class="fa-regular fa-user"></i> <?= e($req['requester_name'] ?? '—') ?></span>
<span><i class="fa-regular fa-clock"></i> <?= e($req['created_at']) ?></span>
<span><i class="fa-solid fa-layer-group"></i> <?= e($req['session_code'] ?: '#'.$req['session_id']) ?></span>
<?php if ($req['decider_name']): ?>
<span><i class="fa-solid fa-gavel"></i> <?= e($req['decider_name']) ?></span>
<?php endif; ?>
</div>
</div>
<?php if ($req['status'] === 'pending' && $can_process): ?>
<div class="req-actions">
<?php if ($req['request_type'] === 're_audit_room'): ?>
<form method="post" action="<?= BASE_URL ?>/inventory/session.php?id=<?= $req['session_id'] ?>" onsubmit="return confirm('<?= $rtl?'الموافقة تفتح الغرفة — متأكد؟':'Approval opens the room — sure?' ?>')">
<?= csrf_input() ?>
<input type="hidden" name="request_id" value="<?= $req['id'] ?>">
<button type="submit" name="reaudit_decision" value="approve" class="btn btn-approve"><i class="fa-solid fa-check"></i> <?= $rtl?'موافقه':'Approve' ?></button>
</form>
<form method="post" action="<?= BASE_URL ?>/inventory/session.php?id=<?= $req['session_id'] ?>">
<?= csrf_input() ?>
<input type="hidden" name="request_id" value="<?= $req['id'] ?>">
<button type="submit" name="reaudit_decision" value="reject" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> <?= $rtl?'رفض':'Reject' ?></button>
</form>
<?php else: ?>
<form method="post" action="<?= BASE_URL ?>/inventory/session.php?id=<?= $req['session_id'] ?>" onsubmit="return confirm('<?= $rtl?'تأكيد المعالجة?':'Confirm?' ?>')">
<?= csrf_input() ?>
<input type="hidden" name="request_id" value="<?= $req['id'] ?>">
<button type="submit" name="reaudit_decision" value="approve" class="btn btn-approve"><i class="fa-solid fa-check"></i> <?= $rtl?'مقبول':'Approve' ?></button>
</form>
<form method="post" action="<?= BASE_URL ?>/inventory/session.php?id=<?= $req['session_id'] ?>">
<?= csrf_input() ?>
<input type="hidden" name="request_id" value="<?= $req['id'] ?>">
<button type="submit" name="reaudit_decision" value="reject" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> <?= $rtl?'رفض':'Reject' ?></button>
</form>
<?php endif; ?>
</div>
<?php elseif ($req['status'] === 'pending'): ?>
<span style="background:#fef3c7;color:#92400e;font-size:11.5px;font-weight:800;padding:6px 12px;border-radius:8px"><i class="fa-solid fa-eye"></i> <?= $rtl?'بانتظار المعالجة':'Awaiting processing' ?></span>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
</div>
<script>
function truncate(s,n){ return s.length>n ? s.substring(0,n)+'...' : s; }
</script>
</body>
</html>
