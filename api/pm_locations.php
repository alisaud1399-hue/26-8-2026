<?php
/**
 * api/pm_locations.php — Locations for PM Quick (3-level tree)
 * GET: ?level=1|2|3 [&parent=X]
 * Uses location_type: building=1, floor=2, room=3
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$level = (int)($_GET['level'] ?? 1);
$parent = (int)($_GET['parent'] ?? 0);

$type_map = [1 => 'building', 2 => 'floor', 3 => 'room'];
$col_map = [1 => 'a.loc_building', 2 => 'a.loc_floor', 3 => 'a.loc_room'];

if (!isset($type_map[$level])) {
    echo json_encode(['items' => [], 'error' => 'Invalid level']);
    exit;
}

$type = $type_map[$level];
$col = $col_map[$level];

if ($level === 1) {
    $sql = "SELECT l.id, l.name,
            (SELECT COUNT(*) FROM assets a
             WHERE a.status='active' AND a.loc_building = l.name) AS asset_count
            FROM item_locations l
            WHERE l.location_type='building' AND l.is_active=1
            ORDER BY l.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT l.id, l.name,
            (SELECT COUNT(*) FROM assets a
             WHERE a.status='active' AND $col = l.name) AS asset_count
            FROM item_locations l
            WHERE l.location_type=? AND l.parent_id=? AND l.is_active=1
            ORDER BY l.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type, $parent]);
}
echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
