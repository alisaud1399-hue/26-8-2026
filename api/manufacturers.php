<?php
/**
 * api/manufacturers.php — جلب/إضافة شركات مصنّعة
 *
 * GET (?q=): قائمة الشركات (مع typeahead)
 *   optional q: فلتر بالاسم
 *
 * POST action=create:
 *   name       (string, required) - اسم الشركة
 *   name_en    (string, optional) - English name
 *   country    (string, optional)
 *   website    (string, optional)
 *
 * Returns:
 *   GET:  { ok, manufacturers: [{id, name, name_en, country, model_count}] }
 *   POST: { ok, id, name, name_en }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$rtl = is_rtl();

// ===== GET: قائمة الشركات =====
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $q = trim($_REQUEST['q'] ?? '');
    $where = ['mfr.is_active = 1'];
    $params = [];

    if ($q !== '') {
        $where[] = '(mfr.name LIKE ? OR mfr.name_en LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    $sql = "SELECT mfr.id, mfr.name, mfr.name_en, mfr.country, mfr.website,
                   (SELECT COUNT(*) FROM manufacturer_models mm WHERE mm.manufacturer_id = mfr.id AND mm.is_active = 1) AS model_count
            FROM manufacturers mfr
            WHERE " . implode(' AND ', $where) . "
            ORDER BY mfr.name_en
            LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // اسم العلامة التجارية: name في الـ DB = English (مثل 3M، Philips)
    // نرجّعها كما هي للمستخدم (الاسم التجاري بالإنجليزي)
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['model_count'] = (int)$r['model_count'];
        // name_in_arabic (مترجم تقريبي) - حالياً فاضي
        $r['display'] = $r['name_en'] ?: $r['name'];
    }
    unset($r);

    json_response([
        'ok' => true,
        'q' => $q,
        'count' => count($rows),
        'manufacturers' => $rows,
    ]);
}

// ===== POST: إضافة شركة جديدة =====
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    if ($action !== 'create') {
        json_response(['ok' => false, 'error' => 'invalid_action', 'allowed' => ['create']], 400);
    }

    $name    = trim($input['name'] ?? '');
    $name_en = trim($input['name_en'] ?? '');
    $country = trim($input['country'] ?? '');
    $website = trim($input['website'] ?? '');

    if ($name === '') json_response(['ok' => false, 'error' => 'name_required'], 400);

    // فحص تكرار
    $ex = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ? OR name_en = ?");
    $ex->execute([$name, $name]);
    if ($ex->fetch()) {
        // أعد المعرّف الموجود بدلاً من الخطأ
        $row = $ex->fetch();
        $sel = $pdo->prepare("SELECT id, name, name_en, country, website FROM manufacturers WHERE id = ?");
        $sel->execute([$row['id']]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        json_response([
            'ok' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['name'],
            'name_en' => $existing['name_en'],
            'already_exists' => true,
        ]);
    }

    try {
        $ins = $pdo->prepare("
            INSERT INTO manufacturers (name, name_en, country, website, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $ins->execute([$name, $name_en ?: $name, $country, $website]);
        $new_id = (int)$pdo->lastInsertId();

        json_response([
            'ok' => true,
            'id' => $new_id,
            'name' => $name,
            'name_en' => $name_en ?: $name,
        ]);
    } catch (Exception $e) {
        json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
    }
}

json_response(['ok' => false, 'error' => 'method'], 405);