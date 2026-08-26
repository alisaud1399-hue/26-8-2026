<?php
/**
 * api/contractor_add.php — Add a new contractor inline
 * Used by pm_schedules.php / pm_execute.php
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Permission: anyone who can manage PM schedules or inventory
$can_add = can('contractors', 'edit') || can('pm.schedules', 'edit') || can('maintenance', 'edit') || is_admin();
if (!$can_add) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// CSRF check (if available)
$csrf = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
if (function_exists('verify_csrf') && $csrf && !verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$service_type = trim($_POST['service_type'] ?? 'general');
$phone = trim($_POST['phone'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');

if (mb_strlen($name) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'name too short']);
    exit;
}

global $pdo;

// Check duplicate
$check = $pdo->prepare("SELECT id FROM contractors WHERE name = ?");
$check->execute([$name]);
$existing = $check->fetchColumn();
if ($existing) {
    echo json_encode(['error' => 'duplicate', 'id' => (int)$existing, 'name' => $name]);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO contractors (name, service_type, phone, contact_person, is_internal, is_active, created_by, created_at) VALUES (?, ?, ?, ?, 0, 1, ?, NOW())");
    $stmt->execute([$name, $service_type, $phone, $contact_person, user_id()]);
    $id = (int)$pdo->lastInsertId();
    log_activity('contractor.created', "contractor:$id", json_encode(['name' => $name, 'service_type' => $service_type]));
    echo json_encode([
        'id' => $id,
        'name' => $name,
        'service_type' => $service_type,
        'phone' => $phone,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
