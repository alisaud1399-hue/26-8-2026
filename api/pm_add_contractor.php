<?php
/**
 * api/pm_add_contractor.php — Inline add contractor (متعهد خارجي) from PM Quick form
 * POST: name, purpose, notes
 *
 * يضيف سجل في committees (committee_type_id=6 = "أخرى" + purpose = PM contractor).
 * status = 'active' للاستخدام الفوري.
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!can('pm.schedules', 'apply')) {
    echo json_encode(['ok' => false, 'msg' => 'لا تملك صلاحية الإضافة']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$purpose = trim($_POST['purpose'] ?? 'متعهد صيانة دورية (PM)');
$notes = trim($_POST['notes'] ?? '');

if ($name === '' || mb_strlen($name) < 2) {
    echo json_encode(['ok' => false, 'msg' => 'الاسم مطلوب (حرفين على الأقل)']);
    exit;
}
if (mb_strlen($name) > 200) {
    echo json_encode(['ok' => false, 'msg' => 'الاسم طويل جداً (الحد 200 حرف)']);
    exit;
}

// Check duplicate (case-insensitive)
$dup = $pdo->prepare("SELECT id, name, status FROM committees WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
$dup->execute([$name]);
$existing = $dup->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    if ($existing['status'] === 'active') {
        // Re-use existing active contractor
        echo json_encode([
            'ok' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'reused' => true,
            'msg' => 'هذا المتعهد موجود مسبقاً (استخدمناه)'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        echo json_encode([
            'ok' => false,
            'msg' => 'يوجد متعهد بنفس الاسم لكن غير مفعّل (' . $existing['status'] . ') — راجع الإعدادات'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Compose the purpose (with notes appended if provided)
$final_purpose = $purpose;
if ($notes) {
    $final_purpose .= "\n\nملاحظات: " . $notes;
}

try {
    $sql = "INSERT INTO committees (name, committee_type_id, purpose, status, created_by, created_at)
            VALUES (?, 6, ?, 'active', ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$name, $final_purpose, user_id()]);
    $id = (int)$pdo->lastInsertId();

    // Log activity
    try {
        log_activity('create', 'contractor_quick', "Inline PM contractor #$id: $name");
    } catch (Throwable $le) { /* ignore */ }

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'name' => $name,
        'reused' => false,
        'msg' => 'تم إضافة المتعهد'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pm_add_contractor failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'خطأ في الحفظ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
