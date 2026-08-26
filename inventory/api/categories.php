<?php
/**
 * inventory/api/categories.php — قائمة التصنيفات النشطة (لبطاقة التسجيل السريع)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$st = $pdo->query("SELECT id, name, name_en, parent_id, level FROM item_categories WHERE is_active=1 ORDER BY sort_order, name");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
json_response(['ok' => true, 'categories' => $rows]);
