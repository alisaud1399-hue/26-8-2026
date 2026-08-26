<?php
/**
 * settings/api/test_ai_connection.php — اختبار اتصال مزود الذكاء الاصطناعي
 * ─────────────────────────────────────────────────────────────────────────────
 * POST: provider, model, api_key (optional — uses DB key if empty), base_url
 *
 * Returns:
 *   ok, provider, model, latency_ms, response_preview
 * OR diagnostic:
 *   ok=false, error_type (key_invalid | model_not_found | rate_limit | network | timeout)
 *   error_msg (AR), error_msg_en (EN), suggestion
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
$rtl = is_rtl();
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok'=>false,'error_type'=>'method','error_msg'=>'Method not allowed']); exit;
}

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$provider = trim($input['provider'] ?? 'groq');
$model    = trim($input['model'] ?? '');
$api_key  = trim($input['api_key'] ?? '');
$base_url = trim($input['base_url'] ?? '');

// Resolve key: if user provided one, use it; otherwise get from DB/config
if ($api_key !== '') {
    $key = $api_key;
} else {
    $key = ai_key();
}
if (!$key) {
    out([
        'ok' => false,
        'error_type' => 'key_missing',
        'error_msg'  => 'مفتاح API غير موجود — أدخل مفتاحاً أو احذفه من DB واستخدم config.php',
        'error_msg_en' => 'No API key found — enter a key or check config.php',
        'suggestion' => $rtl ? 'أدخل المفتاح في حقل API Key ثم اضغط حفظ' : 'Enter your API key in the API Key field, then Save',
    ]);
}

// Resolve model
if ($model === '') {
    $defaults = ai_defaults_for_provider($provider);
    $model = $defaults['model'] ?? '';
}
if (!$model) {
    out([
        'ok' => false,
        'error_type' => 'model_missing',
        'error_msg'  => 'لم يُحدد موديل — اختر مزوداً أو اكتب اسم الموديل يدوياً',
        'error_msg_en' => 'No model specified — select a provider or type a model name',
        'suggestion' => $rtl ? 'اختر مزوداً من القائمة المنسدلة لتعبئة الموديل تلقائياً' : 'Select a provider from dropdown to auto-fill model',
    ]);
}

// Resolve base URL
if ($base_url === '') {
    $defaults = ai_defaults_for_provider($provider);
    $base_url = $defaults['base_url'] ?? '';
}
if (!$base_url) {
    out([
        'ok' => false,
        'error_type' => 'url_missing',
        'error_msg'  => 'رابط API غير محدد',
        'error_msg_en' => 'API base URL is missing',
        'suggestion' => $rtl ? 'اترك الحقل فارغاً أو اكتب الرابط يدوياً' : 'Leave empty for default or enter manually',
    ]);
}

// Build test request — simple prompt
$test_prompt = 'Say "OK" in one word.';

$ch = curl_init($base_url . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $test_prompt]],
        'max_tokens'  => 256,
        'temperature' => 0,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
]);

$start_time = microtime(true);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
$errno = curl_errno($ch);
$latency = round((microtime(true) - $start_time) * 1000);
curl_close($ch);

// Network errors
if ($errno || !$res) {
    $suggestion = '';
    if ($errno === 6) {
        $suggestion = $rtl ? 'تحقق من رابط Base URL — ال域名 غير موجود' : 'Check Base URL — domain not found';
    } elseif ($errno === 28) {
        $suggestion = $rtl ? 'الاتصال استغرق وقتاً طويلاً — تحقق من الـ Firewall أو Proxy' : 'Connection timed out — check firewall or proxy';
    } elseif ($errno === 7) {
        $suggestion = $rtl ? 'فشل الاتصال بالخادم — تحقق من الإنترنت' : 'Failed to connect — check internet connection';
    }
    out([
        'ok' => false,
        'error_type' => 'network',
        'error_msg'  => 'خطأ في الاتصال: ' . ($cerr ?: "errno=$errno"),
        'error_msg_en' => 'Connection error: ' . ($cerr ?: "errno=$errno"),
        'suggestion' => $suggestion,
        'base_url'   => $base_url,
    ]);
}

// Parse response
$j = json_decode($res, true);

// HTTP 401 — Invalid API key
if ($code === 401) {
    out([
        'ok' => false,
        'error_type' => 'key_invalid',
        'error_msg'  => '❌ مفتاح API غير صالح (HTTP 401)',
        'error_msg_en' => '❌ Invalid API key (HTTP 401)',
        'suggestion' => $rtl
            ? 'تأكد من صحة المفتاح. انسخه من لوحة تحكم المزود (Groq Console / OpenAI Dashboard).'
            : 'Verify your key. Copy it from the provider dashboard.',
        'http' => 401,
    ]);
}

// HTTP 403 — Key valid but no access
if ($code === 403) {
    out([
        'ok' => false,
        'error_type' => 'key_forbidden',
        'error_msg'  => '⚠️ المفتاح صالح لكن لا يملك صلاحية لهذا الإجراء (HTTP 403)',
        'error_msg_en' => '⚠️ Key is valid but lacks permission (HTTP 403)',
        'suggestion' => $rtl
            ? 'تحقق من أن المفتاح يملك صلاحية Chat Completions. بعض المفاتيح مخصصة للقراءة فقط.'
            : 'Check that your key has Chat Completions permission. Some keys are read-only.',
        'http' => 403,
    ]);
}

// HTTP 429 — Rate limit
if ($code === 429) {
    out([
        'ok' => false,
        'error_type' => 'rate_limit',
        'error_msg'  => '⏳ تم تجاوز حد الطلبات (Rate Limit) — انتظر قليلاً ثم أعد المحاولة',
        'error_msg_en' => '⏳ Rate limit exceeded — wait a moment and try again',
        'suggestion' => $rtl
            ? 'هذا مؤقت. جرّب بعد 30 ثانية. إذا تكرر، قد تحتاج ترقية الخطة.'
            : 'This is temporary. Try again in 30 seconds. If persistent, consider upgrading.',
        'http' => 429,
    ]);
}

// HTTP 404 — Model not found
if ($code === 404) {
    out([
        'ok' => false,
        'error_type' => 'model_not_found',
        'error_msg'  => "⚠️ الموديل \"$model\" غير موجود عند المزود (HTTP 404)",
        'error_msg_en' => "⚠️ Model \"$model\" not found at provider (HTTP 404)",
        'suggestion' => $rtl
            ? "تحقق من اسم الموديل. اضغط 'الizzard' لرؤية الموديلات المتاحة."
            : 'Verify the model name. Click "Defaults" to see available models.',
        'http' => 404,
    ]);
}

// HTTP 400/422 — Bad request (often wrong model name)
if (in_array($code, [400, 422], true)) {
    $detail = mb_substr($j['error']['message'] ?? $res, 0, 300);
    out([
        'ok' => false,
        'error_type' => 'bad_request',
        'error_msg'  => "⚠️ طلب غير صالح (HTTP $code)",
        'error_msg_en' => "⚠️ Bad request (HTTP $code)",
        'detail'     => $detail,
        'suggestion' => $rtl
            ? 'تحقق من اسم الموديل وشكل المفتاح.'
            : 'Check the model name and key format.',
        'http' => $code,
    ]);
}

// HTTP 200 but no valid response
if ($code !== 200) {
    out([
        'ok' => false,
        'error_type' => 'unknown',
        'error_msg'  => "خطأ غير متوقع (HTTP $code)",
        'error_msg_en' => "Unexpected error (HTTP $code)",
        'detail'     => mb_substr((string)$res, 0, 300),
        'http'       => $code,
    ]);
}

// HTTP 200 — check if response is valid
$preview = trim($j['choices'][0]['message']['content'] ?? '');

// Fallback: try alternate response structures
if ($preview === '' && !empty($j['choices'][0]['text'])) {
    $preview = trim($j['choices'][0]['text']);
}
if ($preview === '' && !empty($j['result']['response'])) {
    $preview = trim($j['result']['response']);
}
if ($preview === '' && !empty($j['choices'][0]['delta']['content'])) {
    $preview = trim($j['choices'][0]['delta']['content']);
}

// Reasoning models (o1, o3, openai/gpt-oss-20b) put output in 'reasoning' field
if ($preview === '' && !empty($j['choices'][0]['message']['reasoning'])) {
    $reasoning = trim($j['choices'][0]['message']['reasoning']);
    $preview = $reasoning;
}

if ($preview === '') {
    $raw_preview = mb_substr($res, 0, 400);
    out([
        'ok' => false,
        'error_type' => 'empty_response',
        'error_msg'  => 'المفتاح صالح — لكن الاستجابة فارغة أو بتنسيق غير متوقع',
        'error_msg_en' => 'Key is valid — but response is empty or in unexpected format',
        'suggestion' => $rtl
            ? 'تحقق من أن الموديل يدعم Chat Completions. إذا كنت تستخدم OpenAI، جرّب gpt-4o-mini.'
            : 'Verify the model supports Chat Completions. If using OpenAI, try gpt-4o-mini.',
        'raw_response' => $raw_preview,
        'http' => $code,
    ]);
}

// SUCCESS
out([
    'ok' => true,
    'provider'       => $provider,
    'model'          => $model,
    'latency_ms'     => $latency,
    'response_preview' => mb_substr($preview, 0, 50),
    'msg'            => $rtl ? '✅ الاتصال ناجح!' : '✅ Connection successful!',
    'msg_en'         => '✅ Connection successful!',
]);
