<?php
/**
 * api/tickets_poll.php — استطلاع (polling) للتحديثات على تذكرة
 * GET: ticket_id, last_message_id, last_event_id
 * Returns: {new_messages: [...], new_events: [...], unread_count}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
$last_msg_id = (int)($_GET['last_message_id'] ?? 0);
$last_evt_id = (int)($_GET['last_event_id'] ?? 0);

if ($ticket_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ticket_id مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الرؤية
$tk = $pdo->prepare("SELECT id, created_by, assigned_to, visibility FROM tickets WHERE id = ?");
$tk->execute([$ticket_id]);
$tk_row = $tk->fetch();
if (!$tk_row) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$can_see_all_tickets = can('tickets', 'admin');
$is_creator = (int)$tk_row['created_by'] === $uid;
$is_assignee = (int)$tk_row['assigned_to'] === $uid;
$is_internal_staff = in_array($tk_row['visibility'], ['public', 'internal'], true);

if (!$can_see_all_tickets && !$is_creator && !$is_assignee && !$is_internal_staff) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية رؤية هذه التذكرة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Internal notes: غير المرتبطين بالصلاحية الداخلية (admin أو creator/assignee) لا يروونها
$can_see_internal_notes = $can_see_all_tickets || $is_creator || $is_assignee;
$internal_filter = $can_see_internal_notes ? '' : 'AND m.is_internal_note = 0';

// جلب الرسائل الجديدة
$msgs = $pdo->prepare("
    SELECT m.id, m.message, m.is_internal_note, m.created_at, m.reply_to_id,
           u.full_name AS sender_name, u.username AS sender_username
    FROM ticket_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.ticket_id = ? AND m.id > ? $internal_filter
    ORDER BY m.id ASC
");
$msgs->execute([$ticket_id, $last_msg_id]);
$new_messages = $msgs->fetchAll();

// جلب الأحداث الجديدة
$evts = $pdo->prepare("
    SELECT e.id, e.event_type, e.created_at, e.note, u.full_name AS actor_name
    FROM ticket_events e
    JOIN users u ON u.id = e.user_id
    WHERE e.ticket_id = ? AND e.id > ?
    ORDER BY e.id ASC
");
$evts->execute([$ticket_id, $last_evt_id]);
$new_events = $evts->fetchAll();

// Unread count: رسائل لم يقرؤها المستخدم بعد
$read = $pdo->prepare("SELECT last_read_message_id FROM ticket_reads WHERE ticket_id=? AND user_id=?");
$read->execute([$ticket_id, $uid]);
$last_read = (int)($read->fetchColumn() ?: 0);

$max = $pdo->prepare("SELECT MAX(id) FROM ticket_messages WHERE ticket_id = ? AND is_internal_note = 0");
$max->execute([$ticket_id]);
$max_msg = (int)$max->fetchColumn();

$unread = max(0, $max_msg - $last_read);

echo json_encode([
    'ok' => true,
    'new_messages' => $new_messages,
    'new_events' => $new_events,
    'unread_count' => $unread,
    'server_time' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
