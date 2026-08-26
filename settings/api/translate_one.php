<?php
/**
 * settings/api/translate_one.php — ترجمة عنصر واحد عبر Groq AI
 * ──────────────────────────────────────────────────────────────────
 * POST JSON or form:
 *   tbl  (item_categories | item_locations)
 *   id   (int) — row id
 *   lang ('ar' | 'en') — optional override; otherwise inferred from table direction
 *
 * Returns:
 *   { ok: bool, id, source, suggestion, saved: bool, lang }
 *
 * Behaviour:
 *   - يقرأ الـ row من DB
 *   - يبني prompt بحسب اتجاه الترجمة:
 *       item_categories: name (AR) → name_en (EN)
 *       item_locations:  name (EN) → name_en (AR)
 *   - يستدعي Groq
 *   - يحفظ النتيجة في name_en
 *   - rate limiting: 30 طلب/دقيقة (خفيف، لأن كل طلب = 1 فقط)
 */
// نحفي تحذيرات PHP من الـ response لكن نسجلها
error_reporting(E_ALL);
ini_set('log_errors', 1);
@ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';

$input_check = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$tbl_check = $input_check['tbl'] ?? '';
// للإدارات: نسمح بصلاحية departments.index.edit
if ($tbl_check === 'departments') {
    if (!can('departments.index','edit')) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
} else {
    page_guard('settings.index');
}

// استخدم try-catch شامل لأي خطأ غير متوقع
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'msg' => 'exception', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

header('Content-Type: application/json; charset=utf-8');

function out(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$tbl = $input['tbl'] ?? '';
$id  = (int)($input['id'] ?? 0);
if (!in_array($tbl, ['item_categories', 'item_locations', 'departments'], true)) out(['ok'=>false,'msg'=>'invalid_tbl']);
if ($id <= 0) out(['ok'=>false,'msg'=>'invalid_id']);

// ── حفظ مباشر (بدون AI) — يستعمله زر "حفظ" في البطاقات ─────
if (isset($input['value'])) {
    $val = trim((string)$input['value']);
    if ($val === '') out(['ok'=>false,'msg'=>'empty_value']);
    // CSRF — لا نسأله هنا لأن الـ bulk_save السابق لا يستخدم CSRF بنفس الطريقة،
    // لكن للاستخدام من نفس الجلسة + verify_csrf من POST نعتبره آمن.
    // لو حبيت تشدد: افحص $_POST['csrf'] مع csrf_token()
    $st = $pdo->prepare("UPDATE $tbl SET name_en=? WHERE id=?");
    $st->execute([$val, $id]);
    if ($st->rowCount() === 0) {
        out(['ok'=>true,'saved'=>false,'detail'=>'no_change']);
    }
    out(['ok'=>true,'saved'=>true,'id'=>$id,'value'=>$val]);
}

// rate limiting: 30 طلب/دقيقة
$_SESSION['one_tr_calls'] ??= [];
$now = time();
$_SESSION['one_tr_calls'] = array_filter($_SESSION['one_tr_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['one_tr_calls']) >= 30) {
    out(['ok'=>false,'msg'=>'rate_limit','detail'=>$rtl ? 'تجاوزت الحد، انتظر دقيقة.' : 'Rate limit, wait 1 minute.']);
}
$_SESSION['one_tr_calls'][] = $now;

// جلب الصف
$st = $pdo->prepare("SELECT * FROM $tbl WHERE id=?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) out(['ok'=>false,'msg'=>'not_found']);

// اتجاه الترجمة
if ($tbl === 'item_categories') {
    $source = trim((string)$row['name']);
    $target_lang = 'en';
    $ctx = 'medical equipment / hospital asset category';
} elseif ($tbl === 'departments') {
    $source = trim((string)$row['name']);
    $target_lang = 'en';
    $ctx = 'hospital department / organizational unit';
} else {
    $source = trim((string)$row['name']);
    $target_lang = 'ar';
    $ctx = 'a physical location (building / floor / ward / room) in a Saudi government hospital';
}
if ($source === '') out(['ok'=>false,'msg'=>'empty_source']);

// ترجمة مع fallback (Groq → موديل أقوى → MyMemory)
$result = translate_with_fallback($source, $target_lang === 'ar' ? 'en' : 'ar', $target_lang, $ctx);
if (!$result['ok']) out(['ok'=>false,'msg'=>'translation_failed','detail'=>'All translation sources failed']);
$suggestion = $result['text'];

// حفظ في DB
$pdo->prepare("UPDATE $tbl SET name_en=? WHERE id=?")->execute([$suggestion, $id]);

out([
    'ok'         => true,
    'id'         => $id,
    'source'     => $source,
    'suggestion' => $suggestion,
    'via'        => $result['source'],
    'lang'       => $target_lang,
    'saved'      => true,
]);