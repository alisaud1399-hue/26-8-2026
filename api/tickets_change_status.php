<?php
/**
 * api/tickets_change_status.php — تغيير حالة تذكرة (AJAX)
 * POST: ticket_id, new_status, note (optional)
 * Returns: {ok, old_status, new_status}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];

// صلاحية الإدارة
if (!can('tickets', 'manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية تغيير حالة التذكرة'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ticket_id  = (int)($_POST['ticket_id'] ?? 0);
$new_status = $_POST['new_status'] ?? '';
$note       = trim($_POST['note'] ?? '') ?: null;

if ($ticket_id <= 0 || $new_status === '') {
    echo json_encode(['ok' => false, 'error' => 'بيانات ناقصة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$STATUS_AR = [
    'open'        => 'مفتوحة',
    'assigned'    => 'معيَّنة',
    'in_progress' => 'جاري العمل',
    'awaiting'    => 'بانتظار رد',
    'resolved'    => 'تم الحل',
    'closed'      => 'مغلقة',
    'archived'    => 'مؤرشفة',
];

if (!isset($STATUS_AR[$new_status])) {
    echo json_encode(['ok' => false, 'error' => 'حالة غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// جلب الحالة القديمة
$cur = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
$cur->execute([$ticket_id]);
$old = $cur->fetchColumn();

if (!$old) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = ticket_change_status($pdo, $ticket_id, $new_status, $uid, $note);

if (!$r['ok']) {
    http_response_code(500);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'old_status' => $old,
    'old_status_ar' => $STATUS_AR[$old] ?? $old,
    'new_status' => $new_status,
    'new_status_ar' => $STATUS_AR[$new_status],
], JSON_UNESCAPED_UNICODE);
