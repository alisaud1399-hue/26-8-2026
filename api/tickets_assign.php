<?php
/**
 * api/tickets_assign.php — تعيين/تغيير مسؤول تذكرة (AJAX)
 * POST: ticket_id, assigned_to (user_id, or empty for unassign)
 * Returns: {ok, assigned_to, assigned_name, broadcast}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];

if (!can('tickets', 'manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية تعيين التذاكر'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

if ($ticket_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ticket_id مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($assigned_to) {
    $u = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND is_active = 1");
    $u->execute([$assigned_to]);
    $user = $u->fetch();
    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'المستخدم غير موجود أو غير نشط'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$r = ticket_assign($pdo, $ticket_id, $assigned_to, $uid);

if (!$r['ok']) {
    http_response_code(500);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'assigned_to' => $assigned_to,
    'assigned_name' => $assigned_to ? $user['full_name'] : null,
], JSON_UNESCAPED_UNICODE);
