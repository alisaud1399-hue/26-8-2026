<?php
/**
 * inventory/api/room_assets.php — أجهزة غرفة مع حالة الجرد (نسخة مصحّحة)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$session_id = (int)($_REQUEST['session_id'] ?? 0);
$room_id    = (int)($_REQUEST['room_id'] ?? 0);
if ($session_id <= 0 || $room_id <= 0) json_response(['ok'=>false,'error'=>'بيانات الجلسة أو الغرفة مفقودة']);

$st = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session || !in_array($session['status'], ['active','review'], true)) json_response(['ok'=>false,'error'=>'الجلسة غير نشطة']);

/* فحص نطاق الفريق */
$me = (int)(current_user()['id'] ?? 0);
if ($me && $session_id && !(is_admin() || can_see_all())) {
    $tt = $pdo->prepare("SELECT t.id FROM inventory_session_team_members tm JOIN inventory_session_teams t ON t.id=tm.team_id WHERE t.session_id=? AND tm.user_id=?");
    $tt->execute([$session_id, $me]);
    $my_tids = $tt->fetchAll(PDO::FETCH_COLUMN);
    if ($my_tids) {
        $sc = $pdo->prepare("SELECT ts.scope_type, ts.scope_id FROM inventory_session_team_scopes ts WHERE ts.team_id IN (" . implode(',', array_fill(0, count($my_tids), '?')) . ")");
        $sc->execute($my_tids);
        $scopes = $sc->fetchAll(PDO::FETCH_ASSOC);
        if ($scopes) {
            $ok = false;
            foreach ($scopes as $s) {
                if ($s['scope_type'] === 'room' && (int)$s['scope_id'] === $room_id) { $ok = true; break; }
                if ($s['scope_type'] === 'dept') {
                    $dr = $pdo->prepare("SELECT dept_id FROM item_locations WHERE id=?");
                    $dr->execute([$room_id]);
                    $did = (int)$dr->fetchColumn();
                    if ((int)$s['scope_id'] === $did) { $ok = true; break; }
                }
            }
            if (!$ok) json_response(['ok'=>false,'error'=>'room_out_of_scope','msg'=>'هذه الغرفة خارج نطاق فريقك المحدد']);
        }
    }
}

$rq = $pdo->prepare("
    SELECT r.id, r.name AS room_en, r.name_en AS room_ar,
           f.id AS floor_id, f.name AS floor_en, f.name_en AS floor_ar,
           b.id AS building_id, b.name AS building_en, b.name_en AS building_ar
    FROM item_locations r
    LEFT JOIN item_locations f ON f.id = r.parent_id
    LEFT JOIN item_locations b ON b.id = f.parent_id
    WHERE r.id = ? AND r.location_type = 'room'
");
$rq->execute([$room_id]);
$room = $rq->fetch(PDO::FETCH_ASSOC);
if (!$room) json_response(['ok'=>false,'error'=>'لم يتم العثور على الغرفة']);

/* جلب اسم القسم بالإنجليزي */
$deptNameEn = null;
if (!empty($room['dept_id'])) {
    $deptQ = $pdo->prepare("SELECT name_en FROM departments WHERE id=?");
    $deptQ->execute([$room['dept_id']]);
    $deptNameEn = $deptQ->fetchColumn() ?: null;
}

$req_dept_id = (int)($_REQUEST['dept_id'] ?? 0);
try {
    $aq = $pdo->prepare("
        SELECT a.id, a.description, a.description_ar, a.en_name, a.tag_number, a.serial_number,
               a.asset_type, a.criticality_class, a.status, a.health_score,
               a.manufacturer_name, a.model_number,
               a.loc_building, a.loc_floor, a.loc_room,
               (a.custodian_dept_id = ?) AS custody_match,
               EXISTS(SELECT 1 FROM custody_ai_suggestions s WHERE s.asset_id = a.id AND s.status IN ('pending','accepted') AND s.suggested_dept_id = ?) AS ai_match,
               ia.action AS last_action, ia.audited_at,
               u.full_name AS auditor_name
        FROM assets a
        LEFT JOIN (
            SELECT i1.asset_id, i1.action, i1.audited_at, i1.audited_by
            FROM inventory_audits i1
            INNER JOIN (SELECT asset_id, MAX(id) max_id FROM inventory_audits WHERE session_id=? GROUP BY asset_id) i2 ON i1.id = i2.max_id
        ) ia ON ia.asset_id = a.id
        LEFT JOIN users u ON u.id = ia.audited_by
        WHERE a.location_id = ? AND a.status NOT IN ('disposed','returned_to_supplier')
        ORDER BY a.criticality_class, a.description
    ");
    $aq->execute([$req_dept_id, $req_dept_id, $session_id, $room_id]);
    $assets = $aq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    json_response(['ok'=>false,'error'=>'خطأ في قاعدة البيانات: '.$e->getMessage()]);
    exit;
}

$done_actions = ['confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously'];
$out = []; $done_count = 0;
foreach ($assets as $a) {
    $is_done = $a['last_action'] !== null && in_array($a['last_action'], $done_actions, true);
    if ($is_done) $done_count++;
    $loc_text = trim(($a['loc_building']??'').' / '.($a['loc_floor']??'').' / '.($a['loc_room']??''), ' /');
    $out[] = [
        'id'=>(int)$a['id'],
        'name'=>$a['en_name'] ?: $a['description'],
        'name_ar'=>$a['description_ar'],
        'tag'=>$a['tag_number'],
        'serial'=>$a['serial_number'],
        'crit'=>$a['criticality_class'] ?: 'C',
        'status'=>$a['status'],
        'health'=>$a['health_score']!==null?(int)$a['health_score']:null,
        'chips'=>array_values(array_filter([$a['manufacturer_name']?'الشركة: '.$a['manufacturer_name']:null, $a['model_number']?'الموديل: '.$a['model_number']:null])),
        'loc_text'=>$loc_text,
        'done'=>$is_done,
        'last_action'=>$a['last_action'],
        'auditor'=>$a['auditor_name'] ?? 'مستخدم',
        'audited_at'=>$a['audited_at'] ? date('Y-m-d h:i A', strtotime($a['audited_at'])) : null,
        'is_target'=>$req_dept_id>0 ? ($a['custody_match']||$a['ai_match']) : true,
    ];
}

json_response([
    'ok'=>true,
    'room'=>[
        'id'=>(int)$room['id'],
        'name'=>$room['room_ar']?:$room['room_en'],'name_ar'=>$room['room_ar'],'name_en'=>$room['room_en'],
        'floor'=>$room['floor_ar']?:$room['floor_en'],'floor_ar'=>$room['floor_ar'],'floor_en'=>$room['floor_en'],
        'building'=>$room['building_ar']?:$room['building_en'],'building_ar'=>$room['building_ar'],'building_en'=>$room['building_en'],
        'dept_name_en'=>$deptNameEn,
        'total'=>count($out),'done'=>$done_count
    ],
    'assets'=>$out
]);