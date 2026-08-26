<?php
/**
 * api/pm_departments.php — Departments for PM Quick
 * GET: ?level=1
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT d.id, d.name,
        (SELECT COUNT(*) FROM assets a
         WHERE a.status='active' AND a.department_id = d.id) AS asset_count
        FROM departments d
        ORDER BY d.name";
$stmt = $pdo->prepare($sql);
$stmt->execute();
echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
