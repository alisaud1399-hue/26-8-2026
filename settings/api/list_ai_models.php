<?php
/**
 * settings/api/list_ai_models.php — جلب النماذج المتاحة من مزود AI
 * ─────────────────────────────────────────────────────────────────
 * POST: provider, api_key (optional), base_url (optional)
 *
 * Returns: { ok, models: [{id, name, owned_by, ...}], provider, count }
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error_type' => 'method', 'error_msg' => 'Method not allowed']); exit;
}

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$provider = trim($input['provider'] ?? '');
$api_key  = trim($input['api_key'] ?? '');
$base_url = trim($input['base_url'] ?? '');

// Resolve key
if ($api_key !== '') {
    $key = $api_key;
} else {
    $key = ai_key();
}
if (!$key) {
    out(['ok' => false, 'error_type' => 'key_missing', 'error_msg' => 'مفتاح API غير موجود', 'error_msg_en' => 'No API key found']);
}

// Resolve base URL
if ($base_url === '') {
    $defaults = ai_defaults_for_provider($provider ?: ai_provider());
    $base_url = $defaults['base_url'] ?? '';
}
if (!$base_url) {
    out(['ok' => false, 'error_type' => 'url_missing', 'error_msg' => 'رابط API غير محدد', 'error_msg_en' => 'API base URL is missing']);
}

// Fetch models
$models_url = rtrim($base_url, '/') . '/models';
$ch = curl_init($models_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ],
]);

$start_time = microtime(true);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
$errno = curl_errno($ch);
$latency = round((microtime(true) - $start_time) * 1000);
curl_close($ch);

if ($errno || !$res) {
    out([
        'ok' => false,
        'error_type' => 'network',
        'error_msg'  => 'خطأ في الاتصال: ' . ($cerr ?: "errno=$errno"),
        'error_msg_en' => 'Connection error: ' . ($cerr ?: "errno=$errno"),
        'base_url' => $base_url,
    ]);
}

if ($code === 401) {
    out(['ok' => false, 'error_type' => 'key_invalid', 'error_msg' => 'مفتاح API غير صالح', 'error_msg_en' => 'Invalid API key', 'http' => 401]);
}
if ($code === 403) {
    out(['ok' => false, 'error_type' => 'forbidden', 'error_msg' => 'المفتاح لا يملك صلاحية', 'error_msg_en' => 'Key lacks permission', 'http' => 403]);
}
if ($code !== 200) {
    out([
        'ok' => false,
        'error_type' => 'unknown',
        'error_msg'  => "خطأ غير متوقع (HTTP $code)",
        'error_msg_en' => "Unexpected error (HTTP $code)",
        'detail' => mb_substr((string)$res, 0, 300),
        'http' => $code,
    ]);
}

$j = json_decode($res, true);
$models = $j['data'] ?? [];

// Sort alphabetically
usort($models, function ($a, $b) {
    return strcmp($a['id'] ?? '', $b['id'] ?? '');
});

// Build clean list
$clean = [];
foreach ($models as $m) {
    $clean[] = [
        'id'        => $m['id'] ?? '',
        'owned_by'  => $m['owned_by'] ?? '',
        'created'   => $m['created'] ?? null,
    ];
}

out([
    'ok'       => true,
    'provider' => $provider ?: ai_provider(),
    'models'   => $clean,
    'count'    => count($clean),
    'latency_ms' => $latency,
    'msg'      => 'تم جلب ' . count($clean) . ' نموذج متاح',
    'msg_en'   => count($clean) . ' models found',
]);
