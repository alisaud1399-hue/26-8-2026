<?php
/**
 * api/pm_categories.php — Categories for PM Quick (3-level tree)
 * GET: ?level=1 [&parent=X]
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$level = (int)($_GET['level'] ?? 1);
$parent = (int)($_GET['parent'] ?? 0);

if ($level === 1) {
    $sql = "SELECT c.id, c.name,
            (SELECT COUNT(*) FROM assets a
             WHERE a.status='active' AND a.cat_level1 = c.name) AS asset_count
            FROM item_categories c
            WHERE c.level=1
            ORDER BY c.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT c.id, c.name,
            (SELECT COUNT(*) FROM assets a
             WHERE a.status='active' AND a.cat_level1 = (
                 SELECT name FROM item_categories WHERE id=?
             ) AND a.cat_level2 = c.name) AS asset_count
            FROM item_categories c
            WHERE c.level=? AND c.parent_id=?
            ORDER BY c.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent, $level, $parent]);
}
echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
