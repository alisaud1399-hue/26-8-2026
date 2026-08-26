<?php
/**
 * api/asset_lookup.php — Auto-fill device info (tag → asset details)
 * Used by pm_schedules.php auto-fill
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

global $pdo;

// Support lookup by id (direct) OR by q (tag/asset number)
$id = (int)($_GET['id'] ?? 0);
$q = trim($_GET['q'] ?? '');

if ($id > 0) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.tag_number, a.asset_number, a.description, a.status, a.criticality_class,
               a.custodian_user_id, a.custodian_dept_id,
               u.full_name AS custodian_name,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN users u ON u.id = a.custodian_user_id
        LEFT JOIN departments d ON d.id = a.custodian_dept_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($asset) goto output;
    echo json_encode(['error' => 'not found']);
    exit;
}

if (mb_strlen($q) < 2) {
    echo json_encode(['error' => 'q must be ≥ 2 chars']);
    exit;
}

// Search by tag_number OR asset_number
$stmt = $pdo->prepare("
    SELECT a.id, a.tag_number, a.asset_number, a.description, a.status, a.criticality_class,
           a.custodian_user_id, a.custodian_dept_id,
           u.full_name AS custodian_name,
           d.name AS dept_name
    FROM assets a
    LEFT JOIN users u ON u.id = a.custodian_user_id
    LEFT JOIN departments d ON d.id = a.custodian_dept_id
    WHERE a.tag_number = ? OR a.asset_number = ?
    LIMIT 1
");
$stmt->execute([$q, $q]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
output:

if (!$asset) {
    // Try partial match
    $stmt = $pdo->prepare("
        SELECT a.id, a.tag_number, a.asset_number, a.description, a.status, a.criticality_class,
               a.custodian_user_id, a.custodian_dept_id,
               u.full_name AS custodian_name,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN users u ON u.id = a.custodian_user_id
        LEFT JOIN departments d ON d.id = a.custodian_dept_id
        WHERE a.tag_number LIKE ? OR a.asset_number LIKE ? OR a.description LIKE ?
        ORDER BY a.id LIMIT 1
    ");
    $stmt->execute(['%'.$q.'%', '%'.$q.'%', '%'.$q.'%']);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$asset) {
    echo json_encode(['error' => 'not found']);
    exit;
}

echo json_encode([
    'id' => (int)$asset['id'],
    'tag_number' => $asset['tag_number'],
    'asset_number' => $asset['asset_number'],
    'description' => $asset['description'],
    'status' => $asset['status'],
    'criticality_class' => $asset['criticality_class'],
    'custodian' => $asset['custodian_name'] ?: $asset['dept_name'],
]);
