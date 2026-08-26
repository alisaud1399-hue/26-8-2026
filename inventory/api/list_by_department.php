<?php
/**
 * inventory/api/list_by_department.php
 * ------------------------------------
 * يجلب قائمة الأصول المتوقعة لقسم معيّن (مع توقعات AI من التوزيع الأولي)
 * يستخدم لـ Department-First mode في scan.php
 *
 * Parameters:
 *   session_id     (int,  required)
 *   department_id  (int,  required) — id من جدول departments
 *   filter         (string, optional) all | high | incomplete | pending
 *   search         (string, optional) — يبحث في tag/serial/description
 *
 * Returns:
 *   {
 *     ok: true,
 *     department: {id, name, name_en, total_predicted},
 *     counts: {total, high, medium, low, complete, partial, untouched, in_scope},
 *     assets: [...]   // see below
 *   }
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$rtl = is_rtl();
$session_id    = (int)($_REQUEST['session_id'] ?? 0);
$department_id = (int)($_REQUEST['department_id'] ?? 0);
$filter        = $_REQUEST['filter'] ?? 'all';
$search        = trim($_REQUEST['search'] ?? '');

if ($session_id <= 0)    json_response(['ok' => false, 'error' => 'session_id_required'], 400);
if ($department_id <= 0) json_response(['ok' => false, 'error' => 'department_id_required'], 400);

// 1) تأكيد الجلسة
$st = $pdo->prepare("SELECT id, title, scope_type, scope_value, operating_mode, status FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) json_response(['ok' => false, 'error' => 'session_not_found'], 404);
if (!in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'session_not_active', 'status' => $session['status']], 403);
}

// 2) تأكيد القسم موجود
$st = $pdo->prepare("SELECT id, name, name_en, is_active FROM departments WHERE id=?");
$st->execute([$department_id]);
$dept = $st->fetch(PDO::FETCH_ASSOC);
if (!$dept) json_response(['ok' => false, 'error' => 'department_not_found'], 404);

// 3) تحقق من أن القسم داخل نطاق الجلسة
$scope_vals = json_decode($session['scope_value'] ?? '[]', true) ?: [];
$in_session_scope = ($session['scope_type'] === 'all')
    || (($session['scope_type'] === 'department') && in_array($department_id, array_map('intval', $scope_vals), true));
if (!$in_session_scope) {
    json_response([
        'ok' => false,
        'error' => 'dept_not_in_session_scope',
        'hint' => $rtl
            ? 'هذا القسم ليس ضمن نطاق الجلسة. أضفه للنطاق من إعدادات الجلسة.'
            : 'Department is not in session scope. Add it from session settings.',
    ], 403);
}

// 4) الجلب الرئيسي: JOIN assets + custody_ai_suggestions
$sql = "
    SELECT
        a.id, a.tag_number, a.alternative_code, a.description, a.en_name,
        a.asset_type, a.criticality_class, a.criticality_level, a.new_used,
        a.manufacturer_name, a.model_number, a.serial_number,
        a.loc_building, a.loc_floor, a.loc_room, a.location_id,
        a.category_id, a.cat_level1, a.cat_level2, a.cat_level3,
        a.custodian_name, a.custodian_dept_name,
        a.prediction_department_id, a.prediction_confidence,
        a.data_completeness, a.incomplete_data,
        cas.confidence AS ai_confidence,
        cas.match_score AS ai_score,
        cas.method AS ai_method,
        cas.status AS ai_status,
        cas.reasoning AS ai_reasoning,
        -- آخر تسجيل في هذه الجلسة (إن وُجد)
        (SELECT action FROM inventory_audits
         WHERE session_id = :sid1 AND asset_id = a.id
         ORDER BY audited_at DESC LIMIT 1) AS last_audit_action,
        (SELECT audited_at FROM inventory_audits
         WHERE session_id = :sid2 AND asset_id = a.id
         ORDER BY audited_at DESC LIMIT 1) AS last_audit_at,
        -- عدد الحقول المتحقق منها
        (SELECT COUNT(*) FROM asset_field_verifications
         WHERE session_id = :sid3 AND asset_id = a.id AND is_verified = 1) AS fields_verified_count,
        (SELECT COUNT(*) FROM asset_field_verifications
         WHERE session_id = :sid4 AND asset_id = a.id) AS fields_tracked_count,
        -- أسماء الحقول المتحقق منها (لكي يقدر الـ JS يعرض الـ checkboxes مفعّلة)
        (SELECT GROUP_CONCAT(field_name) FROM asset_field_verifications
         WHERE session_id = :sid5 AND asset_id = a.id AND is_verified = 1) AS verified_fields
    FROM assets a
    INNER JOIN custody_ai_suggestions cas
        ON cas.asset_id = a.id AND cas.suggested_dept_id = :dept_id
    WHERE a.status NOT IN ('disposed', 'returned_to_supplier')
      AND cas.status IN ('pending', 'accepted')
";

// filter
$params = [':sid1' => $session_id, ':sid2' => $session_id, ':sid3' => $session_id, ':sid4' => $session_id, ':sid5' => $session_id, ':dept_id' => $department_id];
$extra_where = '';
if ($filter === 'high')        $extra_where .= ' AND cas.confidence = "high"';
elseif ($filter === 'medium')  $extra_where .= ' AND cas.confidence = "medium"';
elseif ($filter === 'low')     $extra_where .= ' AND cas.confidence = "low"';
elseif ($filter === 'incomplete') $extra_where .= ' AND a.incomplete_data = 1';
elseif ($filter === 'pending') {
    $extra_where .= ' AND a.id NOT IN (SELECT asset_id FROM inventory_audits WHERE session_id = :sid5)';
    $params[':sid5'] = $session_id;
}

if ($search !== '') {
    $extra_where .= ' AND (a.tag_number LIKE :q1 OR a.serial_number LIKE :q2 OR a.description LIKE :q3 OR a.en_name LIKE :q4)';
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
    $params[':q4'] = '%' . $search . '%';
}

$sql .= $extra_where . ' ORDER BY cas.match_score DESC, a.tag_number ASC LIMIT 500';

$st = $pdo->prepare($sql);
$st->execute($params);
$assets = $st->fetchAll(PDO::FETCH_ASSOC);

// 5) إثراء البيانات بحالة اللون + إكمال
$counts = [
    'total'         => count($assets),
    'high'          => 0,
    'medium'        => 0,
    'low'           => 0,
    'complete'      => 0,
    'partial'       => 0,
    'untouched'     => 0,
    'in_scope'      => count($assets),
];

$CRITICAL_FIELDS = ['tag_number', 'description', 'manufacturer_name', 'model_number', 'location_id'];

foreach ($assets as &$a) {
    // لون حسب ثقة AI
    $conf = $a['ai_confidence'];
    if ($conf === 'high')   $counts['high']++;
    elseif ($conf === 'medium') $counts['medium']++;
    elseif ($conf === 'low') $counts['low']++;

    // لون حسب اكتمال التحقق
    $verified_count = (int)$a['fields_verified_count'];
    $last_action    = $a['last_audit_action'];

    if ($last_action === 'confirmed') {
        $a['color_state'] = 'complete';
        $counts['complete']++;
    } elseif ($verified_count > 0 || $last_action) {
        $a['color_state'] = 'partial';
        $counts['partial']++;
    } else {
        $a['color_state'] = 'untouched';
        $counts['untouched']++;
    }

    // حساب progress %
    $tracked = max((int)$a['fields_tracked_count'], count($CRITICAL_FIELDS));
    $a['verification_progress'] = $tracked > 0 ? round($verified_count / $tracked * 100) : 0;

    // تحويل verified_fields من CSV string إلى مصفوفة (سهلة الاستهلاك في JS)
    $a['verified_fields'] = $a['verified_fields'] ? array_filter(explode(',', $a['verified_fields'])) : [];

    // حذف الحقول الحساسة من الإخراج (لا حاجة للـ frontend)
    unset($a['ai_reasoning']);
}

unset($a);

// 6) KPIs للقسم
$st = $pdo->prepare("
    SELECT
        COUNT(DISTINCT cas.asset_id) AS total_predicted,
        SUM(CASE WHEN cas.confidence='high' THEN 1 ELSE 0 END) AS total_high
    FROM custody_ai_suggestions cas
    WHERE cas.suggested_dept_id = :dept_id AND cas.status IN ('pending','accepted')
");
$st->execute([':dept_id' => $department_id]);
$kpis = $st->fetch(PDO::FETCH_ASSOC);

json_response([
    'ok'             => true,
    'department'     => [
        'id'              => (int)$dept['id'],
        'name'            => $dept['name'],
        'name_en'         => $dept['name_en'],
        'total_predicted' => (int)$kpis['total_predicted'],
        'total_high'      => (int)$kpis['total_high'],
    ],
    'session'        => [
        'id'             => (int)$session['id'],
        'operating_mode' => $session['operating_mode'],
        'title'          => $session['title'],
    ],
    'filter'         => $filter,
    'search'         => $search,
    'counts'         => $counts,
    'assets'         => $assets,
]);