<?php
/**
 * api/cp_search.php — بحث سريع للـ Command Palette
 * يرجع JSON بأفضل 8 أصول مطابقة (تاج/سيريال/وصف/مصنع/موديل)
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { echo json_encode([]); exit; }

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$st = $pdo->prepare("
    SELECT id, tag_number, serial_number, description, description_ar,
           criticality_class, health_score
    FROM assets
    WHERE tag_number LIKE :q OR serial_number LIKE :q
       OR description LIKE :q OR description_ar LIKE :q
       OR manufacturer_name LIKE :q OR model_number LIKE :q
    ORDER BY (tag_number LIKE :q) DESC, id DESC
    LIMIT 8
");
$st->execute(['q' => $like]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));