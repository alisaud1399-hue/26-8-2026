<?php
/**
 * inventory/api/verify_field.php
 * ------------------------------------
 * تسجيل تحقق حقل-بحقل لأصل في جلسة جرد
 * (يستخدم لـ audit trail أدق بدل فحص الأصل كامل)
 *
 * POST JSON:
 *   session_id  (int) required
 *   asset_id    (int) required
 *   field_name  (string) required - tag_number|manufacturer|model|serial|location|description|category
 *   field_value (string) optional - قيمة الحقل وقت التحقق
 *   is_verified (bool) optional, default true
 *
 * أو لتمرير عدة حقول دفعة:
 *   session_id  (int) required
 *   asset_id    (int) required
 *   fields      (array) [{name, value, is_verified?}, ...]
 *
 * Returns:
 *   { ok: true, verified_count, fields_total, completeness }
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'method'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$session_id = (int)($input['session_id'] ?? 0);
$asset_id   = (int)($input['asset_id'] ?? 0);

if ($session_id <= 0) json_response(['ok' => false, 'error' => 'session_id_required'], 400);
if ($asset_id <= 0)   json_response(['ok' => false, 'error' => 'asset_id_required'], 400);

// 1) تأكيد الجلسة
$ss = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sess) json_response(['ok' => false, 'error' => 'session_not_found'], 404);
if (!in_array($sess['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'session_not_active'], 403);
}

$user_id = (int)(current_user()['id'] ?? 0);
if ($user_id <= 0) json_response(['ok' => false, 'error' => 'no_user'], 401);

// 2) تجميع الحقول
$fields = [];
if (!empty($input['fields']) && is_array($input['fields'])) {
    // bulk mode
    foreach ($input['fields'] as $f) {
        if (!empty($f['name'])) {
            $fields[] = [
                'name'       => (string)$f['name'],
                'value'      => (string)($f['value'] ?? ''),
                'is_verified'=> !empty($f['is_verified']),
            ];
        }
    }
} elseif (!empty($input['field_name'])) {
    // single mode
    $fields[] = [
        'name'       => (string)$input['field_name'],
        'value'      => (string)($input['field_value'] ?? ''),
        'is_verified'=> !empty($input['is_verified']),
    ];
}

if (empty($fields)) {
    json_response(['ok' => false, 'error' => 'no_fields_provided'], 400);
}

// 3) حفظ في asset_field_verifications (UPSERT)
try {
    $pdo->beginTransaction();

    $up = $pdo->prepare("
        INSERT INTO asset_field_verifications
          (session_id, asset_id, field_name, field_value, is_verified, verified_by, verified_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          field_value = VALUES(field_value),
          is_verified = VALUES(is_verified),
          verified_by = VALUES(verified_by),
          verified_at = NOW()
    ");

    foreach ($fields as $f) {
        $up->execute([$session_id, $asset_id, $f['name'], $f['value'], $f['is_verified'] ? 1 : 0, $user_id]);
    }

    // 4) حساب data_completeness
    $a = $pdo->prepare("SELECT tag_number, description, manufacturer_name, model_number, serial_number, location_id, category_id FROM assets WHERE id=?");
    $a->execute([$asset_id]);
    $asset = $a->fetch(PDO::FETCH_ASSOC);

    $critical = 0; $filled = 0;
    foreach (['tag_number','description','manufacturer_name','model_number','serial_number','location_id','category_id'] as $fld) {
        $critical++;
        $v = $asset[$fld] ?? null;
        if ($v !== null && $v !== '' && $v !== 0) $filled++;
    }
    if ($filled === $critical)        $completeness = 'complete';
    elseif ($filled >= max(2, intdiv($critical, 2))) $completeness = 'partial';
    else                              $completeness = 'minimal';
    $incomplete_flag = ($completeness !== 'complete') ? 1 : 0;

    $pdo->prepare("
        UPDATE assets
        SET data_completeness = ?,
            incomplete_data   = ?,
            verified_at       = NOW(),
            verified_by       = ?,
            verified_status   = 'تم التحقق'
        WHERE id = ?
    ")->execute([$completeness, $incomplete_flag, $user_id, $asset_id]);

    // 5) إحصائيات الحقول المتحققة
    $cnt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END) AS verified,
          COUNT(*) AS total
        FROM asset_field_verifications
        WHERE session_id=? AND asset_id=?
    ");
    $cnt->execute([$session_id, $asset_id]);
    $stats = $cnt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    json_response([
        'ok'              => true,
        'asset_id'        => $asset_id,
        'session_id'      => $session_id,
        'fields_saved'    => count($fields),
        'verified_count'  => (int)($stats['verified'] ?? 0),
        'fields_total'    => (int)($stats['total'] ?? 0),
        'completeness'    => $completeness,
        'incomplete_data' => (bool)$incomplete_flag,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
}