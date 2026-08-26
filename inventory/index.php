<?php
/**
 * inventory/index.php — قائمة جلسات الجرد الشامل
 * لوحة متابعة مع إحصاءات + قائمة جلسات + زر "جلسة جديدة"
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index');

$rtl  = is_rtl();
$lang = current_lang();
$can_create = can('inventory.create', 'create');
$can_manage = can('inventory.create', 'manage');
$can_manage = can('inventory.create', 'manage');

/* ═══ نطاق الإحصائيات: المميزون يرون أرقام الكل، والعضو يرى أرقام جلساته فقط ═══ */
$inv_see_all_stats = can_see_all() || can('inventory.create', 'manage');
if ($inv_see_all_stats) {
    $inv_sess_scope = '1=1';
    $inv_aud_scope  = '1=1';
} else {
    $inv_uid_s      = (int)current_user()['id'];
    $inv_sess_scope = "id IN (SELECT session_id FROM inventory_session_members WHERE user_id = {$inv_uid_s})";
    $inv_aud_scope  = "session_id IN (SELECT session_id FROM inventory_session_members WHERE user_id = {$inv_uid_s})";
}
// ── فلاتر ──────────────────────────────────────────────────────
$f_status = $_GET['status'] ?? '';
$f_scope  = $_GET['scope']  ?? '';
$q        = trim($_GET['q']  ?? '');

