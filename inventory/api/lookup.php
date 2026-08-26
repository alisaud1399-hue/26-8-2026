<?php
/**
 * inventory/api/lookup.php — يبحث عن أصل برقم التاج أو السيريال
 * يدخل ضمن جلسة جرد نشطة
 *
 * POST JSON أو GET:
 *   tag    (string) - tag_number OR alternative_code OR serial
 *   session(int)   - session_id for scope validation
 *
 * Returns:
 *   { found: bool, asset: {...} | null, scope_in: bool, scope_reason: '...' }
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['tag'])) {
    json_response(['error' => 'method'], 405);
}

$rtl = is_rtl();
$tag = trim($_REQUEST['tag'] ?? '');
$session_id = (int)($_REQUEST['session'] ?? 0);

if ($tag === '') json_response(['found' => false, 'reason' => 'empty_tag'], 400);
if ($session_id <= 0) json_response(['found' => false, 'reason' => 'no_session'], 400);

// تأكيد الجلسة موجودة وحالة نشطة أو مراجعة (يسمح بالمسح فيهما)
$ss = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess = $ss->fetch(PDO::FETCH_ASSOC);
if (!$sess) json_response(['found' => false, 'reason' => 'session_not_found'], 404);
if (!in_array($sess['status'], ['active','review'], true)) {
    json_response(['found' => false, 'reason' => 'session_not_active', 'status' => $sess['status']], 403);
}

// بحث بنفس tag / alternative_code / serial — يبدأ بالتحديد
$st = $pdo->prepare("
    SELECT a.*,
           c1.name AS c1n, c2.name AS c2n, c3.name AS c3n,
           loc.name AS loc_name,
           d.name AS dept_name,
           ia.audited_at AS audited_at,
           u.full_name AS auditor_name,
           (SELECT id FROM known_disposals WHERE (asset_id=a.id OR tag_number=a.tag_number) AND disposal_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) ORDER BY disposal_date DESC LIMIT 1) AS disposal_ref_id,
           (SELECT reference_doc FROM known_disposals WHERE (asset_id=a.id OR tag_number=a.tag_number) ORDER BY disposal_date DESC LIMIT 1) AS disposal_doc
    FROM assets a
    LEFT JOIN item_categories c1 ON c1.id = a.category_id
    LEFT JOIN item_categories c2 ON c2.id = c1.parent_id
    LEFT JOIN item_categories c3 ON c3.id = c2.parent_id
    LEFT JOIN item_locations  loc ON loc.id = a.location_id
    LEFT JOIN departments     d   ON d.id  = a.department_id
    LEFT JOIN inventory_audits ia ON ia.asset_id = a.id AND ia.session_id = ?
    LEFT JOIN users u ON u.id = ia.audited_by
    WHERE a.tag_number = ? OR a.alternative_code = ? OR a.serial_number = ?
    LIMIT 1
");
$st->execute([$session_id, $tag, $tag, $tag]);
$asset = $st->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    json_response([
        'found'      => false,
        'reason'     => 'not_in_db',
        'tag'        => $tag,
        'hint'       => $rtl ? 'هذا التاج غير مسجّل كأصل — قد يكون زيادة (surplus).' : 'Tag not in DB — may be a surplus asset.',
    ]);
}

// تحقّق إن الأصل ضمن نطاق الجلسة
$is_in_scope = check_in_scope($pdo, $session_id, $asset['id']);
$had_audit = (bool)$pdo->query("SELECT 1 FROM inventory_audits WHERE session_id={$session_id} AND asset_id={$asset['id']} AND action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously') LIMIT 1")->fetchColumn();

// تنسيق وقت آخر تدقيق لهذا الأصل ضمن هذه الجلسة (إن وُجد) — نفس صيغة room_assets.php
$asset['audited_at'] = $asset['audited_at'] ? date('Y-m-d h:i A', strtotime($asset['audited_at'])) : null;
$asset['auditor_name'] = $asset['auditor_name'] ?: null;

json_response([
    'found'         => true,
    'asset'         => $asset,
    'in_scope'      => $is_in_scope,
    'had_audit'     => $had_audit,
    'match_method'  => $asset['tag_number'] === $tag ? 'tag' : ($asset['alternative_code'] === $tag ? 'alt_code' : 'serial'),
    'disposal_match' => (bool)$asset['disposal_ref_id'],
    'disposal_doc'  => $asset['disposal_doc'] ?? null,
]);

/**
 * يحدد إذا الأصل داخل نطاق الجلسة (يستخدم نفس المنطق في session.php)
 */
function check_in_scope(PDO $pdo, int $session_id, int $asset_id): bool {
    $st = $pdo->prepare("SELECT scope_type, scope_value FROM inventory_sessions WHERE id=?");
    $st->execute([$session_id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return false;
    $vals = json_decode($s['scope_value'] ?? '[]', true) ?: [];

    switch ($s['scope_type']) {
        case 'all':         return true;
        case 'department':
            if (!$vals) return false;
            $in = implode(',', array_fill(0, count($vals), '?'));
            $c = $pdo->prepare("SELECT 1 FROM assets WHERE id=? AND department_id IN ($in) LIMIT 1");
            $c->execute(array_merge([$asset_id], $vals));
            return (bool)$c->fetchColumn();
        case 'asset_type':
            if (!$vals) return false;
            $in = implode(',', array_fill(0, count($vals), '?'));
            $c = $pdo->prepare("SELECT 1 FROM assets WHERE id=? AND asset_type IN ($in) LIMIT 1");
            $c->execute(array_merge([$asset_id], $vals));
            return (bool)$c->fetchColumn();
        case 'building':
            if (!$vals) return false;
            $in = implode(',', array_fill(0, count($vals), '?'));
            $c = $pdo->prepare("
                SELECT 1 FROM assets a WHERE a.id=? AND (
                  a.location_id IN ($in)
                  OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in))
                  OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id IN ($in)))
                ) LIMIT 1
            ");
            $params = array_merge([$asset_id], $vals, $vals, $vals);
            $c->execute($params);
            return (bool)$c->fetchColumn();
        case 'custom':
            if (!$vals) return false;
            return in_array($asset_id, array_map('intval', $vals), true);
    }
    return false;
}