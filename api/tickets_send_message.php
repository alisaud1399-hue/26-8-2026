<?php
/**
 * api/tickets_send_message.php — إضافة رد على تذكرة (AJAX)
 * POST: ticket_id, message, is_internal_note (0/1), reply_to_id (optional)
 * Returns: {ok, message_id, broadcast_count, message_html}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];

// صلاحية الرد
if (!can('tickets', 'respond')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية الرد على التذاكر'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ticket_id  = (int)($_POST['ticket_id'] ?? 0);
$message    = trim($_POST['message'] ?? '');
$is_internal = !empty($_POST['is_internal_note']) ? 1 : 0;
$reply_to   = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;

if ($ticket_id <= 0 || $message === '') {
    echo json_encode(['ok' => false, 'error' => 'بيانات ناقصة (ticket_id / message)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من رؤية التذكرة (نفس المنطق في view.php)
$tk = $pdo->prepare("SELECT id, status, visibility, created_by, assigned_to FROM tickets WHERE id = ?");
$tk->execute([$ticket_id]);
$tk_row = $tk->fetch();

if (!$tk_row) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$can_see_all_tickets = can('tickets', 'admin');
$is_creator = (int)$tk_row['created_by'] === $uid;
$is_assignee = (int)$tk_row['assigned_to'] === $uid;

if (!$can_see_all_tickets && $tk_row['visibility'] === 'restricted' && !$is_creator && !$is_assignee) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية رؤية هذه التذكرة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التذكرة المغلقة/المؤرشفة لا تقبل ردود
if (in_array($tk_row['status'], ['closed', 'archived'], true)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'التذكرة ' . ($tk_row['status'] === 'closed' ? 'مغلقة' : 'مؤرشفة') . ' — لا يمكن إضافة ردود جديدة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// إضافة الرسالة
$r = ticket_add_message($pdo, $ticket_id, $uid, $message, (bool)$is_internal, $reply_to);

if (!$r['ok']) {
    http_response_code(500);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// جلب تفاصيل الرسالة المُضافة لإرجاع HTML
$msg = $pdo->prepare("
    SELECT m.id, m.message, m.is_internal_note, m.created_at, m.reply_to_id,
           u.full_name AS sender_name, u.username AS sender_username
    FROM ticket_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.id = ?
");
$msg->execute([$r['message_id']]);
$msg_row = $msg->fetch();

echo json_encode([
    'ok' => true,
    'message_id' => $r['message_id'],
    'broadcast_count' => $r['broadcast'],
    'message' => [
        'id' => (int)$msg_row['id'],
        'html_class' => $msg_row['is_internal_note'] ? 'msg is-internal' : 'msg',
        'sender_name' => $msg_row['sender_name'],
        'sender_username' => $msg_row['sender_username'],
        'sender_initial' => mb_substr($msg_row['sender_name'] ?? '؟', 0, 1, 'UTF-8'),
        'created_at' => $msg_row['created_at'],
        'created_at_pretty' => date('Y-m-d H:i', strtotime($msg_row['created_at'])),
        'message' => $msg_row['message'],
        'is_internal' => (int)$msg_row['is_internal_note'],
        'reply_to_id' => $msg_row['reply_to_id'],
    ],
], JSON_UNESCAPED_UNICODE);
