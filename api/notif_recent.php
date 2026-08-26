<?php
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
$uid = (int)current_user()['id'];
// الإشعارات المجدولة (scheduled_for > NOW()) لا تظهر في الجرس — تنتظر وقتها
$items = $pdo->prepare("
    SELECT id,type,title,body,link,is_read,created_at,scheduled_for
    FROM notifications
    WHERE user_id=? AND (scheduled_for IS NULL OR scheduled_for <= NOW())
    ORDER BY created_at DESC LIMIT 8
");
$items->execute([$uid]); $items=$items->fetchAll();
$unread=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (scheduled_for IS NULL OR scheduled_for <= NOW())");
$unread->execute([$uid]);
echo json_encode(['items'=>$items,'unread'=>(int)$unread->fetchColumn()],JSON_UNESCAPED_UNICODE);
