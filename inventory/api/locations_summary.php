<?php
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$session_id = (int)($_REQUEST['session_id'] ?? 0);
if ($session_id <= 0) json_response(['ok' => false, 'error' => 'session_id_required'], 400);

$st = $pdo->prepare("SELECT id, status, scope_type, scope_value FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);

if (!$session || !in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'session_not_active'], 403);
}

$req_dept_id = (int)($_REQUEST['dept_id'] ?? 0);

try {
    // جلب التصنيفات
    $cat_st = $pdo->query("SELECT id, name, parent_id, level FROM item_categories WHERE is_active=1 ORDER BY sort_order, name");
    $categories = $cat_st->fetchAll(PDO::FETCH_ASSOC);

    $rows = $pdo->prepare("
        SELECT r.id AS room_id, r.name AS room_name, r.name_en AS room_name_ar,
               f.id AS floor_id, f.name AS floor_name, f.name_en AS floor_name_ar,
               b.id AS building_id, b.name AS building_name, b.name_en AS building_name_ar,
               COUNT(a.id) AS total,
               SUM(EXISTS(
                   SELECT 1 FROM inventory_audits ia
                   WHERE ia.session_id = ? AND ia.asset_id = a.id
                     AND ia.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')
               )) AS done
        FROM item_locations r
        JOIN assets a ON a.location_id = r.id AND a.status NOT IN ('disposed','returned_to_supplier')
        LEFT JOIN item_locations f ON f.id = r.parent_id
        LEFT JOIN item_locations b ON b.id = f.parent_id
        WHERE r.location_type = 'room' AND r.is_active = 1
        GROUP BY r.id, r.name, r.name_en, f.id, f.name, f.name_en, b.id, b.name, b.name_en
        HAVING total > 0 ORDER BY total DESC
    ");
    $rows->execute([$session_id]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);

    // توحيد شكل all_locs مع بقية الحقول (name/name_en) قبل الإرسال — بلا أي
    // تعديل على دوال الواجهة (getRoomName/getBldName/getFlrName) المستخدَمة
    // في أماكن كثيرة حساسة؛ الإصلاح هنا فقط، عند المصدر.
    $all_normalized = array_map(function ($r) {
        return [
            'room_id' => $r['room_id'], 'name' => $r['room_name_ar'] ?: $r['room_name'], 'name_en' => $r['room_name'],
            'floor_id' => $r['floor_id'], 'floor' => $r['floor_name_ar'] ?: $r['floor_name'], 'floor_en' => $r['floor_name'],
            'building_id' => $r['building_id'], 'building' => $r['building_name_ar'] ?: $r['building_name'], 'building_en' => $r['building_name'],
            'total' => $r['total'], 'done' => $r['done'],
        ];
    }, $all);

    if ($req_dept_id > 0) {
        $dq = $pdo->prepare("SELECT id, name FROM departments WHERE id=?");
        $dq->execute([$req_dept_id]);
        $dept = $dq->fetch(PDO::FETCH_ASSOC);

        if ($dept) {
            $hq = $pdo->prepare("
                SELECT a.id AS asset_id, a.location_id, a.custodian_dept_id, s.suggested_dept_id, s.confidence,
                       EXISTS(SELECT 1 FROM inventory_audits ia WHERE ia.session_id = ? AND ia.asset_id = a.id AND ia.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')) AS is_done
                FROM assets a
                LEFT JOIN custody_ai_suggestions s ON s.asset_id = a.id AND s.status IN ('pending', 'accepted')
                WHERE a.location_id IS NOT NULL AND a.status NOT IN ('disposed', 'returned_to_supplier')
                  AND (a.custodian_dept_id = ? OR s.suggested_dept_id = ?)
            ");
            $hq->execute([$session_id, $req_dept_id, $req_dept_id]);
            $linked_assets = $hq->fetchAll(PDO::FETCH_ASSOC);

            $stats = ['custody' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            $valid_room_ids = []; $room_asset_counts = []; $counted_assets = [];

            foreach ($linked_assets as $la) {
                $valid_room_ids[(int)$la['location_id']] = true;
                $aid = (int)$la['asset_id']; $rid = (int)$la['location_id'];
                if (!isset($counted_assets[$aid])) {
                    if ((int)$la['custodian_dept_id'] === $req_dept_id) $stats['custody']++;
                    if ((int)$la['suggested_dept_id'] === $req_dept_id) {
                        $c = $la['confidence']; if ($c && isset($stats[$c])) $stats[$c]++;
                    }
                    if (!isset($room_asset_counts[$rid])) $room_asset_counts[$rid] = ['total' => 0, 'done' => 0];
                    $room_asset_counts[$rid]['total']++;
                    if ($la['is_done']) $room_asset_counts[$rid]['done']++;
                    $counted_assets[$aid] = true;
                }
            }

            $filtered_rooms = [];
            foreach ($all as $r) {
                $rid = (int)$r['room_id'];
                if (isset($valid_room_ids[$rid])) {
                    $filtered_rooms[] = [
                        'id' => $rid, 'name' => ($r['room_name_ar'] ?: $r['room_name']), 'name_en' => $r['room_name'],
                        'floor_id' => (int)$r['floor_id'], 'floor' => ($r['floor_name_ar'] ?: $r['floor_name']) ?: '', 'floor_en' => $r['floor_name'] ?: '',
                        'building_id'=> (int)$r['building_id'], 'building' => ($r['building_name_ar'] ?: $r['building_name']) ?: (($r['floor_name_ar'] ?: $r['floor_name']) ?: '—'), 'building_en' => $r['building_name'] ?: ($r['floor_name'] ?: '—'),
                        'total' => (int)$room_asset_counts[$rid]['total'], 'done' => (int)$room_asset_counts[$rid]['done'], 'is_fp' => true
                    ];
                }
            }
            usort($filtered_rooms, function ($a, $b) { return $b['total'] <=> $a['total']; });
            json_response(['ok' => true, 'dept_mode' => true, 'dept' => ['id' => $dept['id'], 'name' => $dept['name']], 'stats' => $stats, 'rooms' => $filtered_rooms, 'categories'=> $categories, 'all_locs' => $all_normalized]);
            exit;
        }
    }

    $dept_ids = [];
    if ($session['scope_type'] === 'department') {
        $raw = json_decode($session['scope_value'] ?? '[]', true) ?: [];
        $dept_ids = array_values(array_filter(array_map('intval', (array)$raw)));
    }

    $fp_room_ids = [];
    if ($dept_ids) {
        $ph = implode(',', array_fill(0, count($dept_ids), '?'));
        $fq = $pdo->prepare("SELECT DISTINCT a.location_id FROM assets a LEFT JOIN custody_ai_suggestions s ON s.asset_id = a.id AND s.status IN ('pending','accepted') WHERE a.location_id IS NOT NULL AND (a.custodian_dept_id IN ($ph) OR s.suggested_dept_id IN ($ph))");
        $fq->execute(array_merge($dept_ids, $dept_ids));
        $fp_room_ids = array_flip(array_map('intval', $fq->fetchAll(PDO::FETCH_COLUMN)));
    }

    $fingerprint = []; $others_by_building = [];
    foreach ($all as $r) {
        $room = [
            'id' => (int)$r['room_id'], 'name' => ($r['room_name_ar'] ?: $r['room_name']), 'name_en' => $r['room_name'],
            'floor_id' => (int)$r['floor_id'], 'floor' => ($r['floor_name_ar'] ?: $r['floor_name']) ?: '', 'floor_en' => $r['floor_name'] ?: '',
            'building_id'=> (int)$r['building_id'], 'building' => ($r['building_name_ar'] ?: $r['building_name']) ?: (($r['floor_name_ar'] ?: $r['floor_name']) ?: '—'), 'building_en' => $r['building_name'] ?: ($r['floor_name'] ?: '—'),
            'total' => (int)$r['total'], 'done' => (int)$r['done'], 'is_fp' => isset($fp_room_ids[(int)$r['room_id']]),
        ];
        if ($room['is_fp']) $fingerprint[] = $room; else $others_by_building[$room['building']][] = $room;
    }
    usort($fingerprint, function ($a, $b) {
        $ca = ($a['done'] >= $a['total']); $cb = ($b['done'] >= $b['total']);
        if ($ca !== $cb) return $ca <=> $cb; return $b['total'] <=> $a['total'];
    });

    $others = [];
    foreach ($others_by_building as $bname => $rooms) { $others[] = ['building' => $bname, 'building_en' => $rooms[0]['building_en'] ?? $bname, 'rooms' => $rooms]; }
    usort($others, fn($a, $b) => strcmp($a['building'], $b['building']));

    json_response(['ok' => true, 'scoped_dept' => (bool)$dept_ids, 'fingerprint' => $fingerprint, 'others' => $others, 'categories' => $categories, 'all_locs' => $all_normalized]);

} catch (Throwable $e) { json_response(['ok' => false, 'error' => 'خطأ داخلي: ' . $e->getMessage()], 500); }