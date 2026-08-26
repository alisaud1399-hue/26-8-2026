<?php
/**
 * inventory/api/progress.php — إحصاءات حية للجلسة (JSON)
 * GET ?id=N — يحدّث شريط التغطية بدون reload الصفحة
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') json_response(['error'=>'method'], 405);

$id = (int)($_GET['id'] ?? 0);
if (!$id) json_response(['error'=>'no_session']);

$ss = $pdo->prepare("SELECT scope_type, scope_value, status FROM inventory_sessions WHERE id=?");
$ss->execute([$id]);
$sess = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sess) json_response(['error'=>'session_not_found']);

$vals = json_decode($sess['scope_value'] ?? '[]', true) ?: [];

// Expected
function make_where(string $type, array $vals): array {
    $where = ["a.status='active'"];
    $params = [];
    switch ($type) {
        case 'all':         break;
        case 'department':
            $in = implode(',', array_fill(0, count($vals), '?'));
            $where[] = "a.department_id IN ($in)"; $params = $vals; break;
        case 'asset_type':
            $in = implode(',', array_fill(0, count($vals), '?'));
            $where[] = "a.asset_type IN ($in)"; $params = $vals; break;
        case 'building':
            $in = implode(',', array_fill(0, count($vals), '?'));
            $where[] = "(a.location_id IN ($in)
                OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in))
                OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in))))";
            $params = array_merge($vals,$vals,$vals); break;
        case 'custom':
            $in = implode(',', array_fill(0, count($vals), '?'));
            $where[] = "a.id IN ($in)"; $params = array_map('intval', $vals); break;
    }
    return [implode(' AND ', $where), $params];
}
[$where_sql, $params] = make_where($sess['scope_type'], $vals);

$st = $pdo->prepare("SELECT COUNT(*) FROM assets a WHERE $where_sql");
$st->execute($params);
$expected = (int)$st->fetchColumn();

$act = $pdo->prepare("
    SELECT
      SUM(action IN ('confirmed','location_changed','custody_changed')) AS found,
      SUM(action='condition_damaged') AS damaged,
      SUM(action='missing') AS missing,
      SUM(action LIKE 'missing_%') AS missing_total,
      SUM(action='surplus' OR action='surplus_registered') AS surplus,
      SUM(action='location_changed') AS moved,
      SUM(action='custody_changed') AS cust_chg,
      COUNT(*) AS total_scans
    FROM inventory_audits WHERE session_id=?
");
$act->execute([$id]);
$r = $act->fetch(PDO::FETCH_ASSOC);

$found = (int)($r['found'] ?? 0);
$missing = (int)($r['missing'] ?? 0);
$missing_total = (int)($r['missing_total'] ?? 0);
$coverage = $expected > 0 ? round((($found + $missing) * 100) / max(1,$expected)) : 0;

$last_scan = $pdo->prepare("SELECT MAX(audited_at), audited_by FROM inventory_audits WHERE session_id=? GROUP BY audited_by ORDER BY 1 DESC LIMIT 1");
$last_scan->execute([$id]);
$ls = $last_scan->fetch(PDO::FETCH_ASSOC);

json_response([
    'status'      => $sess['status'],
    'expected'    => $expected,
    'found'       => $found,
    'damaged'     => (int)$r['damaged'],
    'missing'     => $missing,
    'missing_total' => $missing_total,
    'surplus'     => (int)$r['surplus'],
    'moved'       => (int)$r['moved'],
    'cust_chg'    => (int)$r['cust_chg'],
    'total_scans' => (int)$r['total_scans'],
    'pending'     => max(0, $expected - $found - $missing),
    'coverage'    => $coverage,
    'last_scan_at'=> $ls['MAX(audited_at)'] ?? null,
]);
