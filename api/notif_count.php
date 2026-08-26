<?php
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
$uid=(int)current_user()['id'];
$s=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$s->execute([$uid]);
echo json_encode(['count'=>(int)$s->fetchColumn()]);
