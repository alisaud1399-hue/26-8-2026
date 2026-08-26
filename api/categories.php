<?php
/**
 * api/categories.php — جلب الفئات (مستوى واحد أو الأبناء)
 *
 * Parameters:
 *   level     (int) optional: 1 (top) | 2 | 3
 *   parent_id (int) optional: للأبناء
 *   asset_type (string) optional: فلتر حسب نوع الأصل
 *
 * Returns:
 *   { ok: true, categories: [{id, name, name_en, level, parent_id, segment}] }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$level      = (int)($_REQUEST['level'] ?? 0);
$parent_id  = (int)($_REQUEST['parent_id'] ?? 0);
$asset_type = trim($_REQUEST['asset_type'] ?? '');

try {
    $where = ['is_active = 1'];
    $params = [];

    if ($level > 0) {
        $where[] = 'level = ?';
        $params[] = $level;
    }
    if ($parent_id > 0) {
        $where[] = 'parent_id = ?';
        $params[] = $parent_id;
    }
    if ($asset_type !== '') {
        $where[] = '(asset_type = ? OR asset_type IS NULL)';
        $params[] = $asset_type;
    }

    $sql = "SELECT id, name, name_en, level, parent_id, segment, asset_type
            FROM item_categories
            WHERE " . implode(' AND ', $where) . "
            ORDER BY " . ($level > 0 ? "level, sort_order, name" : "level, sort_order, name");

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // لا swap — item_categories.name = Arabic (صحيح), name_en = English (صحيح)

    json_response([
        'ok'         => true,
        'level'      => $level ?: null,
        'parent_id'  => $parent_id ?: null,
        'count'      => count($rows),
        'categories' => $rows,
    ]);
} catch (Exception $e) {
    json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
}