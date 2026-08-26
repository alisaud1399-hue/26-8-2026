<?php
/**
 * api/tickets_subscribe.php — اشتراك/إلغاء اشتراك في تذكرة (AJAX)
 * POST: ticket_id, action ('subscribe' or 'unsubscribe')
 * Returns: {ok, is_subscribed, subscriber_count}
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
$action    = $_POST['action'] ?? '';

if ($ticket_id <= 0 || !in_array($action, ['subscribe', 'unsubscribe'], true)) {
    echo json_encode(['ok' => false, 'error' => 'بيانات ناقصة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من وجود التذكرة
$tk = $pdo->prepare("SELECT id FROM tickets WHERE id = ?");
$tk->execute([$ticket_id]);
if (!$tk->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'subscribe') {
    ticket_subscribe($pdo, $ticket_id, $uid, 'manual', true);
} else {
    ticket_unsubscribe($pdo, $ticket_id, $uid);
}

// جلب العداد
$cnt = $pdo->prepare("
    SELECT subscriber_count FROM tickets WHERE id = ?
");
$cnt->execute([$ticket_id]);
$count = (int)$cnt->fetchColumn();

echo json_encode([
    'ok' => true,
    'is_subscribed' => $action === 'subscribe',
    'subscriber_count' => $count,
], JSON_UNESCAPED_UNICODE);
