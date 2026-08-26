<?php
/**
 * settings/api/bulk_translate_suggest.php — ترجمة فورية لمجموعة صفوف عبر Groq
 * ──────────────────────────────────────────────────────────────────
 * POST: { tbl: 'item_categories'|'item_locations', limit: 10, offset: 0 }
 *   - يجلب كل الصفوف اللي target field عندها فارغ
 *   - يترجمها دفعة (لحد limit) عبر Groq
 *   - يرجع JSON: { results: [{id, source, target, suggestion}], has_more: bool, total: int }
 *
 * ملاحظة: rate limiting صارم (10 طلبات/دقيقة) لأن كل طلب = N استدعاءات Groq
 * الصلاحية: settings.index (admin)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';

// للإدارات: نسمح بصلاحية departments.index.edit
if (($_POST['tbl'] ?? '') === 'departments') {
    if (!can('departments.index','edit')) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
} else {
    page_guard('settings.index');
}
header('Content-Type: application/json; charset=utf-8');

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$tbl = $_POST['tbl'] ?? '';
if (!in_array($tbl, ['item_categories', 'item_locations', 'departments'])) {
    out(['ok' => false, 'msg' => 'Invalid table']);
}

$limit  = max(1, min(20, (int)($_POST['limit'] ?? 10))); // max 20 per call to avoid rate limit
$offset = max(0, (int)($_POST['offset'] ?? 0));

$key = ai_key();
if (!$key) out(['ok' => false, 'msg' => 'GROQ key missing']);

// rate limit: 10 calls/دقيقة
$_SESSION['bulk_sug_calls'] ??= [];
$now = time();
$_SESSION['bulk_sug_calls'] = array_filter($_SESSION['bulk_sug_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['bulk_sug_calls']) >= 10) {
    out(['ok' => false, 'msg' => 'تجاوزت حد الترجمات الجماعية (10 طلبات/دقيقة). انتظر دقيقة.']);
}
$_SESSION['bulk_sug_calls'][] = $now;

// ── نحدد أعمدة المصدر والهدف حسب الجدول ─────────────────────
if ($tbl === 'departments') {
    $source_col  = 'name';    // عربي → إنجليزي
    $target_col  = 'name_en';
    $ctx         = 'department';
    $target_lang = 'en';
} else {
    $source_col = $tbl === 'item_categories' ? 'name'    : 'name';    // للتصنيفات: العربي (name) → EN
    $target_col = $tbl === 'item_categories' ? 'name_en' : 'name_en'; // للمواقع:    EN (name) → AR (name_en)
    $ctx        = $tbl === 'item_categories' ? 'category' : 'location';
    $target_lang = $tbl === 'item_categories' ? 'en' : 'ar';
}

// ── عدّ الإجمالي بدون ترجمة ──────────────────────────────────
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE $target_col IS NULL OR $target_col = ''");
$total_stmt->execute();
$total = (int)$total_stmt->fetchColumn();

if ($total === 0) {
    out(['ok' => true, 'results' => [], 'has_more' => false, 'total' => 0, 'translated' => 0]);
}

// ── جلب دفعة من الصفوف الفاضية ──────────────────────────────
$rows_stmt = $pdo->prepare("SELECT id, $source_col AS source FROM $tbl WHERE $target_col IS NULL OR $target_col = '' ORDER BY id LIMIT ? OFFSET ?");
$rows_stmt->bindValue(1, $limit, PDO::PARAM_INT);
$rows_stmt->bindValue(2, $offset, PDO::PARAM_INT);
$rows_stmt->execute();
$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── الاستدعاء مع fallback ─────────────────────────────────
$results = [];
$translated = 0;
foreach ($rows as $r) {
    $src = trim($r['source']);
    if ($src === '') {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => null, 'error' => 'empty source'];
        continue;
    }
    $result = translate_with_fallback($src, $target_lang === 'ar' ? 'en' : 'ar', $target_lang, $ctx);
    if ($result['ok']) {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => $result['text'], 'via' => $result['source']];
        $translated++;
    } else {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => null, 'error' => 'all sources failed'];
    }
    usleep(150000); // 150ms بين كل استدعاء
}

out([
    'ok' => true,
    'results' => $results,
    'translated' => $translated,
    'has_more' => ($offset + $limit) < $total,
    'next_offset' => $offset + $limit,
    'total' => $total,
    'remaining' => max(0, $total - ($offset + $limit)),
]);