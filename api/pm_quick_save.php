<?php
/**
 * api/pm_quick_save.php — Save PM (quick form)
 * POST: asset_id, next_due, pm_type, estimated_hours, assigned_to_user_id, contractor_id,
 *       notes, notify_lead_days, is_recurring, cycle_days, priority, attachments[]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
header('Content-Type: application/json; charset=utf-8');

page_guard('pm.schedules');

if (!can('pm.schedules', 'apply')) {
    echo json_encode(['ok' => false, 'msg' => 'لا تملك صلاحية الإضافة']);
    exit;
}

$asset_id = (int)($_POST['asset_id'] ?? 0);
$next_due = $_POST['next_due'] ?? '';
$pm_type = trim($_POST['pm_type'] ?? '');
$estimated_hours = !empty($_POST['estimated_hours']) ? (float)$_POST['estimated_hours'] : null;
$assigned_to_user_id = !empty($_POST['assigned_to_user_id']) ? (int)$_POST['assigned_to_user_id'] : null;
$notes = trim($_POST['notes'] ?? '');
$notify_lead_days = !empty($_POST['notify_lead_days']) ? (int)$_POST['notify_lead_days'] : 7;
$is_recurring = !empty($_POST['is_recurring']) ? 1 : 0;
// cycle_days: NOT NULL column — always send a value. 0 means "no cycle / default"
$cycle_days = !empty($_POST['cycle_days']) ? (int)$_POST['cycle_days'] : 0;
$priority = $_POST['priority'] ?? 'normal';

// contractor_id: FK now references committees(id) (Migration 032)
// The PM Quick form's dropdown is populated from `committees` — IDs match directly.
$contractor_id = !empty($_POST['contractor_id']) ? (int)$_POST['contractor_id'] : null;
$is_external = $contractor_id ? 1 : 0;

if ($asset_id < 1 || !$next_due || !$pm_type) {
    echo json_encode(['ok' => false, 'msg' => 'بيانات ناقصة (الأصل، التاريخ، النوع)']);
    exit;
}
if (!$assigned_to_user_id && !$contractor_id) {
    echo json_encode(['ok' => false, 'msg' => 'يجب اختيار المنفّذ (داخلي أو خارجي)']);
    exit;
}
if ($is_recurring && $cycle_days < 1) {
    echo json_encode(['ok' => false, 'msg' => 'عند تفعيل التكرار، يجب تحديد عدد الأيام']);
    exit;
}

// Verify asset exists
$as = $pdo->prepare("SELECT id, description, tag_number FROM assets WHERE id=?");
$as->execute([$asset_id]);
$asset = $as->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    echo json_encode(['ok' => false, 'msg' => 'الأصل غير موجود']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Compute last_completed (NULL for new PM)
    $last_completed = null;

    // Insert into pm_schedules
    $sql = "INSERT INTO pm_schedules
            (asset_id, contractor_id, pm_type, cycle_days, last_completed, next_due,
             notify_lead_days, is_active, notes, is_external, assigned_to_user_id,
             estimated_hours, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $asset_id, $contractor_id, $pm_type, $cycle_days, $last_completed, $next_due,
        $notify_lead_days, $notes, $is_external, $assigned_to_user_id,
        $estimated_hours, user_id()
    ]);
    $pm_id = (int)$pdo->lastInsertId();

    // Handle attachments if uploaded
    $saved_files = 0;
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = BASE_PATH . '/uploads/pm/' . date('Y/m') . '/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($_FILES['attachments']['size'][$i] > 10 * 1024 * 1024) continue; // 10MB limit
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
            if (!in_array($ext, $allowed)) continue;
            $new_name = "pm_{$pm_id}_" . uniqid() . ".{$ext}";
            $target = $upload_dir . $new_name;
            if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $target)) {
                // Try to insert into attachments table if it exists
                try {
                    $pdo->prepare("INSERT INTO attachments
                        (related_type, related_id, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([
                            'pm_schedule', $pm_id, $name, $new_name,
                            '/uploads/pm/' . date('Y/m') . '/' . $new_name,
                            $_FILES['attachments']['type'][$i] ?? 'application/octet-stream',
                            $_FILES['attachments']['size'][$i],
                            user_id()
                        ]);
                } catch (Throwable $att) { /* attachments table may not exist for PM */ }
                $saved_files++;
            }
        }
    }

    // Send notification to assigned user (if internal)
    if ($assigned_to_user_id) {
        try {
            $an = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
            $an->execute([$assigned_to_user_id]);
            $name = $an->fetchColumn() ?: 'المستخدم';
            send_notification(
                $assigned_to_user_id,
                'pm.assigned',
                "PM جديدة: {$pm_type}",
                "تم تعيين صيانة دورية ({$pm_type}) للجهاز «{$asset['description']}» بتاريخ {$next_due}.",
                BASE_URL . "/maintenance/pm_schedules.php?highlight={$pm_id}"
            );
        } catch (Throwable $ne) { error_log('PM notification failed: ' . $ne->getMessage()); }
    }

    // Log activity
    try {
        log_activity('create', 'pm_quick', "PM ID:{$pm_id} Asset:{$asset_id}");
    } catch (Throwable $le) { /* ignore */ }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'pm_id' => $pm_id,
        'msg' => "تم الحفظ بنجاح — رقم PM: {$pm_id}" . ($saved_files ? " (+{$saved_files} مرفقات)" : '')
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('PM Quick save failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'msg' => 'خطأ في الحفظ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
