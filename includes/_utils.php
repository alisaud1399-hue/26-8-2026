<?php
/**
 * includes/_utils.php — دوال مساعدة مشتركة بين كل الوحدات
 *
 * يُستخدم لعرض التسميات ثنائية اللغة، مساعدات التواريخ،
 * وتنسيق المخرجات بشكل موحّد عبر النظام.
 *
 * ──────────────────────────────────────────────────────────────
 * لماذا هذا الملف منفصل؟
 * • assets/includes/_utils.php خاص بوحدة الأصول (دوال حسابية للأصول)
 * • هذا الملف = مشترك (تصنيفات، مواقع، إعدادات، تقارير...)
 * • كل صفحة تعمل require_once dirname(__DIR__) . '/includes/_utils.php';
 */

if (!defined('PMSH_INCLUDES_UTILS')) {

define('PMSH_INCLUDES_UTILS', true);

/**
 * display_name() — عرض اسم ثنائي اللغة من صف (category/location/...)
 *
 * الاستخدام:
 *   <td><?= e(display_name($cat)) ?></td>
 *   <td><?= e(display_name($cat, 'en')) ?></td>
 *
 * @param array  $row    الصف المطلوب (يجب أن يحوي name + name_en)
 * @param string|null $lang  'ar' | 'en' | null=تلقائي حسب لغة الواجهة
 * @return string  الاسم المعروض (مع fallback إذا كانت اللغة المطلوبة فارغة)
 */
function display_name(array $row, ?string $lang = null): string {
    if ($lang === null) {
        $lang = function_exists('is_rtl') && is_rtl() ? 'ar' : 'en';
    }
    // للأسماء ذات طبيعة "إنجليزية بشكل أساسي" (مثل item_locations الحالية)
    // نعكس المنطق عند طلب 'ar' ليرجع name_en (الذي يفترض أن يكون عربي)
    $primary   = $lang === 'en' ? 'name_en' : 'name';
    $fallback  = $lang === 'en' ? 'name'   : 'name_en';

    if (!empty($row[$primary]))   return $row[$primary];
    if (!empty($row[$fallback]))  return $row[$fallback];
    return '';
}

/**
 * display_bilingual() — عرض ثنائي اللغة جنباً إلى جنب
 *
 * الاستخدام في الجداول الإدارية:
 *   <td><?= e(display_bilingual($cat)) ?></td>
 *   → يعرض: "معدات طبية / Medical Equipment"
 *   → أو:     "معدات طبية / <span class='muted'>— غير مترجم —</span>"
 *
 * @param array $row
 * @param string $sep الفاصل (افتراضي " / ")
 * @return string
 */
function display_bilingual(array $row, string $sep = ' / '): string {
    $ar = $row['name']    ?? '';
    $en = $row['name_en'] ?? '';
    if ($ar && $en)  return $ar . $sep . $en;
    if ($ar)        return $ar . $sep . '<span style="color:#dc2626;font-style:italic">⚠ EN missing</span>';
    if ($en)        return '<span style="color:#dc2626;font-style:italic">⚠ AR missing</span>' . $sep . $en;
    return '<span style="color:#94a3b8">—</span>';
}

/**
 * tr_needs_translation() — هل هذا الصف بحاجة لترجمة؟
 *
 * الاستخدام في Bulk Translation Helper:
 *   if (tr_needs_translation($cat, 'en')) { ... اعرضه ... }
 */
function tr_needs_translation(array $row, string $lang): bool {
    $field = $lang === 'en' ? 'name_en' : 'name';
    return empty($row[$field]);
}

/**
 * safe_lang_label() — حماية ضد أسماء طويلة جداً في الواجهة
 */
function safe_lang_label(string $s, int $max = 60): string {
    $s = trim($s);
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max - 1) . '…';
}

/**
 * locale_flag() — علم صغير مرتبط باللغة (يستخدم في الواجهة لإظهار اللغة المتاحة)
 */
function locale_flag(string $lang): string {
    return $lang === 'en' ? '🇬🇧' : '🇸🇦';
}

