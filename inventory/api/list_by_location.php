<?php
/**
 * inventory/api/list_by_location.php
 * ------------------------------------
 * يجلب الأصول المتوقعة في موقع محدد (مبنى/طابق/غرفة) لجلسة جرد
 * يُستخدم لـ Location mode في scan.php (spot-check)
 *
 * Parameters:
 *   session_id  (int, required)
 *   location_id (int, required) - id من item_locations (room أو floor أو building)
 *   filter      (string, optional) all | complete | partial | untouched
 *
 * Returns:
 *   {
 *     ok: true,
 *     location: {id, name, name_en, type, path: 'building > floor > room'},
 *     counts: {total, complete, partial, untouched},
 *     assets: [...]
 *   }
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$session_id  = (int)($_REQUEST['session_id'] ?? 0);
$location_id = (int)($_REQUEST['location_id'] ?? 0);
$filter      = $_REQUEST['filter'] ?? 'all';
$rtl         = is_rtl();

if ($session_id <= 0)  json_response(['ok' => false, 'error' => 'session_id_required'], 400);
if ($location_id <= 0) json_response(['ok' => false, 'error' => 'location_id_required'], 400);

// 1) تأكيد الجلسة نشطة
$st = $pdo->prepare("SELECT id, status, operating_mode FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) json_response(['ok' => false, 'error' => 'session_not_found'], 404);
if (!in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'session_not_active', 'status' => $session['status']], 403);
}

// 2) تأكيد الموقع موجود + بناء breadcrumb
$st = $pdo->prepare("SELECT id, name, name_en, location_type, parent_id FROM item_locations WHERE id=?");
$st->execute([$location_id]);
$loc = $st->fetch(PDO::FETCH_ASSOC);
if (!$loc) json_response(['ok' => false, 'error' => 'location_not_found'], 404);

// Build path: building > floor > room
$path = [];
$cur_id = $location_id;
for ($i = 0; $i < 5; $i++) {
    $cs = $pdo->prepare("SELECT id, name, name_en, location_type, parent_id FROM item_locations WHERE id=?");
    $cs->execute([$cur_id]);
    $row = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$row) break;
    array_unshift($path, [
        'id'   => (int)$row['id'],
        'type' => $row['location_type'],
        'name' => $row['name_en'],  // Arabic
        'name_en' => $row['name'], // English
    ]);
    $cur_id = $row['parent_id'];
    if (!$cur_id) break;
}

// 3) جمع IDs للمواقع الفرعية (لو اخترنا building نعرض كل floors+rooms تحته)
$descendant_ids = [$location_id];
$queue = [$location_id];
$type_filter = $loc['location_type']; // building | floor | room
while ($queue) {
    $cur = array_shift($queue);
    $ds = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id = ?");
    $ds->execute([$cur]);
    while ($r = $ds->fetch(PDO::FETCH_ASSOC)) {
        $descendant_ids[] = (int)$r['id'];
        $queue[] = (int)$r['id'];
    }
}
$placeholders = implode(',', array_fill(0, count($descendant_ids), '?'));

// 4) جلب الأصول المتوقعة في هذا النطاق
// نستخدم location_id (FK) أولاً، ولو ما وُجد نطابق النص
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
        -- آخر تسجيل في هذه الجلسة
        (SELECT action FROM inventory_audits
         WHERE session_id = ? AND asset_id = a.id
         ORDER BY audited_at DESC LIMIT 1) AS last_audit_action,
        (SELECT audited_at FROM inventory_audits
         WHERE session_id = ? AND asset_id = a.id
         ORDER BY audited_at DESC LIMIT 1) AS last_audit_at,
        -- عدد الحقول المتحقق منها
        (SELECT COUNT(*) FROM asset_field_verifications
         WHERE session_id = ? AND asset_id = a.id AND is_verified = 1) AS fields_verified_count,
        (SELECT COUNT(*) FROM asset_field_verifications
         WHERE session_id = ? AND asset_id = a.id) AS fields_tracked_count,
        -- أسماء الحقول المتحقق منها
        (SELECT GROUP_CONCAT(field_name) FROM asset_field_verifications
         WHERE session_id = ? AND asset_id = a.id AND is_verified = 1) AS verified_fields
    FROM assets a
    WHERE a.location_id IN ($placeholders)
      AND a.status NOT IN ('disposed', 'returned_to_supplier')
    ORDER BY a.loc_building, a.loc_floor, a.loc_room, a.tag_number
    LIMIT 500
";

$params = [$session_id, $session_id, $session_id, $session_id, $session_id];
$params = array_merge($params, $descendant_ids);

$st = $pdo->prepare($sql);
$st->execute($params);
$assets = $st->fetchAll(PDO::FETCH_ASSOC);

// 5) حساب color state
$counts = [
    'total'     => count($assets),
    'complete'  => 0,
    'partial'   => 0,
    'untouched' => 0,
];

foreach ($assets as &$a) {
    $last = $a['last_audit_action'];
    $verified = (int)$a['fields_verified_count'];
    if ($last === 'confirmed') {
        $a['color_state'] = 'complete';
        $counts['complete']++;
    } elseif ($verified > 0 || $last) {
        $a['color_state'] = 'partial';
        $counts['partial']++;
    } else {
        $a['color_state'] = 'untouched';
        $counts['untouched']++;
    }

    // تحويل verified_fields من CSV string إلى مصفوفة
    $a['verified_fields'] = $a['verified_fields'] ? array_filter(explode(',', $a['verified_fields'])) : [];
    $tracked = max((int)$a['fields_tracked_count'], 5);
    $a['verification_progress'] = $tracked > 0 ? round($verified / $tracked * 100) : 0;
}
unset($a);

// 6) فلتر (إذا طُلب)
if ($filter === 'complete' || $filter === 'partial' || $filter === 'untouched') {
    $assets = array_values(array_filter($assets, fn($a) => $a['color_state'] === $filter));
    $counts['showing'] = count($assets);
}

// 7) معلومات الموقع
$location_info = [
    'id'   => (int)$loc['id'],
    'type' => $loc['location_type'],
    'name' => $loc['name_en'],  // Arabic
    'name_en' => $loc['name'],
    'path' => $path,
    'descendant_count' => count($descendant_ids),
];

json_response([
    'ok'       => true,
    'location' => $location_info,
    'counts'   => $counts,
    'filter'   => $filter,
    'assets'   => $assets,
]);