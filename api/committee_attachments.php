<?php
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
$cid = (int)($_GET['committee_id'] ?? 0);
if (!$cid) { echo '[]'; exit; }
$st = $pdo->prepare("SELECT id,file_name,file_path,file_size,file_type FROM committee_attachments WHERE committee_id=? ORDER BY id");
$st->execute([$cid]);
echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE);