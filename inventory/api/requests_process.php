<?php
/**
 * inventory/api/requests_process.php — معالجة طلب (اعتماد/رفض)
 * POST JSON: { request_id: int, action: 'approved'|'rejected', decision_note: string }
 * صلاحية: inventory.validate + approve
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['error' => 'method'], 405);
if (!can('inventory.validate', 'approve')) json_response(['error' => 'forbidden'], 403);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$request_id = (int)($body['request_id'] ?? 0);
$action = trim($body['action'] ?? '');
$note = trim($body['decision_note'] ?? '');

if ($request_id <= 0) json_response(['ok' => false, 'error' => 'request_id مفقود']);
if (!in_array($action, ['approved', 'rejected'], true)) json_response(['ok' => false, 'error' => 'action يجب أن يكون approved أو rejected']);

$st = $pdo->prepare("SELECT id, status, request_type, session_id, asset_id, room_id FROM inventory_reaudit_requests WHERE id=?");
$st->execute([$request_id]);
$req = $st->fetch(PDO::FETCH_ASSOC);
if (!$req) json_response(['ok' => false, 'error' => 'الطلب غير موجود']);
if ($req['status'] !== 'pending') json_response(['ok' => false, 'error' => 'تمت معالجة هذا الطلب مسبقاً']);

$me = (int)($_SESSION['user_id'] ?? 0);
$upd = $pdo->prepare("UPDATE inventory_reaudit_requests SET status=?, decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?");
$upd->execute([$action, $me, $note, $request_id]);

// ═══ إذا اعتمد طلب إعادة فتح غرفة → فتح الغرفة تلقائياً ═══
if ($action === 'approved' && $req['request_type'] === 're_audit_room' && $req['room_id'] > 0) {
    try {
        $pdo->prepare("UPDATE room_inventory_locks SET status='reopened', completed_by=NULL, completed_at=NULL WHERE session_id=? AND room_id=? AND status='completed' ORDER BY id DESC LIMIT 1")
            ->execute([$req['session_id'], $req['room_id']]);
    } catch (Throwable $e) { error_log('auto reopen room failed: ' . $e->getMessage()); }
}

// ═══ إشعار لصاحب الطلب ═══
try {
    $sender_name = current_user()['full_name'] ?? 'مدير';
    $type_label = $req['request_type'] === 're_audit_device' ? 'إعادة جرد جهاز' : ($req['request_type'] === 'data_conflict' ? 'تضارب بيانات' : 'إعادة فتح غرفة');
    $status_label = $action === 'approved' ? 'تمت الموافقة ✅' : 'تم الرفض ❌';

    // جلب صاحب الطلب
    $owner_q = $pdo->prepare("SELECT requested_by FROM inventory_reaudit_requests WHERE id=?");
    $owner_q->execute([$request_id]);
    $owner = $owner_q->fetch(PDO::FETCH_ASSOC);

    if ($owner && (int)$owner['requested_by'] > 0 && (int)$owner['requested_by'] !== $me) {
        $notif_body = "{$status_label} على طلب {$type_label}\nبتصرّف: {$sender_name}";
        if ($note) $notif_body .= "\nملاحظة: {$note}";
        $notif_link = BASE_URL . '/inventory/session.php?id=' . $req['session_id'];

        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)")
            ->execute([
                (int)$owner['requested_by'],
                "📋 {$status_label}: {$type_label}",
                $notif_body,
                $notif_link,
                $req['session_id'],
            ]);
    }
} catch (Throwable $e) {
    error_log('request process notification failed: ' . $e->getMessage());
}

json_response(['ok' => true, 'request_id' => $request_id, 'new_status' => $action]);
