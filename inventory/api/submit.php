<?php
/**
 * inventory/api/submit.php — حفظ عملية الجرد (التحقق من جهاز موجود)
 * النسخة المحدثة والمستقرة: تتضمن ميزة (الكتابة فوق السجل) لمنع التكرار
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

// منع الوصول المباشر عبر المتصفح
if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// استقبال البيانات (يدعم JSON Payload)
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$session_id = (int)($input['session_id'] ?? 0);
$asset_id   = (int)($input['asset_id'] ?? 0);
$action     = trim($input['action'] ?? '');
$user_id    = (int)(current_user()['id'] ?? 0);

// التحقق من المدخلات الأساسية
if (!$session_id || !$asset_id || !$action || !$user_id) {
    json_response(['ok' => false, 'error' => 'بيانات مفقودة، لا يمكن إتمام الحفظ.']);
}

// 1. التحقق الصارم من حالة الجلسة
$ss = $pdo->prepare("SELECT status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess_status = $ss->fetchColumn();

if (!$sess_status || !in_array($sess_status, ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'الجلسة غير نشطة للمسح (موقوفة/مغلقة) — لا يمكن حفظ بيانات جرد الآن.']);
}
// 2. حارس العضوية: يمنع غير أعضاء لجنة الجلسة من حفظ أي بيانات
if (!inv_session_guard($session_id)) {
    log_activity('inventory.submit.denied', 'session:' . $session_id, 'user_not_member');
    json_response(['ok' => false, 'error' => 'أنت لست عضواً في لجنة الجرد لهذه الجلسة — لا يمكن حفظ البيانات.'], 403);
}   // ← هنا ينتهي حارس العضوية

// 2.5 ★ رادار المراقبة: منع العضو المعلَّق من الحفظ (بلوك مستقل بعده مباشرة)
require_once BASE_PATH . '/includes/session_controls.php';
if (smc_is_suspended($pdo, $session_id, (int)(current_user()['id'] ?? 0))) {
    json_response(['ok' => false, 'error' => 'مشاركتك في هذه الجلسة معلّقة من مدير الأصول — لا يمكن الحفظ.'], 403);
}

// 2.7 ★ نهاية الجلسة الزمنية (end_date): قفل تام بعد انتهاء المدة (قرار 2026-08-23)
$endState = inv_session_end_state($pdo, $session_id);
if ($endState['ended']) {
    json_response(['ok' => false, 'error' => 'session_ended',
        'message' => 'انتهت صلاحية جلسة الجرد بتاريخ ' . $endState['end_at'] . ' — تواصل مع مدير الأصول للتمديد.'], 403);
}

// 2.8 ★ قاعدة صارمة: لا إعادة جرد جهاز سبق جرده في نفس الجلسة (قرار 2026-08-23)
//     الاستثناء الوحيد: إعداد inv_allow_re_audit مفعّل للفريق، أو استثناء معتمد لهذا الموظف
$allow_re_audit = get_effective_setting($pdo, $session_id, (int)$user_id, 'inv_allow_re_audit', '0') === '1';
$prevSt = $pdo->prepare("SELECT ia.id, ia.audited_at, u.full_name
    FROM inventory_audits ia LEFT JOIN users u ON u.id = ia.audited_by
    WHERE ia.session_id = ? AND ia.asset_id = ? AND ia.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously') LIMIT 1");
$prevSt->execute([$session_id, $asset_id]);
$prevAudit = $prevSt->fetch(PDO::FETCH_ASSOC);
if ($prevAudit && !$allow_re_audit) {
    $apr = $pdo->prepare("SELECT 1 FROM inventory_reaudit_requests
        WHERE session_id = ? AND asset_id = ? AND requested_by = ? AND status = 'approved' LIMIT 1");
    $apr->execute([$session_id, $asset_id, $user_id]);
    if (!$apr->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'already_audited',
            'message' => 'هذا الجهاز سبق جرده في هذه الجلسة بواسطة «' . ($prevAudit['full_name'] ?: '—') . '»'
                . ' بتاريخ ' . ($prevAudit['audited_at'] ?: '—') . ' — لا يمكن جرده مرة أخرى. اطلب استثناء من مدير الأصول إن لزم.',
            'audited_by' => $prevAudit['full_name'], 'audited_at' => $prevAudit['audited_at'],
        ], 409);
    }
}

// استقبال المتغيرات الأخرى وتنظيفها
$scanned_tag = $input['scanned_tag'] ?? null;
$scanned_serial = $input['scanned_serial'] ?? null;
$scan_method = $input['scan_method'] ?? 'manual';
$match_method = $input['match_method'] ?? 'manual_search';

$new_serial = $input['new_serial'] ? trim($input['new_serial']) : null;
$new_health_score = isset($input['new_health_score']) && $input['new_health_score'] !== '' ? (int)$input['new_health_score'] : null;
$new_status = $input['new_status'] ?? null;
/* الحالة العامة — قائمة مسموحة صارمة: الجرد يقيّم ثلاث حالات تشغيلية فقط؛
   بقية قيم status (pending_*, disposed, transferred...) دورات حياة
   لا يقررها الجرد ولا يجوز تمريرها من هنا حتى بطلب مُعدَّل يدوياً */
