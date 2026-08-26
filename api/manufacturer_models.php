<?php
/**
 * api/manufacturer_models.php — جلب/إضافة موديلات (مفلتر بالشركة)
 *
 * GET:
 *   manufacturer_id (int, required)
 *   q               (string, optional) - فلتر بالاسم
 *
 * POST action=create:
 *   manufacturer_id (int, required)
 *   model_number    (string, required)
 *
 * Returns:
 *   GET:  { ok, manufacturer_id, count, models: [{id, model_number, manufacturer_id, manufacturer_name}] }
 *   POST: { ok, id, model_number, manufacturer_id }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$rtl = is_rtl();

// ===== GET =====
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $manufacturer_id = (int)($_REQUEST['manufacturer_id'] ?? 0);
    $q = trim($_REQUEST['q'] ?? '');

    if ($manufacturer_id <= 0) {
        json_response(['ok' => false, 'error' => 'manufacturer_id_required'], 400);
    }

    $where = ['mm.manufacturer_id = ?', 'mm.is_active = 1'];
    $params = [$manufacturer_id];
    if ($q !== '') {
        $where[] = 'mm.model_number LIKE ?';
        $params[] = "%$q%";
    }

    $sql = "SELECT mm.id, mm.manufacturer_id, mm.model_number,
                   mfr.name AS manufacturer_name, mfr.name_en AS manufacturer_name_en
            FROM manufacturer_models mm
            JOIN manufacturers mfr ON mfr.id = mm.manufacturer_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY mm.model_number
            LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['manufacturer_id'] = (int)$r['manufacturer_id'];
    }
    unset($r);

    json_response([
        'ok' => true,
        'manufacturer_id' => $manufacturer_id,
        'q' => $q,
        'count' => count($rows),
        'models' => $rows,
    ]);
}

// ===== POST: إضافة موديل جديد =====
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    if ($action !== 'create') {
        json_response(['ok' => false, 'error' => 'invalid_action', 'allowed' => ['create']], 400);
    }

    $manufacturer_id = (int)($input['manufacturer_id'] ?? 0);
    $model_number = trim($input['model_number'] ?? '');

    if ($manufacturer_id <= 0) json_response(['ok' => false, 'error' => 'manufacturer_id_required'], 400);
    if ($model_number === '') json_response(['ok' => false, 'error' => 'model_number_required'], 400);

    // فحص تكرار
    $ex = $pdo->prepare("SELECT id FROM manufacturer_models WHERE manufacturer_id = ? AND model_number = ?");
    $ex->execute([$manufacturer_id, $model_number]);
    if ($ex->fetch()) {
        $existing = $ex->fetch();
        json_response([
            'ok' => true,
            'id' => (int)$existing['id'],
            'model_number' => $model_number,
            'manufacturer_id' => $manufacturer_id,
            'already_exists' => true,
        ]);
    }

    try {
        $ins = $pdo->prepare("
            INSERT INTO manufacturer_models (manufacturer_id, model_number, is_active, created_at)
            VALUES (?, ?, 1, NOW())
        ");
        $ins->execute([$manufacturer_id, $model_number]);
        $new_id = (int)$pdo->lastInsertId();

        json_response([
            'ok' => true,
            'id' => $new_id,
            'model_number' => $model_number,
            'manufacturer_id' => $manufacturer_id,
        ]);
    } catch (Exception $e) {
        json_response(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
    }
}

json_response(['ok' => false, 'error' => 'method'], 405);