// ════════════════════════════════════════════════════════════════
//  AI Provider Abstraction — كل الـ AI endpoints تستخدم هذه الدوال
// ════════════════════════════════════════════════════════════════

/**
 * سر تشفير AES-256-CBC لمفاتيح API المخزّنة في DB
 * مُشتقّ من APP_SECRET_SALT في config.php
 */
function ai_secret(): string {
    $salt = defined('APP_SECRET_SALT') ? APP_SECRET_SALT : 'pmsh-default-salt';
    return hash('sha256', 'pmsh-ai-keys-v1' . $salt, true); // 32 bytes for AES-256
}

/**
 * تشفير مفتاح API للحفظ في DB
 */
function ai_key_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = substr(hash('sha256', random_bytes(16), true), 0, 16);
    $encrypted = openssl_encrypt($plain, 'AES-256-CBC', ai_secret(), OPENSSL_RAW_DATA, $iv);
    return 'enc1:' . base64_encode($iv . $encrypted);
}

/**
 * فك تشفير مفتاح API
 */
function ai_key_decrypt(string $stored): string {
    if ($stored === '') return '';
    // لو مو مشفّر (نص عادي قديم) نرجعه كما هو
    if (strpos($stored, 'enc1:') !== 0) {
        // heuristic: مفاتيح Groq تبدأ بـ gsk-، OpenAI بـ sk-، DeepSeek بـ sk-
        if (preg_match('/^(gsk|sk|sk-or|sk-deep)/', $stored)) return $stored;
        return ''; // مش مفتاح صحيح
    }
    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', ai_secret(), OPENSSL_RAW_DATA, $iv);
    return $decrypted === false ? '' : $decrypted;
}

/**
 * إعدادات AI الموحدة: مفتاح + مزود + model + base_url
 * يقرأ من DB (admin settings) أولاً، ثم config.php كـ fallback
 * cached في static — يُستدعى مرة واحدة لكل request
 */
function ai_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'provider' => get_setting('ai_provider', 'groq'),
        'api_key'  => '',
        'model'    => get_setting('ai_model', 'openai/gpt-oss-20b'),
        'base_url' => get_setting('ai_base_url', 'https://api.groq.com/openai/v1'),
    ];

    // 1) جرّب من DB (المشفّر)
    $encrypted = (string)get_setting('groq_api_key', '');
    if ($encrypted !== '') {
        $cache['api_key'] = ai_key_decrypt($encrypted);
    }

    // 2) Fallback للـ config.php
    if ($cache['api_key'] === '' && defined('GROQ_API_KEY')) {
        $cache['api_key'] = GROQ_API_KEY;
    }
    if (empty($cache['model']) && defined('GROQ_MODEL') && GROQ_MODEL !== '') {
        $cache['model'] = GROQ_MODEL;
    }
    if (empty($cache['base_url']) && defined('GROQ_BASE_URL')) {
        $cache['base_url'] = GROQ_BASE_URL;
    }

    return $cache;
}

/** مفتاح API (مفكوك التشفير) — جاهز للاستخدام في cURL */
function ai_key(): string {
    return ai_settings()['api_key'];
}

/** اسم الموديل */
function ai_model(): string {
    return ai_settings()['model'];
}

/** base URL للـ API */
function ai_base_url(): string {
    return ai_settings()['base_url'];
}

/** اسم المزود ('groq', 'openai', 'deepseek', 'custom') */
function ai_provider(): string {
    return ai_settings()['provider'];
}

/**
 * يُرجع true لو الإعدادات جاهزة (مفتاح + base URL + model)
 */
function ai_is_ready(): bool {
    $s = ai_settings();
    return $s['api_key'] !== '' && $s['base_url'] !== '' && $s['model'] !== '';
}

/**
 * يُرجع الـ model + key + base URL المناسب بناءً على المزود المختار
 * (يستعمل defaults ذكية لو الـ DB فاضية)
 */
