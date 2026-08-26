<?php
/**
 * api/search_assets.php — بحث في الأصول للجان
 */
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q     = trim($_GET['q']     ?? '');
$maint = trim($_GET['maint'] ?? ''); // medical|it|general
$limit = min(20, (int)($_GET['limit'] ?? 15));

$where  = ["a.status = 'active'"];
$params = [];

if ($q !== '') {
    $like    = '%' . $q . '%';
    $where[] = '(a.description LIKE ? OR a.tag_number LIKE ? OR a.serial_number LIKE ? OR a.manufacturer_name LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like]);
}

if ($maint === 'medical')  { $where[] = 'a.cat_seg1 IN (18,19)'; }
elseif ($maint === 'it')   { $where[] = 'a.cat_seg1 = 13'; }
elseif ($maint === 'general') { $where[] = 'a.cat_seg1 NOT IN (18,19,13)'; }

$wh = implode(' AND ', $where);
$st = $pdo->prepare("
    SELECT a.id, a.description, a.tag_number, a.serial_number,
           a.manufacturer_name, a.model_number, a.cat_seg1,
           a.loc_building, a.loc_floor
    FROM assets a
    WHERE $wh
    ORDER BY a.description
    LIMIT $limit
");
$st->execute($params);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);