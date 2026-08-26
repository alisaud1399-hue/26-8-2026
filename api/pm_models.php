<?php
/**
 * api/pm_models.php — Autocomplete models by manufacturer
 * GET: ?manufacturer_id=X&q=...  (returns id, model_number, asset_count)
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$manuf_id = (int)($_GET['manufacturer_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

if ($manuf_id < 1) { echo json_encode(['items' => []]); exit; }

$params = [$manuf_id];
$where = ['mm.manufacturer_id = ?', 'mm.is_active = 1'];

if ($q !== '') {
    $where[] = 'mm.model_number LIKE ?';
    $params[] = "%$q%";
}

$sql = "SELECT mm.id, mm.model_number,
        (SELECT COUNT(*) FROM assets a
         WHERE a.status='active' AND a.manufacturer_name = mfr.name AND a.model_number = mm.model_number) AS asset_count
        FROM manufacturer_models mm
        INNER JOIN manufacturers mfr ON mfr.id = mm.manufacturer_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (asset_count > 0) DESC, mm.model_number
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['asset_count'] = (int)$r['asset_count'];
}
echo json_encode(['items' => $rows], JSON_UNESCAPED_UNICODE);
