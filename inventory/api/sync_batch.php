<?php
/**
 * inventory/api/sync_batch.php
 * -----------------------------
 * استقبال دفعة من المسحات الميدانية (من PWA IndexedDB)
 *
 * يُستدعى من scan_offline.php عند توفر الإنترنت.
 *
 * POST JSON:
 *   items: [
 *     {
 *       client_id: UUID,        (معرّف فريد من الجهاز — للتكرار)
 *       tag: "BHC002000001",    (إلزامي)
 *       status: "verified|not_found|moved|damaged",
 *       location: "Room 203" (اختياري),
 *       condition: "excellent|good|fair|poor" (اختياري),
 *       notes: "..." (اختياري),
 *       scanned_at: "2026-07-30 14:30:00",
 *       device_id: "abc123...",
 *       user_id: 1,
 *       user_name: "admin"
 *     },
 *     ...
 *   ]
 *
 * Returns:
 *   { ok: true, results: [{ id: 1234, ok: true, asset_id: 567 }, ...] }
 *   { ok: false, error: "..." }
 *
 * Conflict strategy: server wins (لتجنب overwrite للـ local changes)
 *   - asset_id محسوب من tag (lookup)
 *   - إذا tag غير موجود: asset_id = NULL (not_found) أو auto-create? (TODO)
 *
 * Idempotency: client_id unique → إذا تكرر، نتجاهله (UPDATE مكرر)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$items = $input['items'] ?? [];

if (!is_array($items) || empty($items)) {
    echo json_encode(['ok' => false, 'error' => 'no_items'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)(current_user()['id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'no_user'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $pdo;

$results = [];
$success_count = 0;
$skipped_count = 0;
$error_count = 0;

try {
    $pdo->beginTransaction();

    // Pre-fetch: load all existing client_ids to skip duplicates
    $client_ids = array_filter(array_column($items, 'client_id'));
    $existing = [];
    if (!empty($client_ids)) {
        $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
        $stmt = $pdo->prepare("SELECT client_id, id FROM inventory_field_scans WHERE client_id IN ($placeholders)");
        $stmt->execute(array_values($client_ids));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$r['client_id']] = $r['id'];
        }
    }

    // Pre-fetch: load asset_id by tag_number (for existing assets)
    $tags = array_unique(array_filter(array_column($items, 'tag')));
    $tag_to_asset = [];
    if (!empty($tags)) {
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $stmt = $pdo->prepare("SELECT id, tag_number FROM assets WHERE tag_number IN ($placeholders) AND status != 'disposed'");
        $stmt->execute(array_values($tags));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tag_to_asset[$r['tag_number']] = (int)$r['id'];
        }
    }

    $insert_stmt = $pdo->prepare("
        INSERT INTO inventory_field_scans
          (client_id, asset_id, tag_number, scan_status,
           found_location, notes, device_user_agent, device_id,
           scanned_at_local, scanned_by_user_id, synced_at, synced_by_user_id, session_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
          asset_id = VALUES(asset_id),
          scan_status = VALUES(scan_status),
          found_location = VALUES(found_location),
          notes = VALUES(notes),
          synced_at = NOW()
    ");

    $update_asset_stmt = $pdo->prepare("
        UPDATE assets SET
            verified_status = ?,
            verified_at = NOW(),
            verified_by = ?,
            custody_dept_verified = CASE WHEN ? = 'verified' THEN 1 ELSE custody_dept_verified END
        WHERE id = ?
    ");

    $log_stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, target, details, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");

    foreach ($items as $item) {
        $client_id = trim($item['client_id'] ?? '');
        $tag = strtoupper(trim($item['tag'] ?? ''));
        $status = trim($item['status'] ?? '');
        $location = trim($item['location'] ?? '');
        $notes = trim($item['notes'] ?? '');
        $condition = trim($item['condition'] ?? '');
        $scanned_at = trim($item['scanned_at'] ?? date('Y-m-d H:i:s'));
        $device_id = trim($item['device_id'] ?? '');
        $scanned_by_user_id = (int)($item['user_id'] ?? $user_id) ?: $user_id;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_short = substr($ua, 0, 500);

        // Map status → verified_status
        $verified_status_map = [
            'verified'  => 'تم التحقق',
            'not_found' => 'لم يتم التحقق',
            'moved'     => 'تم التحقق',
            'damaged'   => 'تم التحقق',
        ];
        $verified_status = $verified_status_map[$status] ?? null;

        if (!$client_id || !$tag || !$status) {
            $results[] = ['id' => null, 'ok' => false, 'error' => 'missing_required', 'tag' => $tag];
            $error_count++;
            continue;
        }

        if (!in_array($status, ['verified', 'not_found', 'moved', 'damaged'], true)) {
            $results[] = ['id' => null, 'ok' => false, 'error' => 'invalid_status', 'tag' => $tag];
            $error_count++;
            continue;
        }

        // Skip if already exists (idempotency)
        if (isset($existing[$client_id])) {
            $results[] = ['id' => $existing[$client_id], 'ok' => true, 'skipped' => 'duplicate', 'tag' => $tag];
            $skipped_count++;
            continue;
        }

        // Lookup asset_id
        $asset_id = $tag_to_asset[$tag] ?? null;

        try {
            $insert_stmt->execute([
                $client_id,
                $asset_id,
                $tag,
                $status,
                $location,
                $notes,
                $ua_short,
                $device_id,
                $scanned_at,
                $scanned_by_user_id,
                $user_id,  // synced_by_user_id = current logged-in user
                null  // session_id (TODO: detect from session_token)
            ]);
            $new_id = (int)$pdo->lastInsertId();

            // If asset exists and status is verified/moved → update assets.verified_status
            if ($asset_id && in_array($status, ['verified', 'moved'], true)) {
                $update_asset_stmt->execute([$verified_status, $user_id, $status, $asset_id]);
            }

            // Log activity (lightweight)
            $log_stmt->execute([
                $user_id,
                'inventory.field_scanned',
                "scan:$new_id",
                json_encode([
                    'tag' => $tag, 'status' => $status,
                    'asset_id' => $asset_id, 'client_id' => $client_id
                ], JSON_UNESCAPED_UNICODE)
            ]);

            $results[] = ['id' => $new_id, 'ok' => true, 'tag' => $tag, 'asset_id' => $asset_id];
            $success_count++;
        } catch (Exception $e) {
            $results[] = ['id' => null, 'ok' => false, 'error' => $e->getMessage(), 'tag' => $tag];
            $error_count++;
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'total' => count($items),
        'success' => $success_count,
        'skipped' => $skipped_count,
        'errors' => $error_count,
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'ok' => false,
        'error' => 'transaction_failed: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
