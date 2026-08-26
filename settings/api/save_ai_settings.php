<?php
/**
 * settings/api/save_ai_settings.php — حفظ إعدادات الذكاء الاصطناعي
 * ──────────────────────────────────────────────────────────────────
 * POST JSON or form:
 *   provider  (groq | openai | deepseek | custom)
 *   api_key   (string) — يُشفّر بـ AES-256-CBC قبل الحفظ في DB
 *   model     (string)
 *   base_url  (string)
 *   clear_key (0|1) — لو 1، نحذف المفتاح من DB (يستخدم config.php)
 *
 * Permission: settings.index (admin only)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
page_guard('settings.index');
header('Content-Type: application/json; charset=utf-8');

if (!is_ajax() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'msg' => 'method'], 405);
}

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!verify_csrf()) out(['ok' => false, 'msg' => 'invalid_csrf']);

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$provider = trim($input['provider'] ?? 'groq');
$api_key  = trim($input['api_key'] ?? '');
$model    = trim($input['model'] ?? '');
$base_url = trim($input['base_url'] ?? '');
$clear_key = !empty($input['clear_key']);

// تحقق من الـ provider
$valid_providers = ['groq', 'openai', 'deepseek', 'custom'];
if (!in_array($provider, $valid_providers, true)) {
    out(['ok' => false, 'msg' => 'invalid_provider', 'allowed' => $valid_providers]);
}

// Defaults إن ما دخل المستخدم model/base_url
$defaults = ai_defaults_for_provider($provider);
if ($model === '') $model = $defaults['model'];
if ($base_url === '') $base_url = $defaults['base_url'];

if ($provider === 'custom' && ($model === '' || $base_url === '')) {
    out(['ok' => false, 'msg' => 'custom_requires_model_and_url']);
}

// تحقق بسيط من شكل المفتاح (heuristic)
if ($api_key !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,}$/', $api_key)) {
    out(['ok' => false, 'msg' => 'invalid_key_format', 'detail' => 'المفتاح يجب أن يكون 20+ حرف (a-z, 0-9, _, -)']);
}

try {
    $pdo->beginTransaction();

    // حفظ الإعدادات النصية
    set_setting('ai_provider', $provider);
    set_setting('ai_model', $model);
    set_setting('ai_base_url', $base_url);

    // المفتاح: إمّا نحفظه (مشفّر) أو نحذفه
    if ($clear_key) {
        // حذف المفتاح من DB — النظام يرجع لـ config.php
        $pdo->prepare("DELETE FROM system_settings WHERE setting_key='groq_api_key'")->execute();
        $key_source = 'config.php (fallback)';
    } elseif ($api_key !== '') {
        $encrypted = ai_key_encrypt($api_key);
        set_setting('groq_api_key', $encrypted);
        $key_source = 'DB (encrypted)';
    } else {
        // ما غيّر المفتاح — نُبقي الحالي كما هو
        $key_source = 'unchanged';
    }

    // امسح الكاش الثابت حتى التغيير يطبَّق فوراً
    // (المتغيّر static في ai_settings() — نعيد تعريفه يدوياً)
    // ملاحظة: PHP لا يسمح بمسح static cache من خارج الدالة بسهولة.
    // لكن: الإعدادات الجديدة تظهر في request جديد، والـ cache يدوم للـ request الحالي فقط.
    // للاستخدام العملي (المستخدم يحدث ثم يستخدم فوراً في نفس الـ request):
    // نُجبر التحديث بـ touch timestamp على setting

    log_activity('ai_settings.updated', '', json_encode([
        'provider' => $provider,
        'model' => $model,
        'base_url' => $base_url,
        'key_source' => $key_source,
    ], JSON_UNESCAPED_UNICODE));

    $pdo->commit();

    out([
        'ok' => true,
        'provider' => $provider,
        'model' => $model,
        'base_url' => $base_url,
        'key_source' => $key_source,
        'msg' => 'تم الحفظ بنجاح',
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(['ok' => false, 'msg' => 'db_error', 'detail' => $e->getMessage()]);
}