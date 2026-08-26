<?php
/**
 * inventory/api/suggest.php — اقتراحات تدريجية من جدول الأصول
 * للوحة manual input — مع احترام نطاق الجلسة
 *
 * GET ?q=text&session=N
 *
 * Returns: [{ id, tag_number, alternative_code, description, asset_type, loc_name, criticality_class }]
 * Limited to 10 results, scoped to session's defined assets
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$session_id = (int)($_GET['session'] ?? 0);

if (mb_strlen($q) < 1) json_response(['results' => []]);
if (!$session_id) json_response(['error' => 'no_session'], 400);

// تأكيد الجلسة
$ss = $pdo->prepare("SELECT scope_type, scope_value FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sess) json_response(['error' => 'session_not_found'], 404);

$vals = json_decode($sess['scope_value'] ?? '[]', true) ?: [];

// scope subquery
function build_scope_subq(PDO $pdo, string $type, array $vals): array {
    switch ($type) {
        case 'all':
            return ['', []];
        case 'department':
            $in = implode(',', array_fill(0, count($vals), '?'));
            return ["AND a.department_id IN ($in)", $vals];
        case 'asset_type':
            $in = implode(',', array_fill(0, count($vals), '?'));
            return ["AND a.asset_type IN ($in)", array_values($vals)];
        case 'building':
            $in = implode(',', array_fill(0, count($vals), '?'));
            $sq = "AND (a.location_id IN ($in)
                OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in))
                OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in))))";
            return [$sq, array_merge($vals, $vals, $vals)];
        case 'custom':
            $in = implode(',', array_fill(0, count($vals), '?'));
            return ["AND a.id IN ($in)", array_map('intval', $vals)];
    }
    return ['', []];
}
[$scope_sql, $scope_params] = build_scope_subq($pdo, $sess['scope_type'], $vals);

// LIKE على tag, alt_code, description — الأولوية للأطول تطابقاً
$like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
$sql = "
    SELECT a.id, a.tag_number, a.alternative_code, a.description, a.asset_type, a.criticality_class,
           loc.name AS loc_name, d.name AS dept_name
    FROM assets a
    LEFT JOIN item_locations loc ON loc.id = a.location_id
    LEFT JOIN departments     d   ON d.id  = a.department_id
    WHERE a.status = 'active'
      $scope_sql
      AND (
        a.tag_number LIKE ?
        OR a.alternative_code LIKE ?
        OR a.description LIKE ?
        OR a.serial_number LIKE ?
      )
    ORDER BY
      CASE WHEN a.tag_number = ? THEN 1
           WHEN a.tag_number LIKE ? THEN 2
           WHEN a.alternative_code = ? THEN 3
           WHEN a.alternative_code LIKE ? THEN 4
           WHEN a.description LIKE ? THEN 5
           ELSE 6
      END,
      a.tag_number
    LIMIT 10
";
$params = array_merge(
    $scope_params,
    [$like, $like, $like, $like],
    [$q, "$q%", $q, "$q%", "$q%"]
);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

json_response([
    'results' => array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'tag'       => $r['tag_number'],
        'alt'       => $r['alternative_code'] ?? '',
        'desc'      => mb_substr($r['description'] ?? '', 0, 60),
        'type'      => $r['asset_type'],
        'crit'      => $r['criticality_class'],
        'loc'       => $r['loc_name'] ?? '',
        'dept'      => $r['dept_name'] ?? '',
    ], $rows),
    'count' => count($rows),
]);