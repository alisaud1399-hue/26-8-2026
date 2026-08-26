<?php
/**
 * api/tickets_mark_read.php — تعليم رسالة أو كل رسائل تذكرة كمقروءة
 * POST: ticket_id, message_id (optional — يُفترض آخر رسالة في التذكرة)
 * Returns: {ok, last_read_message_id}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ticket_id مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من وجود التذكرة
$tk = $pdo->prepare("SELECT id FROM tickets WHERE id = ?");
$tk->execute([$ticket_id]);
if (!$tk->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// إذا لم يُحدد message_id، نستخدم آخر رسالة
$message_id = (int)($_POST['message_id'] ?? 0);
if ($message_id <= 0) {
    $max = $pdo->prepare("SELECT MAX(id) FROM ticket_messages WHERE ticket_id = ?");
    $max->execute([$ticket_id]);
    $message_id = (int)$max->fetchColumn();
}

if ($message_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'لا توجد رسائل'], JSON_UNESCAPED_UNICODE);
    exit;
}

ticket_mark_read($pdo, $ticket_id, $uid, $message_id);

echo json_encode([
    'ok' => true,
    'ticket_id' => $ticket_id,
    'last_read_message_id' => $message_id,
], JSON_UNESCAPED_UNICODE);
