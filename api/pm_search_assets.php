<?php
/**
 * api/pm_search_assets.php — Asset search for PM Quick
 * GET: ?category_id=X | ?location_id=X | ?department_id=X | ?q=text | ?manufacturer_name=X&model_number=Y
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$where = ["a.status = 'active'"];
$params = [];

if (!empty($_GET['category_id'])) {
    // Lookup category name from id
    $cat = $pdo->prepare("SELECT name, level FROM item_categories WHERE id=?");
    $cat->execute([(int)$_GET['category_id']]);
    $row = $cat->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $where[] = "a.cat_level" . $row['level'] . " = ?";
        $params[] = $row['name'];
    }
}

if (!empty($_GET['location_id'])) {
    $loc = $pdo->prepare("SELECT name, level FROM item_locations WHERE id=?");
    $loc->execute([(int)$_GET['location_id']]);
    $row = $loc->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $col = $row['level'] === 1 ? 'a.loc_building' : ($row['level'] === 2 ? 'a.loc_floor' : 'a.loc_room');
        $where[] = "$col = ?";
        $params[] = $row['name'];
    }
}

if (!empty($_GET['department_id'])) {
    $where[] = "a.department_id = ?";
    $params[] = (int)$_GET['department_id'];
}

if (!empty($_GET['q'])) {
    $q = '%' . $_GET['q'] . '%';
    $where[] = "(a.tag_number LIKE ? OR a.asset_number LIKE ? OR a.serial_number LIKE ? OR a.description LIKE ? OR a.manufacturer_name LIKE ? OR a.model_number LIKE ? OR a.item_code LIKE ?)";
    array_push($params, $q, $q, $q, $q, $q, $q, $q);
}

if (!empty($_GET['manufacturer_name'])) {
    $where[] = "a.manufacturer_name = ?";
    $params[] = $_GET['manufacturer_name'];
}

if (!empty($_GET['model_number'])) {
    $where[] = "a.model_number = ?";
    $params[] = $_GET['model_number'];
}

$sql = "SELECT a.id, a.tag_number, a.asset_number, a.description, a.manufacturer_name,
               a.model_number, a.criticality_class, a.department_id, a.loc_building,
               a.loc_floor, a.loc_room, d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.id DESC
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
