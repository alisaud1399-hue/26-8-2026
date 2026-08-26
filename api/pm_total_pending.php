<?php
/**
 * api/pm_total_pending.php — Total pending PMs (for hero stat)
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$row = $pdo->query("SELECT COUNT(*) AS total FROM pm_schedules WHERE is_active=1 AND next_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch();
echo json_encode(['total' => (int)($row['total'] ?? 0)]);
