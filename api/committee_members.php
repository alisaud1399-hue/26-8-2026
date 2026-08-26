<?php
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
$cid = (int)($_GET['committee_id'] ?? 0);
if (!$cid) { echo '[]'; exit; }
$role_ar = ['manager'=>'رئيس اللجنة','technical'=>'عضو فني','receiver'=>'مستلم','other'=>'عضو'];
$st = $pdo->prepare("SELECT cm.user_id, cm.role, cm.sort_order, u.full_name
    FROM committee_members cm LEFT JOIN users u ON u.id=cm.user_id
    WHERE cm.committee_id=? ORDER BY cm.sort_order");
$st->execute([$cid]);
$rows = $st->fetchAll();
foreach ($rows as &$r) $r['role_ar'] = $role_ar[$r['role']] ?? $r['role'];
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
