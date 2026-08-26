<?php
/**
 * api/departments.php — قائمة الإدارات
 *
 * GET (no params): يرجع كل الإدارات النشطة
 *   optional q: فلتر بالاسم
 *
 * Returns:
 *   { ok, departments: [{id, name, name_en, parent_id}] }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim($_REQUEST['q'] ?? '');

try {
    if ($q !== '') {
        $st = $pdo->prepare("
            SELECT id, name, name_en, parent_id
            FROM departments
            WHERE (name LIKE ? OR name_en LIKE ?)
            ORDER BY name
            LIMIT 100
        ");
        $st->execute(["%$q%", "%$q%"]);
    } else {
        $st = $pdo->query("
            SELECT id, name, name_en, parent_id
            FROM departments
            ORDER BY name
            LIMIT 500
        ");
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
    }
    unset($r);

    json_response([
        'ok' => true,
        'q' => $q,
        'count' => count($rows),
        'departments' => $rows,
    ]);
} catch (Exception $e) {
    json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
}