if (!in_array($new_status, ['active', 'under_maintenance', 'inactive'], true)) {
    $new_status = null;
}

/* ════ 2.95 قاعدة صارمة: لا تكرار للسيريال في النظام كله ════
   (قيود UNIQUE في DB هي الضمانة النهائية — هذا الفحص يعطي رسالة واضحة بدل خطأ SQL) */
if ($new_serial !== null && $new_serial !== '') {
    $dupSt = $pdo->prepare("SELECT id, tag_number FROM assets WHERE serial_number = ? AND id <> ? LIMIT 1");
    $dupSt->execute([$new_serial, $asset_id]);
    if ($dup = $dupSt->fetch(PDO::FETCH_ASSOC)) {
        json_response(['ok' => false, 'error' => 'duplicate_serial',
            'message' => "هذا السيريال مسجل مسبقاً للأصل رقم {$dup['id']} (تاج: {$dup['tag_number']}) — لا يمكن الحفظ. اطلب استثناء إن رأيت تداخلاً."], 409);
    }
}
$new_location_id = isset($input['new_location_id']) && $input['new_location_id'] ? (int)$input['new_location_id'] : null;

/* ════ 2.9 قاعدة الغرفة المُلزِمة لنقل الموقع (فجوتا #7/#8 — قرار 2026-08-23) ════
   الجهاز يُنقل فقط إلى الغرفة التي يمتلك الموظف فيها قفلاً نشطاً،
   وبتحقق نطاق الفريق — لا سحب أجهزة من/إلى غرف خارج نطاق الموظف */