function ai_defaults_for_provider(string $provider): array {
    $defaults = [
        'groq' => [
            'base_url' => 'https://api.groq.com/openai/v1',
            'model'    => 'llama-3.3-70b-versatile',
        ],
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
            'model'    => 'gpt-4o-mini',
        ],
        'deepseek' => [
            'base_url' => 'https://api.deepseek.com/v1',
            'model'    => 'deepseek-chat',
        ],
        'custom' => [
            'base_url' => '',
            'model'    => '',
        ],
    ];
    return $defaults[$provider] ?? $defaults['groq'];
}

/**
 * ترجمة نص مع fallback تلقائي — Groq → MyMemory (مجاني)
 * يُرجع ['text'=>..., 'source'=>..., 'ok'=>bool]
 */
function translate_with_fallback(string $text, string $source_lang, string $target_lang, string $context = 'general'): array {
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'text' => '', 'source' => 'empty'];

    $sys_ar_to_en = <<<SYS
You are an expert translator for a Saudi government hospital. Translate the Arabic name into concise, standard English.

STRICT RULES:
1. Use standard medical/administrative English terms.
2. Keep it concise (a noun phrase, not a sentence).
3. Common terms: إدارة=Department | قسم=Department | وحدة=Unit | مبنى=Building | طابق=Floor | غرفة=Room | عيادة=Clinic | مختبر=Laboratory | مركز=Center | مستودع=Warehouse
4. Output ONLY the English translation. No quotes, no notes, no Arabic.

Examples:
"إدارة التشييد والصيانة" -> Construction & Maintenance Department
"قسم الطوارئ" -> Emergency Department
"وحدة العناية المركزة" -> Intensive Care Unit
"المستودع المركزي" -> Central Warehouse
"إدارة الشؤون الإدارية" -> Administrative Affairs Department
SYS;

    $sys_en_to_ar = <<<SYS
You are an expert medical translator for a Saudi government hospital. Translate the English name into formal Arabic.

STRICT RULES:
1. EXPAND medical abbreviations to their full Arabic meaning.
2. Fixed terms: Department=إدارة | Unit=وحدة | Building=مبنى | Floor=طابق | Room=غرفة | Clinic=عيادة | Laboratory=المختبر
3. Keep trailing numbers/codes as-is.
4. Output ONLY the Arabic translation. No quotes, no notes, no English.
SYS;

    $sys = ($target_lang === 'en') ? $sys_ar_to_en : $sys_en_to_ar;
    $direction = ($target_lang === 'en') ? 'Arabic to English' : 'English to Arabic';
    $prompt = "Translate to " . ($target_lang === 'en' ? 'English' : 'Arabic') . ":\n\"$text\"";

    // ── 1) جرّب Groq بالموديل المعدّل ──
    $s = ai_settings();
    if ($s['api_key'] !== '') {
        $result = _call_groq($s['base_url'], $s['api_key'], $s['model'], $sys, $prompt);
        if ($result !== null) return ['ok' => true, 'text' => $result, 'source' => 'groq:' . $s['model']];

        // ── 2) جرّب موديل أقوى ──
        $fallback_model = ($s['model'] !== 'openai/gpt-oss-120b') ? 'openai/gpt-oss-120b' : 'openai/gpt-oss-20b';
        $result = _call_groq($s['base_url'], $s['api_key'], $fallback_model, $sys, $prompt);
        if ($result !== null) return ['ok' => true, 'text' => $result, 'source' => 'groq:' . $fallback_model];
    }

    // ── 3) MyMemory (مجاني، بدون مفتاح) ──
    $mm_lang = ($target_lang === 'en') ? 'ar|en' : 'en|ar';
    $mm_url = 'https://api.mymemory.translated.net/get?q=' . urlencode($text) . '&langpair=' . $mm_lang;
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $resp = @file_get_contents($mm_url, false, $ctx);
    if ($resp) {
        $mm = json_decode($resp, true);
        $tr = trim($mm['responseData']['translatedText'] ?? '');
        if ($tr !== '' && strtolower($tr) !== strtolower($text)) {
            return ['ok' => true, 'text' => $tr, 'source' => 'mymemory'];
        }
    }

    return ['ok' => false, 'text' => '', 'source' => 'all_failed'];
}

