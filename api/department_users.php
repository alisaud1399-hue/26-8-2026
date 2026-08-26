<?php
/**
 * api/department_users.php
 * جلب رئيس القسم + كل موظفي القسم لاختيار المستلم
 *
 * الاستخدام:
 *   GET /api/department_users.php?dept_id=43
 *
 * السلوك تلقائي حسب نوع القسم:
 *   • Main dept (parent_id IS NULL): يجلب كل الموظفين في القسم + الأقسام الفرعية
 *     ويرجع رئيس القسم مع الوراثة للأب (إن لزم)
 *   • Sub dept (parent_id IS NOT NULL): يرجع فقط رئيس القسم المباشر + موظفي هذا الفرع
 *     (بدون walk-up — رئيس الفرع نفسه أو لا أحد)
 *
 * الاستجابة:
 *   {
 *     "ok": true,
 *     "department": {id, name, level, sub_count},
 *     "manager": {id, full_name, job_title} | null,
 *     "inherited_from": "..." | null,
 *     "users": [{id, full_name, job_title, dept_id, dept_name, is_head, is_active}],
 *     "total_users": N,
 *     "is_sub_dept": true|false
 *   }
 */
require_once __DIR__ . '/../config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) {
    echo json_encode(['ok' => false, 'error' => 'dept_id_required']);
    exit;
}

// ═══ 1) جلب بيانات القسم ═══
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
    echo json_encode(['ok' => false, 'error' => 'dept_not_found']);
    exit;
}

// هل هذا قسم فرعي؟ (sub-dept mode = لا walk-up، موظفون مباشرون فقط)
$is_sub_dept = !empty($row['parent_id']);

$inherited_from = null;
$lookup = $row;
if (!$is_sub_dept) {
    // Main dept فقط: صعد للأب حتى تجد رئيساً فعلياً
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
        $row = $lookup;
    }
}

// ═══ 2) جلب الموظفين ═══
$all_dept_ids = [$dept_id];
$sub_count = 0;

if (!$is_sub_dept) {
    // Main dept: اجمع كل الأقسام الفرعية recursive
    $queue = [$dept_id];
    $depth = 0;
    while (!empty($queue) && $depth < 5) {
        $placeholders = implode(',', array_fill(0, count($queue), '?'));
        $cq = $pdo->prepare("SELECT id FROM departments WHERE parent_id IN ($placeholders) AND is_active = 1");
        $cq->execute($queue);
        $children = $cq->fetchAll(PDO::FETCH_COLUMN);
        if (empty($children)) break;
        $all_dept_ids = array_merge($all_dept_ids, $children);
        $queue = $children;
        $depth++;
    }
    $all_dept_ids = array_unique(array_map('intval', $all_dept_ids));
    $sub_count = count($all_dept_ids) - 1;
}
// Sub dept: [$dept_id] فقط (موظفون مباشرون)

// جلب كل المستخدمين في هذه الأقسام
$in_list = implode(',', array_fill(0, count($all_dept_ids), '?'));
$uq = $pdo->prepare("
    SELECT u.id, u.full_name, u.job_title, u.is_active,
           d.id AS dept_id, d.name AS dept_name
    FROM users u
    INNER JOIN departments d ON d.id = u.department_id
    WHERE u.department_id IN ($in_list)
    ORDER BY (u.id = ?) DESC,    -- المدير أولاً
             u.full_name ASC
");
$params = array_merge($all_dept_ids, [$row['manager_id'] ?? 0]);
$uq->execute($params);
$users = $uq->fetchAll(PDO::FETCH_ASSOC);

// ضع علامة is_head على المدير
foreach ($users as &$u) {
    $u['is_head'] = ($row['manager_id'] && (int)$u['id'] === (int)$row['manager_id']);
}
unset($u);

// ═══ 3) الإجابة ═══
echo json_encode([
    'ok' => true,
    'department' => [
        'id' => $dept_id,
        'name' => $row['name'],
        'level' => $row['level'],
        'sub_count' => $sub_count,
    ],
    'is_sub_dept' => $is_sub_dept,
    'manager' => $row['manager_id'] ? [
        'id' => (int)$row['user_id'],
        'full_name' => $row['full_name'],
        'job_title' => $row['job_title'],
    ] : null,
    'inherited_from' => $inherited_from,
    'users' => array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'full_name' => $u['full_name'],
            'job_title' => $u['job_title'],
            'dept_id' => (int)$u['dept_id'],
            'dept_name' => $u['dept_name'],
            'is_head' => (bool)$u['is_head'],
            'is_active' => (bool)$u['is_active'],
        ];
    }, $users),
    'total_users' => count($users),
], JSON_UNESCAPED_UNICODE);
