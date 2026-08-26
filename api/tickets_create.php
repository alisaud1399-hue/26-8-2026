<?php
/**
 * api/tickets_create.php — إنشاء تذكرة جديدة (AJAX)
 * POST: title, description, ticket_type, priority, visibility (public/internal/restricted),
 *       assigned_to, department_id, related_type, related_id, due_date, is_internal_note
 * Returns: {ok, id, ticket_number, redirect}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];

if (!can('tickets', 'create')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية إنشاء تذاكر'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = [
    'title'            => trim($_POST['title'] ?? ''),
    'description'      => trim($_POST['description'] ?? ''),
    'ticket_type'      => $_POST['ticket_type'] ?? '',
    'priority'         => $_POST['priority'] ?? 'medium',
    'visibility'       => $_POST['visibility'] ?? 'public',
    'assigned_to'      => $_POST['assigned_to'] ?? null,
    'department_id'    => $_POST['department_id'] ?? null,
    'related_type'     => $_POST['related_type'] ?? null,
    'related_id'       => $_POST['related_id'] ?? null,
    'due_date'         => $_POST['due_date'] ?? null,
    'is_internal_note' => !empty($_POST['is_internal_note']) ? 1 : 0,
];

$r = ticket_create($pdo, $data, $uid);

if (!$r['ok']) {
    http_response_code(400);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'id' => $r['id'],
    'ticket_number' => $r['ticket_number'],
    'redirect' => BASE_URL . '/tickets/view.php?id=' . $r['id'],
], JSON_UNESCAPED_UNICODE);