// ── إحصاءات عامة (مفلترة حسب عضوية المستخدم) ─────────────────
$stats = [
'total_sessions'     => (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE {$inv_sess_scope}")->fetchColumn(),
'active_sessions'    => (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE {$inv_sess_scope} AND status='active'")->fetchColumn(),
'planning_sessions'  => (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE {$inv_sess_scope} AND status='planning'")->fetchColumn(),
'completed_sessions' => (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE {$inv_sess_scope} AND status='completed'")->fetchColumn(),
'total_audits'       => (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE {$inv_aud_scope}")->fetchColumn(),
'today_audits'       => (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE {$inv_aud_scope} AND DATE(audited_at)=CURDATE()")->fetchColumn(),
'surplus_found'      => (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE {$inv_aud_scope} AND action='surplus'")->fetchColumn(),
'missing_found'      => (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE {$inv_aud_scope} AND action='missing'")->fetchColumn(),
];

// إحصاء الأصول التي تم التحقق منها (verified_status != 'لم يتم التحقق بعد')
$verified = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE verified_status != 'لم يتم التحقق بعد'")->fetchColumn();
$total_assets = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$verified_pct = $total_assets ? round($verified * 100 / $total_assets) : 0;

// ── بناء WHERE للجلسات ────────────────────────────────────────
$where = ['1=1'];
$params = [];
if ($f_status && in_array($f_status, ['planning','active','review','completed','cancelled'])) {
    $where[] = 's.status = ?';
    $params[] = $f_status;
}
if ($f_scope && in_array($f_scope, ['all','department','asset_type','building','custom'])) {
    $where[] = 's.scope_type = ?';
    $params[] = $f_scope;
}
if ($q !== '') {
    $where[] = '(s.session_code LIKE ? OR s.title LIKE ? OR s.notes LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
/* ═══ الصلاحيات الذكية: غير الأعضاء لا يرون البطاقات ═══ */
$inv_uid = (int)current_user()['id'];
$inv_see_all = can_see_all() || can('inventory.create', 'manage');
if (!$inv_see_all) {
    // المستخدم العادي يرى فقط الجلسات التي هو عضو فيها
    $where[] = 'EXISTS (SELECT 1 FROM inventory_session_members inv_sm WHERE inv_sm.session_id = s.id AND inv_sm.user_id = ?)';
    $params[] = $inv_uid;
}
$where_sql = implode(' AND ', $where);

// ── جلب الجلسات ───────────────────────────────────────────────
$sql = "
    SELECT s.*,
           u.full_name AS creator_name,
           (SELECT COUNT(*) FROM inventory_session_members m WHERE m.session_id = s.id) AS member_count,
           (SELECT COUNT(*) FROM inventory_audits a WHERE a.session_id = s.id) AS audit_count,
           (SELECT COUNT(DISTINCT audited_by) FROM inventory_audits a WHERE a.session_id = s.id) AS active_members
    FROM inventory_sessions s
    LEFT JOIN users u ON u.id = s.created_by
    WHERE $where_sql
    ORDER BY FIELD(s.status,'active','review','planning','completed','cancelled'), s.start_date DESC, s.id DESC
    LIMIT 100
";
$st = $pdo->prepare($sql);
$st->execute($params);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

// ── جلب الفرق لكل جلسة ──
if ($sessions) {
    $sids = array_map(fn($s) => (int)$s['id'], $sessions);
    $placeholders = implode(',', array_fill(0, count($sids), '?'));
    $teams_stmt = $pdo->prepare("
        SELECT t.id, t.session_id, t.name AS team_name,
               GROUP_CONCAT(u.full_name SEPARATOR '، ') AS member_names,
               COUNT(tm.user_id) AS member_count
        FROM inventory_session_teams t
        LEFT JOIN inventory_session_team_members tm ON tm.team_id = t.id
        LEFT JOIN users u ON u.id = tm.user_id
        WHERE t.session_id IN ($placeholders)
        GROUP BY t.id, t.session_id, t.name
        ORDER BY t.id
    ");
    $teams_stmt->execute($sids);
    $teams_by_session = [];
    foreach ($teams_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teams_by_session[(int)$row['session_id']][] = $row;
    }
}

$page_title = $rtl ? 'الجرد الشامل للأصول' : 'Comprehensive Asset Inventory';
$active_nav = 'inventory.index';
$flash_msgs = get_flash();

// ── بطاقة ألوان الحالات ───────────────────────────────────────
$STATUS_META = [
    'planning'  => ['ar' => 'تحت التخطيط',  'en' => 'Planning',   'color' => '#64748b', 'icon' => 'fa-pen-ruler'],
    'active'    => ['ar' => 'نشطة الآن',     'en' => 'Active',     'color' => '#10b981', 'icon' => 'fa-circle-play'],
    'review'    => ['ar' => 'قيد المراجعة',  'en' => 'Under Review','color' => '#f59e0b', 'icon' => 'fa-magnifying-glass'],
    'completed' => ['ar' => 'مكتملة',        'en' => 'Completed',  'color' => '#2563eb', 'icon' => 'fa-circle-check'],
    'cancelled' => ['ar' => 'ملغاة',         'en' => 'Cancelled',  'color' => '#dc2626', 'icon' => 'fa-circle-xmark'],
];

$SCOPE_META = [
    'all'         => $rtl ? 'شامل لكل الأصول' : 'All assets',
    'department'  => $rtl ? 'حسب الإدارة' : 'By department',
    'asset_type'  => $rtl ? 'حسب نوع الأصل' : 'By asset type',
    'building'    => $rtl ? 'حسب المبنى' : 'By building',
    'custom'      => $rtl ? 'نطاق مخصص' : 'Custom scope',
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
body { background:var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width:1500px; margin:0 auto; padding:22px; }
.h-banner { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:22px; padding:22px 28px; color:#fff; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.h-banner h1 { font-size:19px; font-weight:900; margin:0; display:flex; align-items:center; gap:10px; }
.h-banner h1 i { color:#fbbf24; }
.h-banner p { font-size:12.5px; color:#cbd5e1; margin:6px 0 0; }
.btn-new { background:linear-gradient(135deg,#10b981,#059669); color:#fff; border:none; padding:11px 22px; border-radius:12px; font-family:'Tajawal'; font-size:13px; font-weight:900; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(16,185,129,.3); transition:.2s; }
.btn-new:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(16,185,129,.4); }

.kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px; }
@media(max-width:900px){ .kpis{ grid-template-columns:repeat(2,1fr); } }
.kpi { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px 18px; }
.kpi .v { font-size:24px; font-weight:900; color:var(--text); }
.kpi .v.eng { font-family:'Inter'; }
.kpi .l { font-size:11.5px; font-weight:800; color:var(--muted); margin-top:4px; }
.kpi .s { font-size:10.5px; color:#94a3b8; margin-top:2px; }
.kpi-icon { float:left; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; margin-left:12px; }
.kpi-icon.green { background:#d1fae5; color:#10b981; }
.kpi-icon.amber { background:#fef3c7; color:#f59e0b; }
.kpi-icon.blue  { background:#dbeafe; color:#2563eb; }
.kpi-icon.red   { background:#fee2e2; color:#dc2626; }
.kpi-icon.purple{ background:#ede9fe; color:#7c3aed; }

.progress-card { background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1px solid #bfdbfe; border-radius:16px; padding:18px 20px; margin-bottom:18px; display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
.progress-card .label { font-size:12px; font-weight:900; color:#1e40af; flex:1; min-width:200px; }
.progress-card .pct { font-size:32px; font-weight:900; color:#1d4ed8; font-family:'Inter'; }
.progress-card .desc { font-size:11.5px; color:#475569; font-weight:700; }
.progress-bar-bg { flex:2; min-width:300px; height:14px; background:#fff; border-radius:99px; overflow:hidden; border:1px solid #bfdbfe; }
.progress-bar-fg { height:100%; background:linear-gradient(90deg,#2563eb,#7c3aed); transition:width .5s; }

.filters { background:var(--card); border-radius:16px; border:1px solid var(--border); padding:14px 18px; margin-bottom:16px; display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
.filters label { font-size:10.5px; font-weight:800; color:var(--muted); display:block; margin-bottom:4px; }
.filters input, .filters select { border:1.5px solid var(--border); border-radius:9px; padding:8px 10px; font-family:'Tajawal'; font-size:12.5px; background:#fff; min-width:140px; }
.btn-f { background:#0f172a; color:#fff; border:none; border-radius:9px; padding:9px 18px; font-family:'Tajawal'; font-size:12.5px; font-weight:800; cursor:pointer; }
.btn-clear { font-size:11.5px; font-weight:800; color:#dc2626; text-decoration:none; padding:9px 4px; }

.sessions-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:14px; }
.session-card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:18px; transition:.2s; cursor:pointer; position:relative; overflow:hidden; }
.session-card:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,.06); border-color:#cbd5e1; }
.session-card::before { content:''; position:absolute; top:0; right:0; width:4px; height:100%; background:var(--st-color); }
.sc-head { display:flex; justify-content:space-between; align-items:start; gap:10px; margin-bottom:10px; }
.sc-code { font-family:'Inter'; font-size:12.5px; font-weight:900; color:#0f172a; background:#f1f5f9; padding:3px 9px; border-radius:6px; }
.sc-status { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:900; padding:3px 10px; border-radius:99px; color:#fff; }
.sc-title { font-size:14px; font-weight:900; color:#0f172a; margin:6px 0 4px; line-height:1.5; }
.sc-meta { font-size:11px; color:#64748b; font-weight:700; display:flex; flex-wrap:wrap; gap:8px 14px; margin:8px 0; }
.sc-meta i { color:#94a3b8; margin-left:4px; }
.sc-progress { background:#f8fafc; border-radius:8px; height:6px; overflow:hidden; margin-top:10px; }
.sc-progress-fg { height:100%; background:linear-gradient(90deg,#10b981,#059669); }
.sc-footer { display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #e2e8f0; font-size:11px; color:#64748b; font-weight:700; }
.sc-actions { display:flex; gap:6px; }
.sc-btn { background:#f1f5f9; border:1px solid #e2e8f0; color:#475569; padding:5px 11px; border-radius:7px; font-family:'Tajawal'; font-size:10.5px; font-weight:800; text-decoration:none; cursor:pointer; }
.sc-btn:hover { background:#e2e8f0; }
.sc-btn.primary { background:#2563eb; color:#fff; border-color:#2563eb; }
.sc-btn.primary:hover { background:#1d4ed8; }
.empty-state { text-align:center; padding:60px 20px; color:#94a3b8; background:var(--card); border-radius:16px; border:1px dashed #cbd5e1; }
.empty-state i { font-size:48px; margin-bottom:12px; color:#cbd5e1; }
@media (max-width: 768px) {
    .wrap { padding: 12px !important; }
    .h-banner { flex-direction: column; align-items: flex-start; padding: 16px !important; border-radius: 16px !important; }
    .h-banner h1 { font-size: 16px !important; }
    .btn-new { width: 100%; justify-content: center; margin-top: 10px; }
    
    .kpis { grid-template-columns: repeat(2, 1fr) !important; gap: 8px !important; }
    .kpi { padding: 12px !important; border-radius: 12px !important; }
    .kpi .v { font-size: 20px !important; }
    .kpi-icon { width: 32px !important; height: 32px !important; font-size: 14px !important; }

    .progress-card { flex-direction: column; align-items: flex-start !important; gap: 10px !important; }
    .progress-bar-bg { width: 100%; min-width: 100% !important; }

    .filters { flex-direction: column; align-items: stretch !important; gap: 8px !important; }
    .filters input, .filters select { width: 100%; min-width: 100% !important; }
    .btn-f { width: 100%; justify-content: center; }

    .sessions-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
    .session-card { padding: 14px !important; }
    .sc-title { font-size: 13.5px !important; }
    .sc-meta { font-size: 10.5px !important; gap: 6px 10px !important; }
    .sc-footer { flex-direction: column; gap: 10px; align-items: flex-start !important; }
    .sc-actions { width: 100%; }
    .sc-btn { flex: 1; text-align: center; justify-content: center; padding: 8px !important; font-size: 11.5px !important; }
}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm):
$fmeta = [
    'success' => ['bg'=>'#ecfdf5','bd'=>'#10b981','tx'=>'#065f46','ic'=>'fa-circle-check'],
    'warning' => ['bg'=>'#fffbeb','bd'=>'#f59e0b','tx'=>'#92400e','ic'=>'fa-triangle-exclamation'],
    'error'   => ['bg'=>'#fef2f2','bd'=>'#ef4444','tx'=>'#991b1b','ic'=>'fa-circle-xmark'],
][$fm['type']] ?? ['bg'=>'#eff6ff','bd'=>'#3b82f6','tx'=>'#1e40af','ic'=>'fa-circle-info'];
?>
<div style="background:<?= $fmeta['bg'] ?>;border:1.5px solid <?= $fmeta['bd'] ?>;border-right:6px solid <?= $fmeta['bd'] ?>;color:<?= $fmeta['tx'] ?>;border-radius:12px;padding:16px 18px;margin-bottom:14px;font-weight:800;font-size:14px;display:flex;align-items:center;gap:12px;box-shadow:0 3px 10px rgba(0,0,0,.05)">
<i class="fa-solid <?= $fmeta['ic'] ?>" style="font-size:20px"></i>
<span><?= e($fm['message']) ?></span>
</div>
<?php endforeach; ?>

<div class="h-banner">
    <div>
        <h1><i class="fa-solid fa-clipboard-check"></i> <?= e($page_title) ?></h1>
        <p><?= $rtl ? 'لإدارة جولات التدقيق الميداني للأصول — مع تعبئة تلقائية لـ verified_status' : 'Manage field audit rounds — auto-fills verified_status' ?></p>
    </div>
    <?php if ($can_create): ?>
    <a class="btn-new" href="<?= BASE_URL ?>/inventory/create.php"><i class="fa-solid fa-plus"></i> <?= $rtl ? 'جلسة جديدة' : 'New Session' ?></a>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi">
        <div class="kpi-icon blue"><i class="fa-solid fa-clipboard-list"></i></div>
        <div class="v eng"><?= $stats['total_sessions'] ?></div>
        <div class="l"><?= $rtl ? 'إجمالي الجلسات' : 'Total Sessions' ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-icon green"><i class="fa-solid fa-circle-play"></i></div>
        <div class="v eng"><?= $stats['active_sessions'] ?></div>
        <div class="l"><?= $rtl ? 'جلسة نشطة الآن' : 'Active Now' ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-icon purple"><i class="fa-solid fa-qrcode"></i></div>
        <div class="v eng"><?= number_format($stats['total_audits']) ?></div>
        <div class="l"><?= $rtl ? 'إجمالي عمليات الفحص' : 'Total Audits' ?></div>
        <div class="s eng"><?= $stats['today_audits'] ?> <?= $rtl ? 'اليوم' : 'today' ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="v eng"><?= $stats['surplus_found'] + $stats['missing_found'] ?></div>
        <div class="l"><?= $rtl ? 'فروقات مكتشفة' : 'Discrepancies' ?></div>
        <div class="s eng"><?= $stats['surplus_found'] ?> + <?= $stats['missing_found'] ?></div>
    </div>
</div>

<!-- Overall verification progress -->
<div class="progress-card">
    <div class="label">
        <?= $rtl ? 'تقدم التحقق الإجمالي على مستوى المستشفى' : 'Hospital-wide verification progress' ?>
        <div class="desc"><?= $rtl ? 'بناءً على verified_status في جدول الأصول' : 'Based on verified_status field' ?></div>
    </div>
    <div class="progress-bar-bg">
        <div class="progress-bar-fg" style="width:<?= $verified_pct ?>%"></div>
    </div>
    <div class="pct"><?= $verified_pct ?>%</div>
    <div class="desc eng"><?= number_format($verified) ?> / <?= number_format($total_assets) ?></div>
</div>

<!-- Filters -->
<form class="filters" method="GET">
    <div>
        <label><?= $rtl ? 'بحث' : 'Search' ?></label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= $rtl ? 'رمز الجلسة أو العنوان' : 'Session code or title' ?>">
    </div>
    <div>
        <label><?= $rtl ? 'الحالة' : 'Status' ?></label>
        <select name="status">
            <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
            <?php foreach ($STATUS_META as $k => $m): ?>
            <option value="<?= e($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= $rtl ? $m['ar'] : $m['en'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label><?= $rtl ? 'النطاق' : 'Scope' ?></label>
        <select name="scope">
            <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
            <?php foreach ($SCOPE_META as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $f_scope === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn-f" type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl ? 'تطبيق' : 'Apply' ?></button>
    <a href="index.php" class="btn-clear"><?= $rtl ? 'إلغاء التصفية' : 'Clear' ?></a>
</form>

<!-- Sessions -->
<?php if (!$sessions): ?>
<div class="empty-state">
    <i class="fa-solid fa-clipboard-list"></i>
    <div style="font-size:15px;font-weight:900;color:#475569;margin-bottom:6px"><?= $rtl ? 'لا توجد جلسات جرد' : 'No inventory sessions yet' ?></div>
    <div style="font-size:12px"><?= $rtl ? 'ابدأ بإنشاء جلسة جديدة لتدقيق الأصول ميدانياً' : 'Create your first session to start field audits' ?></div>
    <?php if ($can_create): ?>
    <a class="btn-new" href="<?= BASE_URL ?>/inventory/create.php" style="margin-top:14px">
        <i class="fa-solid fa-plus"></i> <?= $rtl ? 'جلسة جديدة' : 'New Session' ?>
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="sessions-grid">
    <?php foreach ($sessions as $s):
        $sm = $STATUS_META[$s['status']] ?? $STATUS_META['planning'];
        $sc = $SCOPE_META[$s['scope_type']] ?? $s['scope_type'];
        $start = $s['start_date'] ? date('Y-m-d', strtotime($s['start_date'])) : '';
        $end = $s['end_date'] ? date('Y-m-d', strtotime($s['end_date'])) : ($rtl ? 'مفتوحة' : 'Open');
    ?>
    <div class="session-card" style="--st-color: <?= $sm['color'] ?>" onclick="if(event.target.tagName==='A'||event.target.tagName==='BUTTON')return;window.location='<?= BASE_URL ?>/inventory/session.php?id=<?= (int)$s['id'] ?>'">
        <div class="sc-head">
            <span class="sc-code"><?= e($s['session_code']) ?></span>
            <span class="sc-status" style="background:<?= $sm['color'] ?>"><i class="fa-solid <?= $sm['icon'] ?>"></i> <?= $rtl ? $sm['ar'] : $sm['en'] ?></span>
        </div>
        <div class="sc-title"><?= e($s['title']) ?></div>
        <div class="sc-meta">
            <span><i class="fa-solid fa-layer-group"></i><?= $sc ?></span>
            <span><i class="fa-solid fa-calendar"></i><?= $start ?> ← <?= $end ?></span>
            <span><i class="fa-solid fa-users"></i><?= (int)$s['member_count'] ?> <?= $rtl ? 'عضو' : 'members' ?></span>
            <span><i class="fa-solid fa-user"></i><?= e($s['creator_name'] ?? '—') ?></span>
        </div>
        <?php
        $s_teams = $teams_by_session[(int)$s['id']] ?? [];
        if ($s_teams):
        ?>
        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
            <?php foreach ($s_teams as $t): ?>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:5px 10px; font-size:11px; font-weight:700; color:#1e40af; display:inline-flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-people-group" style="font-size:10px; color:#6366f1;"></i>
                <?= e($t['team_name']) ?>
                <span style="background:#dbeafe; color:#1d4ed8; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:800;"><?= (int)$t['member_count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="sc-progress"><div class="sc-progress-fg" style="width:<?= min(100, $s['audit_count'] * 2) ?>%"></div></div>
        <div class="sc-footer">
            <span><i class="fa-solid fa-qrcode"></i> <?= (int)$s['audit_count'] ?> <?= $rtl ? 'عملية فحص' : 'audits' ?></span>
            <div class="sc-actions" onclick="event.stopPropagation()">
                <?php if ($can_manage && $s['status'] === 'planning'): ?>
                <a class="sc-btn primary" href="<?= BASE_URL ?>/inventory/create.php?id=<?= (int)$s['id'] ?>"><i class="fa-solid fa-pen"></i> <?= $rtl ? 'تعديل' : 'Edit' ?></a>
                <?php endif; ?>
                <a class="sc-btn" href="<?= BASE_URL ?>/inventory/session.php?id=<?= (int)$s['id'] ?>"><i class="fa-solid fa-eye"></i> <?= $rtl ? 'تفاصيل' : 'View' ?></a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
</div>
</body>
</html>