if ($action === 'location_changed') {
    $lkSt = $pdo->prepare("SELECT id, room_id FROM room_inventory_locks
        WHERE session_id = ? AND locked_by = ? AND status = 'active' LIMIT 1");
    $lkSt->execute([$session_id, $user_id]);
    $lock = $lkSt->fetch(PDO::FETCH_ASSOC);
    if (!$lock) {
        json_response(['ok' => false, 'error' => 'no_active_lock',
            'message' => 'نقل الموقع يتطلب وجودك داخل غرفة بجلسة نشطة — افتح الغرفة أولاً.'], 403);
    }
    if (!$new_location_id || (int)$new_location_id !== (int)$lock['room_id']) {
        json_response(['ok' => false, 'error' => 'dest_not_current_room',
            'message' => 'لا يمكن نقل الجهاز إلا إلى الغرفة المفتوحة حالياً لدى الموظف.'], 422);
    }
    if (!rl_check_team_scope($pdo, $session_id, (int)$user_id, (int)$new_location_id)) {
        json_response(['ok' => false, 'error' => 'room_out_of_scope',
            'message' => 'الغرفة الوجهة خارج نطاق فريقك المحدد.'], 403);
    }
}

$condition_notes = $input['condition_notes'] ?? null;
$device_info = substr($input['device_info'] ?? '', 0, 250);

/* ════ حواجز اكتمال بيانات "الجهاز موجود أمامي" ════
   أعلام التحقق الصريح ترسلها الواجهة بناءً على تفاعل الموظف الفعلي
   (زر تأكيد الموقع + اختيار الحالة الفنية). تحقق الواجهة إرشاد لطيف
   يمكن تجاوزه فنياً — هذه الطبقة هي الحاجز الحقيقي الذي لا يُتجاوز.
   لا تنطبق على المفقود/المُكهَّن (لا جهاز أمام الموظف ليُفحص). */
$location_confirmed = !empty($input['location_confirmed']);
$health_confirmed   = !empty($input['health_confirmed']);

if (in_array($action, ['confirmed', 'condition_damaged'], true)) {
    $ax = $pdo->prepare("SELECT serial_number FROM assets WHERE id = ?");
    $ax->execute([$asset_id]);
    $existing_serial = $ax->fetchColumn();

    $missing = [];
    if (empty($existing_serial) && empty($new_serial)) $missing[] = 'السيريال';
    if (!$location_confirmed && empty($new_location_id)) $missing[] = 'تأكيد الموقع';
    if (!$health_confirmed) $missing[] = 'الحالة الفنية';

    if ($missing) {
        json_response([
            'ok'      => false,
            'error'   => 'incomplete_presence_data',
            'message' => 'لا يمكن تأكيد الجرد بدون إكمال: ' . implode('، ', $missing),
        ], 422);
    }
}

try {
    // بدء المعاملة لضمان تكامل البيانات
    $pdo->beginTransaction();

    /* ════ 2. تحديث جدول الأصول الأساسي (Assets Table) ════ */
    
    // أ) إذا كان الإجراء تأكيداً أو تحديثاً للحالة أو الموقع
    if (in_array($action, ['confirmed', 'condition_damaged', 'location_changed', 'custody_changed'])) {
        
        $update_fields = [
            "verified_status = 'تم التحقق'",
            "verified_at = NOW()"
        ];
        $params = [];

        if ($new_serial) {
            $update_fields[] = "serial_number = ?";
            $params[] = $new_serial;
        }
        if ($new_health_score !== null) {
            $update_fields[] = "health_score = ?";
            $params[] = $new_health_score;
        }
        if ($new_status) {
            $update_fields[] = "status = ?";
            $params[] = $new_status;
        }
        if ($new_location_id) {
            $update_fields[] = "location_id = ?";
            $params[] = $new_location_id;
        }

        $sql = "UPDATE assets SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $params[] = $asset_id;
        
        $st = $pdo->prepare($sql);
        $st->execute($params);

    // ب) إذا كان الجهاز مفقوداً أو مكهناً
    } elseif (in_array($action, ['missing', 'missing_disposed_previously'])) {
        // نكتفي بتحديث حالة التحقق إلى "مفقود" دون العبث بموقعه الأخير المسجل أو بياناته الفنية
        $st = $pdo->prepare("UPDATE assets SET verified_status = 'مفقود', verified_at = NOW() WHERE id = ?");
        $st->execute([$asset_id]);
    }

    /* ════ 3. التحديث الجوهري لجدول الجرد (Audits Table) ════ */
    // الهدف: منع تكدس السجلات للجهاز الواحد في الجلسة الواحدة (الكتابة فوق السجل القديم إن وجد)
    
    // التحقق هل تم جرد هذا الجهاز مسبقاً في هذه الجلسة؟
    $check = $pdo->prepare("SELECT id FROM inventory_audits WHERE session_id = ? AND asset_id = ? LIMIT 1");
    $check->execute([$session_id, $asset_id]);
    $existing_audit_id = $check->fetchColumn();

    $final_serial = $new_serial ?: $scanned_serial; // نعتمد السيريال الجديد إن تم إدخاله، وإلا السيريال القديم

    if ($existing_audit_id) {
        // تحديث السجل السابق (Overwrite)
        $st = $pdo->prepare("
            UPDATE inventory_audits 
            SET scanned_tag = ?, 
                scanned_serial = ?, 
                scan_method = ?, 
                match_method = ?, 
                action = ?, 
                audited_by = ?, 
                audited_at = NOW(), 
                condition_notes = ?, 
                device_info = ?
            WHERE id = ?
        ");
        $st->execute([
            $scanned_tag, 
            $final_serial, 
            $scan_method, 
            $match_method, 
            $action, 
            $user_id, 
            $condition_notes, 
            $device_info, 
            $existing_audit_id
        ]);
    } else {
        // إدخال سجل جرد جديد (لأول مرة)
        $st = $pdo->prepare("
            INSERT INTO inventory_audits 
              (session_id, asset_id, scanned_tag, scanned_serial, scan_method, match_method, action, audited_by, audited_at, condition_notes, device_info)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        $st->execute([
            $session_id, 
            $asset_id, 
            $scanned_tag, 
            $final_serial, 
            $scan_method, 
            $match_method, 
            $action, 
            $user_id, 
            $condition_notes, 
            $device_info
        ]);
    }

    // اعتماد التغييرات
    $pdo->commit();
    json_response(['ok' => true]);

} catch (Exception $e) {
    // إلغاء كل شيء في حال حدوث خطأ
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    // إرجاع رسالة الخطأ للواجهة
    json_response([
        'ok' => false, 
        'error' => 'خطأ داخلي أثناء الحفظ: ' . $e->getMessage()
    ]);
}