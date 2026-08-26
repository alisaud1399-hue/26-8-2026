<?php
/**
 * inventory/api/requests_list.php — قائمة الطلبات الموحدة للجلسة
 * GET?session_id=123
 * يُرجع: re_audit_device, re_audit_room, data_conflict
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$sid = (int)($_GET['session_id'] ?? 0);
if ($sid <= 0) json_response(['ok' => false, 'error' => 'session_id required']);

$st = $pdo->prepare("
    SELECT r.*,
        u.full_name AS requested_by_name,
        a.tag_number AS asset_tag, a.description AS asset_desc, a.description_ar AS asset_desc_ar,
        CONCAT_WS(' / ', b.name, f.name, rm.name) AS asset_location,
        ca.tag_number AS conflict_asset_tag, ca.description AS conflict_asset_desc, ca.description_ar AS conflict_asset_desc_ar
    FROM inventory_reaudit_requests r
    LEFT JOIN users u ON u.id = r.requested_by
    LEFT JOIN assets a ON a.id = r.asset_id
    LEFT JOIN assets ca ON ca.id = r.conflict_asset_id
    LEFT JOIN item_locations rm ON rm.id = r.room_id
    LEFT JOIN item_locations f ON f.id = rm.parent_id
    LEFT JOIN item_locations b ON b.id = f.parent_id
    WHERE r.session_id = ?
    ORDER BY r.created_at DESC
");
$st->execute([$sid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

json_response(['ok' => true, 'requests' => $rows]);
