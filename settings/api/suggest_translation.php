// <?php
/**
 * settings/api/suggest_translation.php — اقتراح ترجمة عبر Groq AI
 * ──────────────────────────────────────────────────────────────
 * الاستخدام من الواجهة:
 *   fetch('settings/api/suggest_translation.php', {
 *     method: 'POST',
 *     body: new FormData()  // + 'text' + 'target' (ar|en) + 'context' (category|location)
 *   })
 *
 * الصلاحية: admin فقط (لحماية الـ API key والـ rate limit)
 * الحد: 60 طلب/دقيقة لكل مستخدم (محمي بـ simple throttle في session)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
// للإدارات: نسمح بصلاحية departments.index.edit
if (($_POST['context'] ?? '') === 'department') {
    if (!can('departments.index','edit')) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
} else {
    page_guard('settings.index');
}
header('Content-Type: application/json; charset=utf-8');

function out(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// rate limiting بسيط عبر session
$_SESSION['sug_calls'] ??= [];
$now = time();
$_SESSION['sug_calls'] = array_filter($_SESSION['sug_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['sug_calls']) >= 30) {
    out(['ok' => false, 'msg' => 'تجاوزت حد الطلبات (30/دقيقة). حاول بعد دقيقة.']);
}
$_SESSION['sug_calls'][] = $now;

$text   = trim($_POST['text'] ?? '');
$target = $_POST['target'] ?? 'en';
$ctx    = $_POST['context'] ?? 'category'; // category | location | generic

if ($text === '') out(['ok' => false, 'msg' => 'النص فارغ']);
if (!in_array($target, ['ar', 'en'])) out(['ok' => false, 'msg' => 'لغة غير مدعومة']);
if (mb_strlen($text) > 200) out(['ok' => false, 'msg' => 'النص طويل جداً (200 حرف كحد أقصى)']);

// ── ترجمة مع fallback ───────────────────────────────────────
$result = translate_with_fallback($text, $target === 'en' ? 'ar' : 'en', $target, $ctx);
if (!$result['ok']) out(['ok' => false, 'msg' => 'فشل الترجمة من جميع المصادر']);

out(['ok' => true, 'suggestion' => $result['text'], 'src' => $result['source']]);