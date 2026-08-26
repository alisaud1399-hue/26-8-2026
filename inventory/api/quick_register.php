<?php
/**
 * inventory/api/quick_register.php — تسجيل أصل جديد سريع (لـ surplus)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['error'=>'method'], 405);

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$session_id = (int)($input['session_id'] ?? 0);
$tag = trim($input['tag_number'] ?? '');
$serial_number = trim($input['serial_number'] ?? '');

if (!$session_id) json_response(['error'=>'no_session']);

// تأكيد الجلسة نشطة
$ss = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sess || $sess['status'] !== 'active') {
json_response(['error'=>'session_not_active', 'message'=>'الجلسة غير نشطة للمسح (موقوفة/مغلقة) — لا يمكن تسجيل أصول جديدة الآن.']);
}
// 2. حارس العضوية: يمنع غير أعضاء لجنة الجلسة من تسجيل أصول جديدة
if (!inv_session_guard($session_id)) {
    log_activity('inventory.quick_register.denied', 'session:' . $session_id, 'user_not_member');
    json_response(['error'=>'forbidden', 'message'=>'أنت لست عضواً في لجنة الجرد لهذه الجلسة — لا يمكن تسجيل أصول جديدة.'], 403);
}

// 1. تأكد التاج غير مكرر
if ($tag !== '') {
    $exists = $pdo->prepare("SELECT id FROM assets WHERE tag_number=? LIMIT 1");
    $exists->execute([$tag]);
    if ($exists->fetchColumn()) json_response(['error'=>'duplicate_tag', 'message'=>'هذا التاج مسجل مسبقاً!']);
}

// 2. تأكد السيريال غير مكرر (الإضافة الجديدة للحماية)
if ($serial_number !== '') {
    $exists_serial = $pdo->prepare("SELECT id, tag_number FROM assets WHERE serial_number=? LIMIT 1");
    $exists_serial->execute([$serial_number]);
    $sc = $exists_serial->fetch(PDO::FETCH_ASSOC);
    if ($sc) {
        json_response(['error'=>'duplicate_serial', 'message'=>"هذا السيريال مسجل مسبقاً لجهاز بتاج: {$sc['tag_number']}"]);
    }
}

$user_id = (int)(current_user()['id'] ?? 0);
if (!$user_id) json_response(['error'=>'no_user']);

$description    = trim($input['description_en'] ?? $input['description'] ?? '');
$description_ar = trim($input['description_ar'] ?? '');

/* نوع الجهاز — إلزامي الآن، لا افتراض صامت لـ'other' بعد اليوم.
   هذه القيمة هي أساس توجيه "إكمال البيانات" لفريق الصيانة الصحيح
   (طبية/عامة/تقنية معلومات) عبر is_my_maintenance_type() — غيابها
   كان يوجّه كل أصول الزيادة لفريق الصيانة العامة افتراضياً بلا تمييز. */
$ASSET_TYPES = ['medical', 'it', 'infrastructure', 'hvac', 'transport', 'furniture', 'other'];
$asset_type = $input['asset_type'] ?? '';
if (!in_array($asset_type, $ASSET_TYPES, true)) {
    json_response(['error' => 'asset_type_required', 'message' => 'يجب تحديد نوع الجهاز (طبي / عام / تقنية معلومات) قبل الحفظ.']);
}
$location_id    = $input['location_id']    ? (int)$input['location_id']    : null;
$department_id  = ($input['department_id'] ?? null) ? (int)$input['department_id'] : null;
$manufacturer   = trim($input['manufacturer'] ?? '');
$model_no       = trim($input['model_no'] ?? '');
$criticality    = in_array($input['criticality_class'] ?? '', ['A','B','C'], true) ? $input['criticality_class'] : 'C';

// NUPCO Architecture
$item_code      = trim($input['item_code'] ?? '');
$unit           = trim($input['unit'] ?? '');
$generic_code   = trim($input['generic_code'] ?? '');

// حقول التصنيفات (إن وجدت من البطاقة المنبثقة)
$cat1 = trim($input['cat_level1'] ?? '');
$cat2 = trim($input['cat_level2'] ?? '');
$cat3 = trim($input['cat_level3'] ?? '');
$bld = trim($input['loc_building'] ?? '');
$flr = trim($input['loc_floor'] ?? '');
$rm = trim($input['loc_room'] ?? '');

/* إن وصل location_id رقمياً بلا نصوص الموقع (كما يحدث دائماً من نافذة
   تسجيل الزيادة في شاشة الجرد)، نحلّها تلقائياً من item_locations —
   حتى تصل بيانات موقع كاملة لكل شاشة/مستند يعرضها نصاً مباشراً (بطاقة
   العهدة، أمر العمل، وغيرها) لا فقط للشاشات التي تربط عبر location_id. */
if ($location_id && !$bld && !$flr && !$rm) {
    $loc_q = $pdo->prepare("
        SELECT r.name AS room_name, f.name AS floor_name, b.name AS building_name
        FROM item_locations r
        LEFT JOIN item_locations f ON f.id = r.parent_id
        LEFT JOIN item_locations b ON b.id = f.parent_id
        WHERE r.id = ?
    ");
    $loc_q->execute([$location_id]);
    if ($loc_row = $loc_q->fetch(PDO::FETCH_ASSOC)) {
        $bld = $loc_row['building_name'] ?: '';
        $flr = $loc_row['floor_name'] ?: '';
        $rm  = $loc_row['room_name'] ?: '';
    }
}

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("
        INSERT INTO assets
          (tag_number, alternative_code, serial_number, description, description_ar, asset_type,
           location_id, department_id, manufacturer_name, model_number,
           item_code, unit, generic_code,
           cat_level1, cat_level2, cat_level3,
           loc_building, loc_floor, loc_room,
           status, criticality_class, verified_status, verified_at,
           created_by, created_at, incomplete_data)
        VALUES
          (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
           'active', ?, 'تم التحقق', NOW(), ?, NOW(), 1)
    ");
    
    $st->execute([
        $tag ?: null,
        $serial_number ?: null,
        $description,
        $description_ar ?: null,
        $asset_type,
        $location_id,
        $department_id,
        $manufacturer ?: null,
        $model_no ?: null,
        $item_code ?: null,
        $unit ?: null,
        $generic_code ?: null,
        $cat1 ?: null, $cat2 ?: null, $cat3 ?: null,
        $bld ?: null, $flr ?: null, $rm ?: null,
        $criticality,
        $user_id,
    ]);
    $asset_id = (int)$pdo->lastInsertId();

    // audit row مع surplus_registered
    $st = $pdo->prepare("
        INSERT INTO inventory_audits
          (session_id, asset_id, scanned_tag, scanned_serial, scan_method, match_method, action, audited_by, audited_at, device_info)
        VALUES (?, ?, ?, ?, 'manual', 'tag', 'surplus_registered', ?, NOW(), ?)
    ");
    $st->execute([$session_id, $asset_id, $tag ?: null, $serial_number ?: null, $user_id, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
    $audit_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    $completion = refresh_asset_completion($pdo, $asset_id, 'inventory.quick_register (زيادة جرد)');

    json_response([
        'ok' => true,
        'asset_id' => $asset_id,
        'audit_id' => $audit_id,
        'tag' => $tag ?: $serial_number,
        'completion' => $completion,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error'=>'db','message'=>'خطأ بقاعدة البيانات: '.$e->getMessage()]);
}