/** استدعاء Groq داخلي — يُرجع النص المترجم أو null */
function _call_groq(string $base_url, string $api_key, string $model, string $sys, string $user_msg): ?string {
    $ch = curl_init($base_url . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user_msg],
            ],
            'temperature' => 0.1,
            'max_tokens'   => 150,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$res) return null;

    $j = json_decode($res, true);
    $out = trim($j['choices'][0]['message']['content'] ?? '');
    $out = trim($out, " \t\n\r\0\x0B\"'`");
    $out = preg_replace('/^(English|Arabic|Translation|Output|Result):\s*/i', '', $out);
    $out = trim($out, " \t\n\r\0\x0B\"'`");

    return ($out !== '') ? $out : null;
}

} // PMSH_INCLUDES_UTILS guard

// ════════════════════════════════════════════════════════════════
//  Lazy Cron: التصعيد التلقائي عند تحميل الصفحة
// ════════════════════════════════════════════════════════════════

/**
 * run_lazy_cron() — يفحص البلاغات المتأخرة عند كل تحميل صفحة
 *
 * كيف يعمل:
 *   1. يقرأ آخر وقت شغّل فيه الـ cron من DB (cron.last_complaint_check)
 *   2. إذا مرّ 5 دقائق أو أكثر → يشغّل run_complaint_escalation()
 *   3. يحدّث الوقت في DB
 *
 * يُستدعى من register_lazy_cron() عبر register_shutdown_function()
 * مرة واحدة فقط لكل طلب HTTP، وبشكل آمن (لا تداخل).
 */
if (!function_exists('run_lazy_cron')) {
function run_lazy_cron(): void {
    global $pdo;

    // لا يشتغل في CLI
    if (php_sapi_name() === 'cli') return;

    // لا يشتغل إذا لم يُحمَّل قاعدة البيانات بعد
    if (!isset($pdo)) return;

    // لا يشتغل إذا المستخدم غير مسجّل (يرى صفحات عامة فقط)
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['user_id'])) return;

    // لا يشتغل إذا التصعيد معطَّل في الإعدادات
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        // هل التصعيد التلقائي أو الإغلاق التلقائي مفعَّل؟
        $enabled = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='auto_escalation_enabled'")->fetchColumn();
        $autoCloseDays = (int) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='complaint_auto_close_days'")->fetchColumn() ?? 0);
        if ($enabled === '0' && $autoCloseDays <= 0) return;

        // آخر وقت تحقق
        $lastRun = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='cron.last_complaint_check'")->fetchColumn();
        $minInterval = 300; // 5 دقائق
        if ($lastRun && (time() - strtotime($lastRun)) < $minInterval) return;

        // حدّث الوقت أولاً (منع التداخل)
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('cron.last_complaint_check', NOW()) ON DUPLICATE KEY UPDATE setting_value=NOW()")->execute();

        // شغّل التصعيد
        if (!function_exists('run_complaint_escalation')) {
            require_once BASE_PATH . '/cron/escalate_complaints.php';
        }
        if (function_exists('run_complaint_escalation')) {
            $result = run_complaint_escalation();
            if ($result['escalated'] > 0 || $result['breached'] > 0) {
                error_log("lazy_cron: escalated={$result['escalated']}, breached={$result['breached']}, total={$result['total']}");
            }
        }
    } catch (Exception $e) {
        error_log("lazy_cron error: " . $e->getMessage());
    }
}
}

/**
 * register_lazy_cron() — يسجّل Lazy Cron ليشغل عند انتهاء الطلب
 * يُستدعى من config.php بعد اتصال قاعدة البيانات
 */
if (!function_exists('register_lazy_cron')) {
function register_lazy_cron(): void {
    if (php_sapi_name() === 'cli') return;
    register_shutdown_function('run_lazy_cron');
}
}