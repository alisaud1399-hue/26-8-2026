<?php
/**
 * inventory/api/reaudit_request.php
 * إنشاء طلب استثناء (إعادة جرد جهاز أو إعادة فتح غرفة)
 *
 * POST JSON:
 *   session_id  (int, required)
 *   request_type (string) — 're_audit_device' (default) | 're_audit_room' | 'data_conflict'
 *   asset_id    (int) — مطلوب إذا request_type=re_audit_device أو data_conflict
 *   room_id     (int) — مطلوب إذا request_type=re_audit_room
 *   reason      (string, required)
 *   conflict_field (string) — 'tag' أو 'serial' — مطلوب إذا request_type=data_conflict
 *   conflict_value (string) — القيمة المتعارضة — مطلوب إذا request_type=data_conflict
 *   conflict_asset_id (int) — الأصل المتعارض معه — مطلوب إذا request_type=data_conflict
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();

$body        = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id  = (int)($body['session_id'] ?? 0);
$request_type = trim($body['request_type'] ?? 're_audit_device');
$asset_id    = (int)($body['asset_id'] ?? 0);
$room_id     = (int)($body['room_id'] ?? 0);
$reason      = trim($body['reason'] ?? '');
$conflict_field = trim($body['conflict_field'] ?? '');
$conflict_value = trim($body['conflict_value'] ?? '');
$conflict_asset_id = (int)($body['conflict_asset_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($request_type, ['re_audit_device', 're_audit_room', 'data_conflict'], true)) {
    json_response(['ok' => false, 'error' => 'نوع طلب غير صحيح']);
}
if ($session_id <= 0) json_response(['ok' => false, 'error' => 'بيانات ناقصة']);
if ($reason === '') json_response(['ok' => false, 'error' => 'سبب الطلب إلزامي']);

// الجلسة يجب أن تكون حية
$st = $pdo->prepare("SELECT id, status, title FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session || !in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'الجلسة غير نشطة']);
}

// ══════════════════════════════════════════════════════════════
// النوع 1: إعادة جرد جهاز (re_audit_device)
// ══════════════════════════════════════════════════════════════
if ($request_type === 're_audit_device') {
    if ($asset_id <= 0) json_response(['ok' => false, 'error' => 'identifiers مفقودة — أرسل asset_id']);

    // آخر سجل جرد "منجَز" لهذا الأصل في هذه الجلسة
    $aq = $pdo->prepare("
        SELECT id, action FROM inventory_audits
        WHERE session_id=? AND asset_id=?
          AND action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')
        ORDER BY id DESC LIMIT 1
    ");
    $aq->execute([$session_id, $asset_id]);
    $audit = $aq->fetch(PDO::FETCH_ASSOC);
    if (!$audit) json_response(['ok' => false, 'error' => 'لا يوجد سجل جرد منجَز لهذا الأصل في الجلسة']);

    // منع تكرار طلب معلَّق لنفس الأصل
    $dq = $pdo->prepare("SELECT id FROM inventory_reaudit_requests
        WHERE session_id=? AND asset_id=? AND request_type='re_audit_device' AND status='pending' LIMIT 1");
    $dq->execute([$session_id, $asset_id]);
    if ($dq->fetch()) json_response(['ok' => false, 'error' => 'يوجد طلب معلَّق مسبقاً لهذا الأصل']);

    $ins = $pdo->prepare("
        INSERT INTO inventory_reaudit_requests (session_id, request_type, asset_id, audit_id, room_id, requested_by, reason)
        VALUES (?, 're_audit_device', ?, ?, NULL, ?, ?)
    ");
    $ins->execute([$session_id, $asset_id, (int)$audit['id'], $user_id, $reason]);
    $request_id = (int)$pdo->lastInsertId();

    // جلب معلومات الأصل للإشعار
    $an = $pdo->prepare("SELECT tag_number, description FROM assets WHERE id=?");
    $an->execute([$asset_id]);
    $asset_info = $an->fetch(PDO::FETCH_ASSOC);
    $asset_label = ($asset_info['tag_number'] ?? '#'.$asset_id) . ' — ' . ($asset_info['description'] ?? '');

// ══════════════════════════════════════════════════════════════
// النوع 2: إعادة فتح غرفة (re_audit_room)
// ══════════════════════════════════════════════════════════════
} elseif ($request_type === 're_audit_room') {
    if ($room_id <= 0) json_response(['ok' => false, 'error' => 'identifiers مفقودة — أرسل room_id']);

    // تحقق أن الغرفة مُغلقة فعلاً في هذه الجلسة (completed lock)
    $lq = $pdo->prepare("SELECT id, locked_by, completed_at, completed_by
        FROM room_inventory_locks
        WHERE session_id=? AND room_id=? AND status='completed' LIMIT 1");
    $lq->execute([$session_id, $room_id]);
    $completed_lock = $lq->fetch(PDO::FETCH_ASSOC);
    if (!$completed_lock) json_response(['ok' => false, 'error' => 'الغرفة ليست مُغلقة في هذه الجلسة']);

    // منع تكرار طلب معلَّق لنفس الغرفة
    $dq = $pdo->prepare("SELECT id FROM inventory_reaudit_requests
        WHERE session_id=? AND room_id=? AND request_type='re_audit_room' AND status='pending' LIMIT 1");
    $dq->execute([$session_id, $room_id]);
    if ($dq->fetch()) json_response(['ok' => false, 'error' => 'يوجد طلب معلَّق مسبقاً لهذه الغرفة']);

    $ins = $pdo->prepare("
        INSERT INTO inventory_reaudit_requests (session_id, request_type, asset_id, audit_id, room_id, requested_by, reason)
        VALUES (?, 're_audit_room', NULL, NULL, ?, ?, ?)
    ");
    $ins->execute([$session_id, $room_id, $user_id, $reason]);
    $request_id = (int)$pdo->lastInsertId();

    // جلب معلومات الغرفة للإشعار
    $rn = $pdo->prepare("SELECT name, name_en FROM item_locations WHERE id=?");
    $rn->execute([$room_id]);
    $room_info = $rn->fetch(PDO::FETCH_ASSOC);
    $asset_label = ($room_info['name_en'] ?: $room_info['name'] ?: '#' . $room_id);

// ══════════════════════════════════════════════════════════════
// النوع 3: تقرير تضارب بيانات (data_conflict)
// ══════════════════════════════════════════════════════════════
} elseif ($request_type === 'data_conflict') {
    if ($asset_id <= 0) json_response(['ok' => false, 'error' => 'asset_id مفقود']);
    if (!in_array($conflict_field, ['tag', 'serial'], true)) json_response(['ok' => false, 'error' => 'conflict_field يجب أن يكون tag أو serial']);
    if ($conflict_value === '') json_response(['ok' => false, 'error' => 'conflict_value مفقود']);

    // منع تكرار طلب معلَّق لنفس الأصل لنفس الحقل
    $dq = $pdo->prepare("SELECT id FROM inventory_reaudit_requests
        WHERE session_id=? AND asset_id=? AND request_type='data_conflict' AND conflict_field=? AND status='pending' LIMIT 1");
    $dq->execute([$session_id, $asset_id, $conflict_field]);
    if ($dq->fetch()) json_response(['ok' => false, 'error' => 'يوجد طلب تضارب معلَّق مسبقاً لهذا الحقل']);

    $ins = $pdo->prepare("
        INSERT INTO inventory_reaudit_requests (session_id, request_type, asset_id, audit_id, room_id, conflict_field, conflict_value, conflict_asset_id, requested_by, reason)
        VALUES (?, 'data_conflict', ?, NULL, NULL, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$session_id, $asset_id, $conflict_field, $conflict_value, $conflict_asset_id ?: null, $user_id, $reason]);
    $request_id = (int)$pdo->lastInsertId();

    $an = $pdo->prepare("SELECT tag_number, description, description_ar FROM assets WHERE id=?");
    $an->execute([$asset_id]);
    $asset_info = $an->fetch(PDO::FETCH_ASSOC);
    $asset_label = ($asset_info['tag_number'] ?? '#'.$asset_id) . ' — ' . ($asset_info['description_ar'] ?: $asset_info['description'] ?? '');
    $conflict_label = $conflict_field === 'tag' ? 'التاج' : 'السيريال';

    // معلومات الأصل المتعارض
    $conflict_asset_label = '';
    if ($conflict_asset_id > 0) {
        $ca = $pdo->prepare("SELECT tag_number, description, description_ar FROM assets WHERE id=?");
        $ca->execute([$conflict_asset_id]);
        $ca_info = $ca->fetch(PDO::FETCH_ASSOC);
        $conflict_asset_label = ($ca_info['tag_number'] ?? '#'.$conflict_asset_id) . ' — ' . ($ca_info['description_ar'] ?: $ca_info['description'] ?? '');
    }
}

// ══════════════════════════════════════════════════════════════
// إشعار للمدير/المسؤول — يذهب لكل شخص يملك صلاحية الاعتماد
// ══════════════════════════════════════════════════════════════
try {
    $sender_name = current_user()['full_name'] ?? 'موظف';
    $type_label = $request_type === 're_audit_device' ? 'إعادة جرد جهاز' : ($request_type === 'data_conflict' ? 'تضارب بيانات' : 'إعادة فتح غرفة');

    $notif_body = "طلب {$type_label}: {$asset_label}\nالسبب: {$reason}\nالجلسة: {$session['title']}";
    $notif_link = BASE_URL . '/inventory/session.php?id=' . $session_id;

    // إرسال لكل من يملك inventory.validate.approve
    $admin_q = $pdo->query("
        SELECT DISTINCT u.id FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN role_permissions rp ON rp.role_id = ur.role_id
        JOIN permissions p ON p.id = rp.permission_id
        WHERE p.code = 'inventory.validate' AND p.action = 'approve'
    ");
    $admin_ids = $admin_q->fetchAll(PDO::FETCH_COLUMN);

    foreach ($admin_ids as $admin_id) {
        if ((int)$admin_id === $user_id) continue; // لا تُرسل لصاحب الطلب
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
            VALUES (?, 'warning', ?, ?, ?, 'inventory_session', ?)")
            ->execute([
                (int)$admin_id,
                "🚩 طلب استثناء جرد: {$type_label}",
                $notif_body,
                $notif_link,
                $session_id,
            ]);
    }
} catch (Throwable $e) {
    error_log('reaudit_request notification failed: ' . $e->getMessage());
}

json_response(['ok' => true, 'request_id' => $request_id, 'request_type' => $request_type]);
