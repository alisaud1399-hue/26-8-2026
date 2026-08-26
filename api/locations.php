<?php
/**
 * api/locations.php — جلب المواقع الفرعية (Floors / Rooms / Search)
 *
 * Parameters:
 *   action    (string) required: 'floors' | 'rooms' | 'buildings' | 'search'
 *   parent_id (int)    required for 'floors' | 'rooms'
 *   q         (string) required for 'search'
 *
 * Returns:
 *   { ok: true, locations: [{id, name, name_en, location_type, path}] }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action    = $_REQUEST['action'] ?? '';
$parent_id = (int)($_REQUEST['parent_id'] ?? 0);
$q         = trim($_REQUEST['q'] ?? '');

if (!in_array($action, ['buildings', 'floors', 'rooms', 'search'], true)) {
    json_response(['ok' => false, 'error' => 'invalid_action'], 400);
}

try {
    if ($action === 'buildings') {
        $st = $pdo->prepare("SELECT id, name, name_en, location_type FROM item_locations WHERE location_type = 'building' AND is_active = 1 ORDER BY name_en");
        $st->execute();
    } elseif ($action === 'floors' || $action === 'rooms') {
        $child_type = $action === 'floors' ? 'floor' : 'room';
        $st = $pdo->prepare("SELECT id, name, name_en, location_type FROM item_locations WHERE parent_id = ? AND location_type = ? AND is_active = 1 ORDER BY name_en");
        $st->execute([$parent_id, $child_type]);
    } else {
        // search: ابحث في أي مستوى (building/floor/room) بالاسم، أعد المسار الكامل
        if ($q === '') {
            json_response(['ok' => true, 'q' => '', 'count' => 0, 'locations' => []]);
        }
        $st = $pdo->prepare("
            SELECT
                l.id, l.name, l.name_en, l.location_type,
                b.name AS b_name, b.name_en AS b_name_en,
                f.name AS f_name, f.name_en AS f_name_en
            FROM item_locations l
            LEFT JOIN item_locations f ON f.id = l.parent_id
            LEFT JOIN item_locations b ON b.id = f.parent_id
            WHERE l.is_active = 1 AND l.location_type IN ('building','floor','room')
              AND (l.name LIKE ? OR l.name_en LIKE ?)
            ORDER BY l.location_type DESC
            LIMIT 20
        ");
        $st->execute(["%$q%", "%$q%"]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Fix data swap in item_locations (name=English, name_en=Arabic — opposite of convention)
    // Return both as-is from DB, but flag the convention so JS can decide
    foreach ($rows as &$r) {
        $r['name_raw']    = $r['name'];    // actual English text
        $r['name_en_raw'] = $r['name_en']; // actual Arabic text
        // Keep the conventional shape: name=Arabic (primary), name_en=English
        $r['name']    = $r['name_en_raw'] ?: $r['name_raw']; // Arabic becomes 'name'
        $r['name_en'] = $r['name_raw'];    // English becomes 'name_en'
        // For search: build path (Building > Floor > Room)
        if ($action === 'search') {
            $bname = $r['b_name_en'] ?: $r['b_name']; // English name of building
            $fname = $r['f_name_en'] ?: $r['f_name'];
            $parts = array_filter([$bname, $fname, $r['name_en']]);
            $r['path'] = implode(' > ', $parts);
        }
    }
    unset($r);

    json_response([
        'ok'        => true,
        'action'    => $action,
        'parent_id' => $parent_id,
        'q'         => $q,
        'count'     => count($rows),
        'locations' => $rows,
        'note'      => 'item_locations columns are swapped in DB; API normalizes',
    ]);
} catch (Exception $e) {
    json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
}