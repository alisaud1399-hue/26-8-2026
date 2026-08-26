<?php
/**
 * includes/notify.php — دوال الإشعارات
 */

if (!function_exists('notify')) {

/**
 * إرسال إشعار لمستخدم
 */
function notify(int $user_id, string $type, string $title, string $message='', string $url=''): void {
    global $pdo;
    if (!$user_id) return;
    try {
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $type, $title, $message?:null, $url?:null]);
    } catch (Exception $e) { /* silent */ }
}

/**
 * إرسال إشعار لمجموعة مستخدمين
 */
function notify_many(array $user_ids, string $type, string $title, string $message='', string $url=''): void {
    foreach (array_unique(array_filter($user_ids)) as $uid)
        notify((int)$uid, $type, $title, $message, $url);
}

/**
 * إرسال إشعار لجميع المستخدمين بدور معين
 */
function notify_role(string $role_name, string $type, string $title, string $message='', string $url=''): void {
    global $pdo;
    $st = $pdo->prepare("SELECT DISTINCT u.id FROM users u
        INNER JOIN user_roles ur ON ur.user_id=u.id
        INNER JOIN roles r ON r.id=ur.role_id
        WHERE r.name=? AND u.is_active=1");
    $st->execute([$role_name]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    notify_many($ids, $type, $title, $message, $url);
}

/**
 * إشعارات دورة اللجنة
 */
function notify_committee_submitted(int $committee_id, string $name, int $requester_id): void {
    $url = BASE_URL . '/committees/approve.php?id=' . $committee_id;
    $msg = "طلب تشكيل لجنة: «{$name}»";
    // المدير التنفيذي
    notify_role('executive', 'committee_request', 'طلب اعتماد لجنة جديدة', $msg, $url);
    // Admin
    notify_role('admin',     'committee_request', 'طلب اعتماد لجنة جديدة', $msg, $url);
}

function notify_committee_approved(int $committee_id, string $name, int $requester_id): void {
    $url = BASE_URL . '/committees/view.php?id=' . $committee_id;
    notify($requester_id, 'committee_approved',
        'تمت الموافقة على اللجنة',
        "تمت الموافقة على لجنة «{$name}» وبدأت إجراءات التوقيع", $url);
}

function notify_committee_returned(int $committee_id, string $name, int $requester_id, string $reason=''): void {
    $url = BASE_URL . '/committees/form.php?id=' . $committee_id;
    notify($requester_id, 'committee_returned',
        'طلب اللجنة يحتاج تصحيح',
        "أُعيد طلب لجنة «{$name}» للتصحيح" . ($reason ? ": {$reason}" : ''), $url);
}

function notify_committee_rejected(int $committee_id, string $name, int $requester_id, string $reason=''): void {
    $url = BASE_URL . '/committees/view.php?id=' . $committee_id;
    notify($requester_id, 'committee_rejected',
        'تم رفض طلب اللجنة',
        "رُفض طلب لجنة «{$name}»" . ($reason ? ": {$reason}" : ''), $url);
}

function notify_member_sign_request(int $committee_id, string $name, int $member_user_id, int $seq): void {
    $url = BASE_URL . '/committees/sign.php?id=' . $committee_id;
    notify($member_user_id, 'member_sign_request',
        'مطلوب توقيعك على لجنة',
        "دورك للموافقة على لجنة «{$name}» (الخطوة {$seq})", $url);
}

function notify_member_completed(int $committee_id, string $name, int $requester_id): void {
    $url = BASE_URL . '/committees/view.php?id=' . $committee_id;
    notify($requester_id, 'committee_completed',
        'اكتملت موافقات اللجنة ✅',
        "اكتملت جميع الموافقات على لجنة «{$name}» — المحضر جاهز للطباعة", $url);
}

} // end if !function_exists
