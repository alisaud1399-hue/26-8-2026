<?php
/**
 * inventory/api/room_lock.php — إدارة أقفال الغرف (تسجيل وصول / تسلّم / تعليق / إقفال)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = trim($input['action'] ?? '');
$session_id = (int)($input['session_id'] ?? 0);
$room_id = (int)($input['room_id'] ?? 0);
$me = (int)(current_user()['id'] ?? 0);
if (!$me) json_response(['ok'=>false,'error'=>'no_user'], 401);
if (!$session_id) json_response(['ok'=>false,'error'=>'missing']);
/* بعض الأكشن (resolve) لا تحتاج room_id — التحقق لاحقاً لكل حالة */

$ss = $pdo->prepare("SELECT status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess_status = $ss->fetchColumn();
if (!$sess_status) json_response(['ok'=>false,'error'=>'no_session']);
if ($sess_status !== 'active') json_response(['ok'=>false,'error'=>'session_not_active','status'=>$sess_status]);
if (!inv_session_guard($session_id)) json_response(['ok'=>false,'error'=>'not_member'], 403);
/* ── رادار المراقبة: فرض التعليق والمنع من غرفة ── */
require_once BASE_PATH.'/includes/session_controls.php';
if (smc_is_suspended($pdo,$session_id,$me)) json_response(['ok'=>false,'error'=>'member_suspended','msg'=>'مشاركتك في هذه الجلسة معلّقة من مدير الأصول.'], 403);
if (in_array($action,['checkin','takeover'],true) && $room_id>0 && smc_is_room_blocked($pdo,$session_id,$me,$room_id))
    json_response(['ok'=>false,'error'=>'room_blocked','msg'=>'غير مسموح لك بدخول هذه الغرفة — راجع مدير الأصول.'], 403);

/* ═══ انتهاء الصلاحية التلقائية ═══ لا يوجد cron، نتحقق عند كل طلب */
$expiredLocks = $pdo->query("SELECT id, session_id, room_id, locked_by FROM room_inventory_locks WHERE status='active' AND expires_at IS NOT NULL AND expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiredLocks as $el) {
    $pdo->prepare("UPDATE room_inventory_locks SET status='expired', note='انتهت الصلاحية تلقائياً' WHERE id=?")->execute([$el['id']]);
    $pdo->prepare("INSERT INTO room_lock_events (lock_id,session_id,room_id,actor_id,event_type,note) VALUES(?,?,?,NULL,'expired','انتهت الصلاحية تلقائياً')")->execute([$el['id'], $el['session_id'], $el['room_id']]);
}

$mode = get_effective_setting($pdo, $session_id, $me, 'inv_parallel_mode', 'off');
$par_rooms = json_decode(get_effective_setting($pdo, $session_id, $me, 'inv_parallel_rooms', '[]'), true) ?: [];
$parallel = ($mode==='all') || ($mode==='selected' && in_array($room_id, array_map('intval',$par_rooms), true));
/* إعدادات الجرد من طبقتين: team → global (المرجع: create.php + system_settings) */
$allow_takeover      = get_effective_setting($pdo, $session_id, $me, 'inv_allow_takeover', '1') === '1';
$require_oath        = get_effective_setting($pdo, $session_id, $me, 'inv_require_oath_complete', '1') === '1';
/* مهلة الأقفال = شبكة أمان للغرف المتروكة فقط (قرار 2026-08-23: الوقت ليس أداة عقاب) */
$lock_timeout_min    = (int)get_effective_setting($pdo, $session_id, $me, 'inv_lock_timeout_min', '480');
$max_suspend_count   = (int)get_effective_setting($pdo, $session_id, $me, 'inv_max_suspend_count', '3');
$max_locks_per_user  = (int)get_effective_setting($pdo, $session_id, $me, 'inv_max_locks_per_user', '1');
$block_undoc         = get_effective_setting($pdo, $session_id, $me, 'inv_block_audit_undocumented_room', '1') === '1';
$dept_required       = get_effective_setting($pdo, $session_id, $me, 'inv_dept_required_before_lock', '1') === '1';
$allow_multi_room    = get_effective_setting($pdo, $session_id, $me, 'inv_allow_multi_room_before_close', '0') === '1';
$member_team_id      = get_member_team_id($pdo, $session_id, $me);

function rl_log($pdo,$lock_id,$session_id,$room_id,$actor,$type,$note=null){
    $pdo->prepare("INSERT INTO room_lock_events (lock_id,session_id,room_id,actor_id,event_type,note) VALUES (?,?,?,?,?,?)")
        ->execute([$lock_id,$session_id,$room_id,$actor,$type,$note]);
}
function rl_active_locks($pdo,$session_id,$room_id){
    $st=$pdo->prepare("SELECT l.*, u.full_name FROM room_inventory_locks l LEFT JOIN users u ON u.id=l.locked_by
        WHERE l.session_id=? AND l.room_id=? AND l.status='active'");
    $st->execute([$session_id,$room_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function rl_completed($pdo,$session_id,$room_id){
    $st=$pdo->prepare("SELECT l.*, u.full_name FROM room_inventory_locks l LEFT JOIN users u ON u.id=l.locked_by
        WHERE l.session_id=? AND l.room_id=? AND l.status='completed' LIMIT 1");
    $st->execute([$session_id,$room_id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function rl_my_other_active($pdo,$session_id,$me,$except_room,$include_suspended=true){
    $st=$pdo->prepare("SELECT l.*, r.name rname, r.name_en rname_en FROM room_inventory_locks l
        LEFT JOIN item_locations r ON r.id=l.room_id
        WHERE l.session_id=? AND l.locked_by=? AND l.status='active' AND l.room_id<>?");
    $st->execute([$session_id,$me,$except_room]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $L) {
        /* عند السماح بتعدد الغرف: القفل المعلّق لا يحجب فتح غرفة جديدة */
        if (!$include_suspended && rl_lock_is_suspended_now($pdo,(int)$L['id'])) continue;
        return $L;
    }
    return false;
}

/**
 * حساب الوقت التراكمي (بالثواني) لمستخدم معين في غرفة معينة خلال جلسة.
 * يجمع كل الفترات النشطة من room_lock_events (opened→suspended, resumed→suspended, resumed→completed, إلخ).
 */
function rl_cumulative_seconds($pdo, int $session_id, int $room_id, int $user_id): int {
    $total = 0;
    /* جلب جميع الأقفال لهذه الغرفة+المستخدم بالترتيب */
    $locks = $pdo->prepare("SELECT id, locked_at, resumed_at, completed_at, status
        FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? ORDER BY id");
    $locks->execute([$session_id, $room_id, $user_id]);
    foreach ($locks->fetchAll(PDO::FETCH_ASSOC) as $lk) {
        /* جلب أحداث هذا القفل بالترتيب */
        $evts = $pdo->prepare("SELECT event_type, created_at FROM room_lock_events WHERE lock_id=? ORDER BY id");
        $evts->execute([(int)$lk['id']]);
        $events = $evts->fetchAll(PDO::FETCH_ASSOC);

        /* نحتاج المتسلسل: opened → [resumed → suspended]* → [completed|expired] */
        $started_at = null;
        foreach ($events as $ev) {
            $type = $ev['event_type'];
            $time = strtotime($ev['created_at']);
            if ($type === 'opened' || $type === 'resumed') {
                $started_at = $time;
            } elseif (in_array($type, ['suspended','completed','taken_over','expired']) && $started_at !== null) {
                $total += max(0, $time - $started_at);
                $started_at = null;
            }
        }
        /* إذا القفل لا يزال نشطاً ولم يُعلّق بعد */
        if ($started_at !== null && $lk['status'] === 'active') {
            $total += max(0, time() - $started_at);
        }
    }
    return $total;
}

/**
 * تحقق من أن المستخدم مسموح له بالدخول للغرفة بناءً على نطاق فريقه.
 * (النسخة المشتركة معرّفة في includes/session_controls.php — rl_check_team_scope)
 */

switch ($action) {

case 'resolve': { // تحويل كود QR الغرفة إلى id
    $code = trim($input['code'] ?? '');
    if ($code === '') json_response(['ok'=>false,'error'=>'no_code']);
    if (strpos($code,'code=') !== false) { $p = explode('code=',$code); $code = urldecode(end($p)); }
    $st = $pdo->prepare("SELECT id FROM item_locations WHERE location_code=? AND location_type='room' LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    if (!$id) json_response(['ok'=>false,'error'=>'room_not_found']);
    if ($session_id && $me && !(is_admin() || can_see_all()) && !rl_check_team_scope($pdo, $session_id, $me, (int)$id)) {
        json_response(['ok'=>false,'error'=>'room_out_of_scope','msg'=>'هذه الغرفة خارج نطاق فريقك المحدد']);
    }
    json_response(['ok'=>true,'room_id'=>(int)$id]);
}

case 'preview': {
    if (!$room_id) json_response(['ok'=>false,'error'=>'missing_room']);
    /* فحص نطاق الفريق أولاً */
    if ($session_id && $me && !(is_admin() || can_see_all()) && !rl_check_team_scope($pdo, $session_id, $me, $room_id)) {
        json_response(['ok'=>false,'error'=>'room_out_of_scope','msg'=>'هذه الغرفة خارج نطاق فريقك المحدد']);
    }
    /* جلب بيانات الغرفة */
    $rq=$pdo->prepare("SELECT r.id, r.name, r.name_en, f.name f_name, f.name_en f_name_en, b.name b_name, b.name_en b_name_en
        FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
    $rq->execute([$room_id]); $room=$rq->fetch(PDO::FETCH_ASSOC);
    if (!$room) json_response(['ok'=>false,'error'=>'room_not_found']);

    /* هل الغرفة مكتملة مسبقاً؟ */
    $done = rl_completed($pdo,$session_id,$room_id);

    /* هل المستخدم له قفل سابق تم تعليقه في هذه الغرفة؟ (يعني عاد بعد تعليق) */
    $prev = $pdo->prepare("SELECT e.created_at, l.resumed_at, l.completed_at
        FROM room_lock_events e JOIN room_inventory_locks l ON l.id=e.lock_id
        WHERE l.session_id=? AND l.room_id=? AND l.locked_by=? AND e.event_type='suspended'
        ORDER BY e.id DESC LIMIT 1");
    $prev->execute([$session_id,$room_id,$me]);
    $prevSuspend = $prev->fetch(PDO::FETCH_ASSOC);

    /* هل يوجد قفل نشط حالياً بنفس الغرفة للمستخدم؟ */
    $myActive = $pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status='active'");
    $myActive->execute([$session_id,$room_id,$me]);
    $hasActive = (bool)$myActive->fetchColumn();

    /* إجمالي الأصول في الموقع (بناءً على location_id) — قد لا تكون صحيحة */
    $assetTotal = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id=? AND status='active'");
    $assetTotal->execute([$room_id]);
    $assetTotal = (int)$assetTotal->fetchColumn();

    $byType = $pdo->prepare("SELECT asset_type, COUNT(*) c FROM assets WHERE location_id=? AND status='active' GROUP BY asset_type");
    $byType->execute([$room_id]);
    $typeBreakdown = [];
    foreach ($byType->fetchAll(PDO::FETCH_ASSOC) as $r) $typeBreakdown[$r['asset_type']] = (int)$r['c'];

    /* الأصول الموثّقة فعلياً: لها سجل جرد متحقق (confirmed/damaged/location_changed/custody_changed)
       في أي جلسة سابقة مع تحديث verified_status */
    $verifiedCount = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id=? AND status='active' AND verified_status IS NOT NULL");
    $verifiedCount->execute([$room_id]);
    $verifiedCount = (int)$verifiedCount->fetchColumn();

    $verifiedByType = $pdo->prepare("SELECT asset_type, COUNT(*) c FROM assets WHERE location_id=? AND status='active' AND verified_status IS NOT NULL GROUP BY asset_type");
    $verifiedByType->execute([$room_id]);
    $verifiedTypeBreakdown = [];
    foreach ($verifiedByType->fetchAll(PDO::FETCH_ASSOC) as $r) $verifiedTypeBreakdown[$r['asset_type']] = (int)$r['c'];

    /* كم أصل تم جرده فعلاً في هذه الجلسة الحالية من هذه الغرفة */
    $auditedCount = $pdo->prepare("SELECT COUNT(DISTINCT a.asset_id) FROM inventory_audits a
        JOIN assets ast ON ast.id = a.asset_id
        WHERE a.session_id=? AND ast.location_id=? AND a.asset_id IS NOT NULL
        AND a.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')");
    $auditedCount->execute([$session_id, $room_id]);
    $auditedCount = (int)$auditedCount->fetchColumn();

    /* التفصيل المجرود حسب النوع */
    $auditedByType = $pdo->prepare("SELECT ast.asset_type, COUNT(DISTINCT a.asset_id) c
        FROM inventory_audits a JOIN assets ast ON ast.id = a.asset_id
        WHERE a.session_id=? AND ast.location_id=? AND a.asset_id IS NOT NULL
        AND a.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')
        GROUP BY ast.asset_type");
    $auditedByType->execute([$session_id, $room_id]);
    $auditedTypeBreakdown = [];
    foreach ($auditedByType->fetchAll(PDO::FETCH_ASSOC) as $r) $auditedTypeBreakdown[$r['asset_type']] = (int)$r['c'];

    /* عدد الأعضاء الحاليين في الغرفة (أقفال نشطة) */
    $currentUsers = $pdo->prepare("SELECT l.locked_by, u.full_name FROM room_inventory_locks l
        JOIN users u ON u.id=l.locked_by WHERE l.session_id=? AND l.room_id=? AND l.status='active'");
    $currentUsers->execute([$session_id, $room_id]);
    $currentUsersList = [];
    foreach ($currentUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ((int)$u['locked_by'] !== $me) {
            $currentUsersList[] = $u['full_name'];
        }
    }

    /* هل يوجد طلب إعادة فتح معلّق لهذه الغرفة؟ */
    $prq = $pdo->prepare("SELECT r.requested_by, u.full_name
        FROM inventory_reaudit_requests r
        LEFT JOIN users u ON u.id = r.requested_by
        WHERE r.session_id=? AND r.room_id=? AND r.request_type='re_audit_room' AND r.status='pending'
        LIMIT 1");
    $prq->execute([$session_id, $room_id]);
    $pendingReq = $prq->fetch(PDO::FETCH_ASSOC);

    json_response(['ok'=>true,
        'room'=>$room,
        'completed'=>(bool)$done,
        'completed_by'=>$done?($done['full_name']??''):null,
        'returning'=>(bool)$prevSuspend && !$hasActive,
        'last_suspended'=>$prevSuspend?($prevSuspend['created_at']??null):null,
        'has_active_lock'=>$hasActive,
        'asset_count'=>$assetTotal,
        'asset_by_type'=>$typeBreakdown,
        'verified_count'=>$verifiedCount,
        'verified_by_type'=>$verifiedTypeBreakdown,
        'audited_count'=>$auditedCount,
        'audited_by_type'=>$auditedTypeBreakdown,
        'current_users'=>$currentUsersList,
        'pending_request'=>(bool)$pendingReq,
        'pending_request_by'=>$pendingReq?($pendingReq['full_name']??''):null,
    ]);
}

case 'status': {
    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $mine=null; $other=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    $done = rl_completed($pdo,$session_id,$room_id);
    $rq=$pdo->prepare("SELECT r.name, r.name_en, f.name f_name, f.name_en f_name_en, b.name b_name, b.name_en b_name_en
        FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
    $rq->execute([$room_id]); $room=$rq->fetch(PDO::FETCH_ASSOC);
    $users=array_map(fn($L)=>['name'=>$L['full_name']??'','me'=>(int)$L['locked_by']===$me,'at'=>$L['locked_at'],'resumed_at'=>$L['resumed_at']??null],$locks);
    $sc=$pdo->prepare("SELECT COUNT(*) FROM room_lock_events e JOIN room_inventory_locks l ON l.id=e.lock_id WHERE l.session_id=? AND l.locked_by=? AND e.event_type='suspended'");
    $sc->execute([$session_id,$me]); $suspend_count=(int)$sc->fetchColumn();

    /* الوقت التراكمي لكل مستخدم في هذه الغرفة */
    $cumulative_by_user = [];
    foreach ($locks as $L) {
        $uid = (int)$L['locked_by'];
        if (!isset($cumulative_by_user[$uid])) {
            $cumulative_by_user[$uid] = rl_cumulative_seconds($pdo, $session_id, $room_id, $uid);
        }
    }
    /* دمج مع بيانات المستخدمين */
    $users_out = [];
    foreach ($users as &$u) {
        $uid = null;
        foreach ($locks as $L) {
            if (($u['me'] && (int)$L['locked_by'] === $me) || (!$u['me'] && $u['name'] === ($L['full_name'] ?? ''))) {
                $uid = (int)$L['locked_by'];
                break;
            }
        }
        $u['cumulative_sec'] = $uid ? ($cumulative_by_user[$uid] ?? 0) : 0;
        $users_out[] = $u;
    }
    unset($u);

    json_response(['ok'=>true,'completed'=>(bool)$done,'completed_by'=>$done?($done['full_name']??''):null,
        'mine'=>(bool)$mine,'other'=>$other?['name'=>$other['full_name'],'at'=>$other['locked_at']]:null,'parallel'=>$parallel,
        'room'=>$room?:null,'users'=>$users_out,'suspend_count'=>$suspend_count,
        'my_lock'=>$mine?['locked_at'=>$mine['locked_at'],'resumed_at'=>$mine['resumed_at'],'expires_at'=>$mine['expires_at']]:null]);
}

case 'checkin': {
    /* end_date هو الحد الأعلى الحقيقي للجلسة — لا دخول جديد بعد انتهائها (قرار 2026-08-23) */
    $endState = inv_session_end_state($pdo, $session_id);
    if ($endState['ended']) {
        json_response(['ok'=>false,'error'=>'session_ended','msg'=>'انتهت صلاحية جلسة الجرد بتاريخ '.$endState['end_at'].' — تواصل مع مدير الأصول للتمديد.'], 403);
    }
    $done = rl_completed($pdo,$session_id,$room_id);
    if ($done) {
        /* هل هناك طلب إعادة فتح معتمد لهذه الغرفة؟ */
        $ra = $pdo->prepare("SELECT id FROM inventory_reaudit_requests
            WHERE session_id=? AND room_id=? AND request_type='re_audit_room' AND status='approved' LIMIT 1");
        $ra->execute([$session_id, $room_id]);
        if ($ra->fetch()) {
            /* الموافقة: نُغلق القفل المكتمل ونسمح بالدخول من جديد */
            $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note='أُعيد فتحها بموجب طلب استثناء معتمد' WHERE id=?")->execute([(int)$done['id']]);
            rl_log($pdo,(int)$done['id'],$session_id,$room_id,$me,'taken_over','إعادة فتح غرفة بموجب طلب معتمد');
        } else {
            json_response(['ok'=>false,'error'=>'room_completed','by'=>$done['full_name']??'','msg'=>'هذه الغرفة مُغلقة — اطلب إعادة فتحها من مدير الأصول']);
        }
    }

    /* تحقق من نطاق الفريق */
    if (!rl_check_team_scope($pdo, $session_id, $me, $room_id)) {
        json_response(['ok'=>false,'error'=>'room_out_of_scope','msg'=>'هذه الغرفة خارج نطاق فريقك المحدد']);
    }

    /* قاعدة صارمة: الغرفة لازم تكون موثقة (dept + location_code) قبل الجرد */
    if ($block_undoc || $dept_required) {
        $st = $pdo->prepare("SELECT dept_id, dept_root_id, parse_status, location_code FROM item_locations WHERE id=? AND location_type='room'");
        $st->execute([$room_id]);
        $room = $st->fetch(PDO::FETCH_ASSOC);
        if (!$room) json_response(['ok'=>false,'error'=>'room_not_found']);
        if ($dept_required && (empty($room['dept_id']) || empty($room['dept_root_id']) || ($room['parse_status'] ?? '') !== 'verified')) {
            json_response(['ok'=>false,'error'=>'room_not_verified','msg'=>'الغرفة غير موثقة (بدون قسم مرتبط) — وثقها من إدارة المواقع أولاً']);
        }
        if ($block_undoc && empty($room['location_code'])) {
            json_response(['ok'=>false,'error'=>'room_undocumented','msg'=>'الغرفة بدون تكويد رقمي — أضف location_code من الترميز']);
        }
    }

    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $mine=null; $other=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    if ($mine) {
        $pdo->prepare("UPDATE room_inventory_locks SET resumed_at=NOW() WHERE id=?")->execute([$mine['id']]);
        rl_log($pdo,$mine['id'],$session_id,$room_id,$me,'resumed');
        json_response(['ok'=>true,'result'=>'resumed','lock_id'=>(int)$mine['id']]);
    }
    $otherRoom = rl_my_other_active($pdo,$session_id,$me,$room_id, !$allow_multi_room);
    if ($otherRoom) {
        $roomLbl = $otherRoom['rname_en'] ?: $otherRoom['rname'];
        $isSusp  = rl_lock_is_suspended_now($pdo,(int)$otherRoom['id']);
        $msg = $isSusp && !$allow_multi_room
            ? "أنت ما زلت معلقاً في الغرفة «{$roomLbl}» — ادخل عليها وأكمل الجرد أو أقفلها نهائياً قبل الانتقال لغرفة أخرى"
            : "لديك غرفة مفتوحة «{$roomLbl}» — أكمل جرد الغرفة أو أقفلها أولاً";
        json_response(['ok'=>false,'error'=>'has_other_lock','room'=>$roomLbl,'msg'=>$msg]);
    }
    if ($other && !$parallel) json_response(['ok'=>false,'error'=>'needs_takeover','by'=>$other['full_name']??'','at'=>$other['locked_at']]);

    /* قاعدة: الحد الأقصى للأقفال لكل موظف (المعلّق لا يُحتسب إذا سُمح بتعدد الغرف) */
    if ($max_locks_per_user >= 1) {
        $st = $pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND locked_by=? AND status='active'");
        $st->execute([$session_id, $me]);
        $cur_count = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lkid) {
            if ($allow_multi_room && rl_lock_is_suspended_now($pdo,(int)$lkid)) continue;
            $cur_count++;
        }
        if ($cur_count >= $max_locks_per_user) {
            json_response(['ok'=>false,'error'=>'max_locks_reached','limit'=>$max_locks_per_user,'msg'=>"تجاوزت الحد ($max_locks_per_user غرفة) — أنهِ أو علّق غرفة أولاً"]);
        }
    }

    /* حساب expiry_at بناءً على lock_timeout_min */
    $expiry_sql = $lock_timeout_min > 0 ? "DATE_ADD(NOW(), INTERVAL $lock_timeout_min MINUTE)" : "NULL";
    $pdo->prepare("DELETE FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status != 'active'")
        ->execute([$session_id,$room_id,$me]);
    $pdo->prepare("INSERT INTO room_inventory_locks (session_id,room_id,locked_by,status,team_id,expires_at) VALUES (?,?,?,'active',?,$expiry_sql)")
        ->execute([$session_id,$room_id,$me,$member_team_id]);
    $lid = (int)$pdo->lastInsertId();
    rl_log($pdo,$lid,$session_id,$room_id,$me,'opened',($parallel && $other)?'check-in مشترك':null);
    json_response(['ok'=>true,'result'=>'opened','lock_id'=>$lid,'expires_in_min'=>$lock_timeout_min]);
}

case 'takeover': {
    if (!$allow_takeover) json_response(['ok'=>false,'error'=>'takeover_disabled','msg'=>'الاستلام معطّل من إعدادات الجرد']);
    /* لا استلام بعد انتهاء صلاحية الجلسة */
    $endState = inv_session_end_state($pdo, $session_id);
    if ($endState['ended']) {
        json_response(['ok'=>false,'error'=>'session_ended','msg'=>'انتهت صلاحية جلسة الجرد بتاريخ '.$endState['end_at'].' — تواصل مع مدير الأصول للتمديد.'], 403);
    }
    if (!rl_check_team_scope($pdo, $session_id, $me, $room_id)) {
        json_response(['ok'=>false,'error'=>'room_out_of_scope','msg'=>'هذه الغرفة خارج نطاق فريقك المحدد']);
    }
    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $other=null; $mine=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    $otherRoom = rl_my_other_active($pdo,$session_id,$me,$room_id, !$allow_multi_room);
    if ($otherRoom) json_response(['ok'=>false,'error'=>'has_other_lock','room'=>($otherRoom['rname_en']?:$otherRoom['rname'])]);
    try {
        $pdo->beginTransaction();
        $taken_from_user = null;
        $taken_from_name = null;
        if ($other) {
            $taken_from_user = (int)$other['locked_by'];
            $taken_from_name = $other['full_name'] ?? '';
            $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note='سُحب القفل بواسطة موظف آخر' WHERE id=?")->execute([$other['id']]);
            rl_log($pdo,$other['id'],$session_id,$room_id,$me,'taken_over','من '.($other['full_name']??''));
        }
        if ($mine) {
            $pdo->prepare("UPDATE room_inventory_locks SET status='active', resumed_at=NOW() WHERE id=?")->execute([$mine['id']]);
            $lid = (int)$mine['id'];
        } else {
            $pdo->prepare("INSERT INTO room_inventory_locks (session_id,room_id,locked_by,status,team_id) VALUES (?,?,?,'active',?)")->execute([$session_id,$room_id,$me,$member_team_id]);
            $lid = (int)$pdo->lastInsertId();
        }
        rl_log($pdo,$lid,$session_id,$room_id,$me,'resumed','تسلّم');

        // إشعار للمستخدم الذي سُحبت منه الغرفة
        if ($taken_from_user && $taken_from_user !== $me) {
            $actor_name = current_user()['full_name'] ?? '';
            $room_info = $pdo->prepare("SELECT name, name_en FROM item_locations WHERE id=?");
            $room_info->execute([$room_id]);
            $room_row = $room_info->fetch(PDO::FETCH_ASSOC);
            $room_label = $room_row ? ($room_row['name_en'] ?: $room_row['name']) : '#' . $room_id;
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                VALUES (?, 'warning', ?, ?, ?, 'inventory_session', ?)")
                ->execute([
                    $taken_from_user,
                    '⚠️ سُحبت منك غرفة الجرد',
                    'عزيزي ' . $taken_from_name . '، قام ' . $actor_name . ' بتسلّم غرفة "' . $room_label . '" التي كنت تجرون فيها الجرد.',
                    BASE_URL . '/inventory/session.php?id=' . $session_id,
                    $session_id
                ]);
        }

        $pdo->commit();
        json_response(['ok'=>true,'lock_id'=>$lid]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['ok'=>false,'error'=>'db']); }
}

case 'suspend': {
    $st=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status='active'");
    $st->execute([$session_id,$room_id,$me]); $lid=$st->fetchColumn();
    if (!$lid) json_response(['ok'=>false,'error'=>'no_lock']);
    if ($max_suspend_count > 0) {
        /* العدّاد على مستوى الجلسة كلها (وليس لكل قفل) — قرار التدقيق 2026-08-23 فجوة #4 */
        $sc=$pdo->prepare("SELECT COUNT(*) FROM room_lock_events e JOIN room_inventory_locks l ON l.id=e.lock_id
            WHERE l.session_id=? AND l.locked_by=? AND e.event_type='suspended'");
        $sc->execute([$session_id,$me]);
        if ((int)$sc->fetchColumn() >= $max_suspend_count)
            json_response(['ok'=>false,'error'=>'suspend_limit_reached','msg'=>"تجاوزت الحد الأقصى لعمليات التعليق ($max_suspend_count) في هذه الجلسة — تواصل مع مدير الأصول لإعادة تعيين العداد"], 403);
    }
    rl_log($pdo,$lid,$session_id,$room_id,$me,'suspended');
    json_response(['ok'=>true]);
}

case 'complete': {
    $oath = !empty($input['oath']);
    if ($require_oath && !$oath) json_response(['ok'=>false,'error'=>'oath_required','msg'=>'يجب تأكيد الإقرار قبل الإقفال النهائي']);
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status='active'");
        $st->execute([$session_id,$room_id,$me]); $lid=$st->fetchColumn();
        if (!$lid) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'no_lock']); }
        $pdo->prepare("UPDATE room_inventory_locks SET status='completed', completion_oath=?, completed_at=NOW(), note='إقرار بإتمام الجرد' WHERE id=?")
            ->execute([$oath?1:0,$lid]);
        rl_log($pdo,$lid,$session_id,$room_id,$me,'completed');
        $others=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND status='active' AND locked_by<>?");
        $others->execute([$session_id,$room_id,$me]);
        foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $oid)
            $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note='أُقفلت الغرفة بواسطة موظف آخر' WHERE id=?")->execute([$oid]);

        // إشعار أعضاء الفرق المنتمين لنطاق هذه الغرفة
        $room_info = $pdo->prepare("SELECT name, name_en, dept_id FROM item_locations WHERE id=? AND location_type='room'");
        $room_info->execute([$room_id]);
        $room_row = $room_info->fetch(PDO::FETCH_ASSOC);
        $room_label = $room_row ? ($room_row['name_en'] ?: $room_row['name']) : '#' . $room_id;
        $completer_name = current_user()['full_name'] ?? '';

        if ($room_row) {
            // جلب أعضاء الفرق الذين نطاقهم يشمل هذه الغرفة (بالقسم أو بالغرفة مباشرة)
            $notify_st = $pdo->prepare("
                SELECT DISTINCT tm.user_id, u.full_name
                FROM inventory_session_team_members tm
                JOIN inventory_session_teams t ON t.id = tm.team_id AND t.session_id = ?
                LEFT JOIN inventory_session_team_scopes ts ON ts.team_id = t.id
                LEFT JOIN users u ON u.id = tm.user_id
                WHERE ts.scope_type = 'room' AND ts.scope_id = ?
                   OR ts.scope_type = 'dept' AND ts.scope_id = ?
            ");
            $notify_st->execute([$session_id, $room_id, $room_row['dept_id']]);
            $ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                VALUES (?, 'success', ?, ?, ?, 'inventory_session', ?)");
            foreach ($notify_st->fetchAll(PDO::FETCH_ASSOC) as $nr) {
                $nuid = (int)$nr['user_id'];
                if ($nuid === $me) continue;
                $ins_n->execute([
                    $nuid,
                    '✅ تم إكمال غرفة الجرد',
                    'تم إكمال جرد الغرفة "' . $room_label . '" بواسطة ' . $completer_name . '.',
                    BASE_URL . '/inventory/session.php?id=' . $session_id,
                    $session_id
                ]);
            }
        }

        $pdo->commit();
        json_response(['ok'=>true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['ok'=>false,'error'=>'db']); }
}

default: json_response(['ok'=>false,'error'=>'bad_action']);
}