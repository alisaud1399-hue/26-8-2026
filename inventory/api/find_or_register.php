<?php
/**
 * inventory/api/find_or_register.php
 * ------------------------------------
 * للزوائد (Surplus): ابحث أولاً، لو ما وُجد سجّل كأصل جديد
 *
 * POST JSON:
 *   action: 'search' | 'register'
 *
 * === action=search ===
 *   tag | serial (string, required) - للبحث في tag_number, alternative_code, serial_number
 *
 *   Returns:
 *     { ok: true, found: bool, asset?: {...}, hint?: '...' }
 *
 * === action=register ===
 *   session_id (int, required)
 *   tag_number (string, required) - فريد
 *   asset_type (string, required) - medical|it|infrastructure|...
 *   new_used   (string, required) - NEW|USED
 *   description (string, optional)
 *   manufacturer_name (string, optional)
 *   model_number (string, optional)
 *   serial_number (string, optional)
 *   cat_seg1/2/3 (int, optional) - معرّفات الفئات
 *   loc_building/floor/room (string, optional) - معرّفات نصية للموقع
 *   location_id (int, optional) - FK لغرفة محددة
 *   dept_id (int, optional) - القسم
 *
 *   Returns:
 *     { ok: true, asset_id, tag_number, data_completeness, incomplete_data }
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
$rtl = is_rtl();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'method'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? '';

if ($action === 'search') {
    // ===== البحث عن أصل موجود =====
    $tag = trim($input['tag'] ?? $input['serial'] ?? '');
    if ($tag === '') {
        json_response(['ok' => false, 'error' => 'tag_required'], 400);
    }

    $st = $pdo->prepare("
        SELECT a.id, a.tag_number, a.alternative_code, a.description, a.en_name,
               a.asset_type, a.criticality_class, a.manufacturer_name, a.model_number,
               a.serial_number, a.loc_building, a.loc_floor, a.loc_room,
               a.status, a.data_completeness,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.tag_number = ? OR a.alternative_code = ? OR a.serial_number = ?
        LIMIT 1
    ");
    $st->execute([$tag, $tag, $tag]);
    $asset = $st->fetch(PDO::FETCH_ASSOC);

    if ($asset) {
        json_response([
            'ok'    => true,
            'found' => true,
            'asset' => $asset,
            'hint'  => $rtl
                ? "هذا التاج مسجّل كأصل موجود. افحصه قبل تسجيل زيادة."
                : "This tag exists as an asset. Audit it before registering as surplus.",
        ]);
    } else {
        json_response([
            'ok'    => true,
            'found' => false,
            'tag'   => $tag,
            'hint'  => $rtl
                ? "هذا التاج غير مسجّل. أكمل البيانات لتسجيله كأصل جديد."
                : "This tag is not registered. Complete the data to register as a new asset.",
        ]);
    }
}

elseif ($action === 'register') {
    // ===== تسجيل أصل جديد =====
    $session_id = (int)($input['session_id'] ?? 0);
    $tag_number = trim($input['tag_number'] ?? '');
    $asset_type = trim($input['asset_type'] ?? '');
    $new_used   = trim($input['new_used'] ?? 'USED');
    $user_id    = (int)(current_user()['id'] ?? 0);

    if ($session_id <= 0) json_response(['ok' => false, 'error' => 'session_required'], 400);
    if ($tag_number === '') json_response(['ok' => false, 'error' => 'tag_required'], 400);
    if ($asset_type === '') json_response(['ok' => false, 'error' => 'asset_type_required'], 400);
    if (!in_array($asset_type, ['medical','it','infrastructure','hvac','transport','furniture','other'])) {
        json_response(['ok' => false, 'error' => 'invalid_asset_type'], 400);
    }
    if (!in_array($new_used, ['NEW','USED'])) {
        json_response(['ok' => false, 'error' => 'invalid_new_used'], 400);
    }

    // التحقق من الجلسة
    $ss = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
    $ss->execute([$session_id]);
    $sess = $ss->fetch(PDO::FETCH_ASSOC);
    if (!$sess) json_response(['ok' => false, 'error' => 'session_not_found'], 404);
    if (!in_array($sess['status'], ['active', 'review'], true)) {
        json_response(['ok' => false, 'error' => 'session_not_active'], 403);
    }

    // التحقق من عدم تكرار tag_number
    $ex = $pdo->prepare("SELECT id FROM assets WHERE tag_number = ?");
    $ex->execute([$tag_number]);
    if ($ex->fetch()) {
        json_response(['ok' => false, 'error' => 'tag_exists', 'tag' => $tag_number], 409);
    }

    // قاعدة صلبة: السيريال فريد — نفس السيريال لا يُسجَّل على جهازين
    $serial_check = trim($input['serial_number'] ?? '');
    if ($serial_check !== '') {
        $exs = $pdo->prepare("SELECT id, tag_number FROM assets WHERE serial_number = ? LIMIT 1");
        $exs->execute([$serial_check]);
        if ($dup = $exs->fetch(PDO::FETCH_ASSOC)) {
            json_response([
                'ok'      => false,
                'error'   => 'serial_exists',
                'message' => $rtl
                    ? "هذا السيريال مسجّل مسبقاً على الأصل ({$dup['tag_number']}) — تحقق من الجهاز أو اطلب استثناء تبديل تاج من مدير الأصول."
                    : "This serial is already registered on asset {$dup['tag_number']} — verify the device or request a tag-swap exception.",
            ], 409);
        }
    }

    // تجميع البيانات
    $description       = trim($input['description'] ?? '');
    $description_ar    = trim($input['description_ar'] ?? '');
    $manufacturer_name = trim($input['manufacturer_name'] ?? '');
    $manufacturer_id   = (int)($input['manufacturer_id'] ?? 0) ?: null;
    $model_number      = trim($input['model_number'] ?? '');
    $model_id          = (int)($input['model_id'] ?? 0) ?: null;
    $serial_number     = trim($input['serial_number'] ?? '');
    $cat_seg1          = (int)($input['cat_seg1'] ?? 0) ?: null;
    $cat_seg2          = (int)($input['cat_seg2'] ?? 0) ?: null;
    $cat_seg3          = (int)($input['cat_seg3'] ?? 0) ?: null;
    $loc_building      = trim($input['loc_building'] ?? '');
    $loc_floor         = trim($input['loc_floor'] ?? '');
    $loc_room          = trim($input['loc_room'] ?? '');
    $loc_text          = trim($input['loc_text'] ?? '');
    $location_id       = (int)($input['location_id'] ?? 0) ?: null;
    $dept_id           = (int)($input['dept_id'] ?? 0) ?: null;
    // NUPCO Architecture (Migration 015): item_code + unit (for Form 8 aggregation)
    $item_code         = trim($input['item_code'] ?? '');
    $unit              = trim($input['unit'] ?? '');
    $generic_code      = trim($input['generic_code'] ?? '');
    // توحيد التنسيق: نحفظ NEW/USED بالإنجليزية (نفس assets/form.php)
    // الإصلاح (Bug #3): لا نحوّل إلى "جديد/مستعمل" بالعربي — الـ schema يتوقع إنجليزي
    $new_used_standard = $new_used === 'NEW' ? 'NEW' : 'USED';

    $mode = $input['mode'] ?? 'quick'; // 'quick' (Tab 1) or 'complete' (Tab 2)
    if ($mode === 'complete') {
        // الإكمال: كل الحقول الإلزامية في Tab 2
        $missing = [];
        if (!$description) $missing[] = 'الوصف';
        if (!$manufacturer_name) $missing[] = 'الشركة المصنّعة';
        if (!$model_number) $missing[] = 'الموديل';
        if (!$serial_number) $missing[] = 'السيريال';
        if (!$cat_seg1) $missing[] = 'الفئة (L1)';
        if ($missing) {
            json_response([
                'ok' => false,
                'error' => 'complete_mode_missing_fields',
                'missing' => $missing,
                'message' => 'في وضع "إكمال بيانات" كل الحقول إلزامية: ' . implode('، ', $missing),
            ], 400);
        }
    } else {
        // Quick: المبنى + الطابق + الغرفة كلها إلزامية (أو loc_text كبديل)
        if ((!$loc_building || !$loc_floor || !$loc_room) && !$loc_text) {
            json_response([
                'ok' => false,
                'error' => 'quick_location_required',
                'message' => 'في التسجيل السريع: اختر المبنى + الطابق + الغرفة، أو اكتب موقع نصّي في حقل "موقع تقريبي".',
            ], 400);
        }
    }

    // لو ما عندنا location_id لكن عندنا loc_text، نقسّمه على ">" و " - "
    if (!$location_id && $loc_text !== '' && !$loc_building && !$loc_floor && !$loc_room) {
        $parts = preg_split('/\s*(?:>|>>|،|,|-)\s*/u', $loc_text);
        $parts = array_map('trim', $parts);
        if (count($parts) >= 3) { $loc_building = $parts[0]; $loc_floor = $parts[1]; $loc_room = $parts[2]; }
        elseif (count($parts) === 2) { $loc_building = $parts[0]; $loc_floor = $parts[1]; }
        elseif (count($parts) === 1) { $loc_building = $parts[0]; }
    }

    // لو عندنا manufacturer_id، نضمن اسم الشركة
    if ($manufacturer_id && !$manufacturer_name) {
        $mn = $pdo->prepare("SELECT name_en, name FROM manufacturers WHERE id = ?");
        $mn->execute([$manufacturer_id]);
        $mr = $mn->fetch(PDO::FETCH_ASSOC);
        if ($mr) $manufacturer_name = $mr['name_en'] ?: $mr['name'];
    }
    // لو عندنا model_id، نضمن رقم الموديل
    if ($model_id && !$model_number) {
        $md = $pdo->prepare("SELECT model_number FROM manufacturer_models WHERE id = ?");
        $md->execute([$model_id]);
        $mm = $md->fetch(PDO::FETCH_ASSOC);
        if ($mm) $model_number = $mm['model_number'];
    }

    // حساب data_completeness
    $critical = 0; $filled = 0;
    foreach (['description', 'manufacturer_name', 'model_number', 'serial_number'] as $fld) {
        $critical++;
        if (!empty($$fld)) $filled++;
    }
    // الموقع (3 حقول نصية) يعدّ كمجموعة
    $critical++;
    if ($loc_building || $loc_floor || $loc_room || $location_id) $filled++;
    // التصنيف
    $critical++;
    if ($cat_seg1) $filled++;

    if ($filled === $critical)        $completeness = 'complete';
    elseif ($filled >= max(2, intdiv($critical, 2))) $completeness = 'partial';
    else                              $completeness = 'minimal';
    $incomplete_flag = ($completeness !== 'complete') ? 1 : 0;

    // ============================
    // خريطة إكمال البيانات (auto-assign based on asset_type)
    // ============================
    $completion_team = [];
    $cs = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'completion_team'");
    $cs->execute();
    $csr = $cs->fetch(PDO::FETCH_ASSOC);
    if ($csr && $csr['setting_value']) {
        $completion_team = json_decode($csr['setting_value'], true) ?: [];
    }
    $needs_completion_by = $completion_team[$asset_type] ?? null;

    // رسالة وأولوية بحسب الـ completeness
    $completion_notes = null;
    $completion_priority = null;
    if ($incomplete_flag) {
        $team_name = '';
        if ($needs_completion_by) {
            $un = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $un->execute([$needs_completion_by]);
            $team_name = $un->fetchColumn() ?: '';
        }
        $missing = [];
        if (!$description) $missing[] = 'الوصف';
        if (!$manufacturer_name) $missing[] = 'الشركة المصنّعة';
        if (!$model_number) $missing[] = 'الموديل';
        if (!$serial_number) $missing[] = 'السيريال';
        if (!($loc_building || $loc_floor || $loc_room || $location_id)) $missing[] = 'الموقع';
        if (!$cat_seg1) $missing[] = 'الفئة';
        $missing_list = $missing ? implode('، ', $missing) : 'تفاصيل إضافية';
        $completion_notes = "يحتاج تحقق شامل — مسجّل كـ " . ($completeness === 'minimal' ? 'بيانات أساسية فقط' : 'بيانات جزئية') . ".\nينقص: $missing_list";
        if ($team_name) $completion_notes .= "\nالمسؤول عن الإكمال: $team_name";
        $completion_priority = $completeness === 'minimal' ? 'high' : 'medium';
    }

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO assets
              (tag_number, description, description_ar, asset_type, new_used,
               manufacturer_name, model_number, serial_number,
               loc_building, loc_floor, loc_room, location_id,
               cat_seg1, cat_seg2, cat_seg3,
               item_code, unit, generic_code,
               department_id, status, criticality_class, criticality_level,
               data_completeness, incomplete_data, needs_completion_by,
               completion_notes, completion_priority,
               verified_status, verified_at, verified_by,
               created_by, created_at, updated_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
               ?, 'active', 'C', 'medium',
               ?, ?, ?,
               ?, ?,
               'تم التحقق (Surplus)', NOW(), ?,
               ?, NOW(), NOW())
        ");
        $ins->execute([
            $tag_number, $description ?: null, $description_ar ?: null, $asset_type, $new_used_standard,
            $manufacturer_name ?: null, $model_number ?: null, $serial_number ?: null,
            $loc_building ?: null, $loc_floor ?: null, $loc_room ?: null, $location_id,
            $cat_seg1, $cat_seg2, $cat_seg3,
            $item_code ?: null, $unit ?: null, $generic_code ?: null,
            $dept_id,
            $completeness, $incomplete_flag, $needs_completion_by,
            $completion_notes, $completion_priority,
            $user_id,
            $user_id,
        ]);

        $new_id = (int)$pdo->lastInsertId();

        // تسجيل في inventory_audits (surplus_registered)
        $aud = $pdo->prepare("
            INSERT INTO inventory_audits
              (session_id, asset_id, scanned_tag, scanned_serial, scan_method, match_method, action,
               audited_by, audited_at, device_info, condition_notes)
            VALUES
              (?, ?, ?, ?, 'manual', 'tag', 'surplus_registered', ?, NOW(), ?, ?)
        ");
        $aud->execute([
            $session_id, $new_id, $tag_number, $serial_number ?: null,
            $user_id,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            "Surplus registered via wizard. Completeness: $completeness",
        ]);

        $pdo->commit();

        json_response([
            'ok'               => true,
            'asset_id'         => $new_id,
            'tag_number'       => $tag_number,
            'data_completeness'=> $completeness,
            'incomplete_data'  => (bool)$incomplete_flag,
            'filled_count'     => $filled,
            'critical_count'   => $critical,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
    }
}

else {
    json_response(['ok' => false, 'error' => 'invalid_action', 'allowed' => ['search', 'register']], 400);
}