<?php
/**
 * api/department_manager.php
 * جلب رئيس القسم الحالي (manager_id) لقسم معيّن — لتعبئة "المستلم" تلقائياً
 */
require_once __DIR__ . '/../config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) {
    echo json_encode(['manager' => null, 'department' => null]);
    exit;
}

$q = $pdo->prepare("
    SELECT d.id, d.name, d.level, d.parent_id, d.manager_id,
           u.id AS user_id, u.full_name, u.job_title
    FROM departments d
    LEFT JOIN users u ON u.id = d.manager_id
    WHERE d.id = ?
    LIMIT 1
");
$q->execute([$dept_id]);
$row = $q->fetch();

if (!$row) {
    echo json_encode(['manager' => null, 'department' => null]);
    exit;
}

// إن لم يكن لهذا القسم رئيس مستقل، نصعد للأب فالجد حتى نجد أقرب رئيس فعلي
// (الأقسام الفرعية المُستخرجة من صور التحويلات غالباً مواقع/وظائف لا "إدارات" مستقلة بذاتها)
$inherited_from = null;
$lookup = $row;
$hops = 0;
while (!$lookup['manager_id'] && $lookup['parent_id'] && $hops < 5) {
    $pq = $pdo->prepare("
        SELECT d.id, d.name, d.level, d.parent_id, d.manager_id,
               u.id AS user_id, u.full_name, u.job_title
        FROM departments d
        LEFT JOIN users u ON u.id = d.manager_id
        WHERE d.id = ? LIMIT 1
    ");
    $pq->execute([$lookup['parent_id']]);
    $parent = $pq->fetch();
    if (!$parent) break;
    $lookup = $parent;
    $hops++;
}
if ($lookup['manager_id'] && $lookup['id'] != $row['id']) {
    $inherited_from = $lookup['name'];
    $row = $lookup; // نستخدم بيانات أقرب أب له رئيس فعلي
}

// تفاصيل التكليف النشط من شاشة التكليفات — يُحدِّد دائم/مؤقت + منذ متى
// (نستخدم id القسم الفعلي الذي وجدنا له رئيساً — قد يكون الأب الموروث)
$asn = null;
if ($row['manager_id']) {
    $aq = $pdo->prepare("
        SELECT assignment_type, start_date, end_date
        FROM department_manager_assignments
        WHERE department_id = ? AND status='active'
        ORDER BY id DESC LIMIT 1
    ");
    $aq->execute([$row['id']]);
    $asn = $aq->fetch() ?: null;
}

echo json_encode([
    'department' => ['id' => $row['id'], 'name' => $row['name'], 'level' => $row['level']],
    'manager'    => $row['manager_id'] ? [
        'id'        => $row['user_id'],
        'full_name' => $row['full_name'],
        'job_title' => $row['job_title'],
    ] : null,
    'assignment' => $asn ? [
        'type'       => $asn['assignment_type'],
        'start_date' => $asn['start_date'],
        'end_date'   => $asn['end_date'],
    ] : null,
    'inherited_from' => $inherited_from,
]);