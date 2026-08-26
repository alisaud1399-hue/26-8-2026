<?php
/**
 * api/pm_manufacturers.php — Autocomplete manufacturers from manufacturers table
 * GET: ?q=...   (returns id, name, name_en, country, model_count)
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$params = [];
$where = ['mfr.is_active = 1'];

if ($q !== '') {
    $where[] = '(mfr.name LIKE ? OR mfr.name_en LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$sql = "SELECT mfr.id, mfr.name, mfr.name_en, mfr.country, mfr.website,
        (SELECT COUNT(*) FROM manufacturer_models mm WHERE mm.manufacturer_id = mfr.id AND mm.is_active = 1) AS model_count,
        (SELECT COUNT(*) FROM assets a WHERE a.status='active' AND a.manufacturer_name = mfr.name) AS asset_count
        FROM manufacturers mfr
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (asset_count > 0) DESC, mfr.name_en
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast IDs and counts to int
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['model_count'] = (int)$r['model_count'];
    $r['asset_count'] = (int)$r['asset_count'];
    unset($r['website']); // Don't expose
}
echo json_encode(['items' => $rows], JSON_UNESCAPED_UNICODE);
