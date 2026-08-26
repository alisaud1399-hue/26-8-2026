<?php
/**
 * inventory/api/translate_batch.php — ترجمة batch سياقية عبر Groq AI
 * POST: { offset: int, limit: int (max 50) }
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/_utils.php';

if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'admin_only']); exit; }

$data = json_decode(file_get_contents('php://input'), true);
$offset = max(0, (int)($data['offset'] ?? 0));
$limit  = min(50, max(1, (int)($data['limit'] ?? 50)));

global $pdo;

$stmt = $pdo->prepare("
    SELECT id, tag_number, description
    FROM assets
    WHERE id <= 2858
      AND description IS NOT NULL
      AND description != ''
      AND (description_ar IS NULL OR description_ar = '')
    ORDER BY id ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$limit, $offset]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_remaining = $pdo->query("SELECT COUNT(*) FROM assets WHERE id <= 2858 AND description IS NOT NULL AND description != '' AND (description_ar IS NULL OR description_ar = '')")->fetchColumn();
$total_all = $pdo->query("SELECT COUNT(*) FROM assets WHERE id <= 2858 AND description IS NOT NULL AND description != ''")->fetchColumn();
$total_translated = $pdo->query("SELECT COUNT(*) FROM assets WHERE id <= 2858 AND description_ar IS NOT NULL AND description_ar != ''")->fetchColumn();

if (empty($rows)) {
    echo json_encode(['ok'=>true,'translated'=>[],'remaining'=>0,'total'=>$total_all,'translated_count'=>$total_translated]);
    exit;
}

$dict = [
    'ultrasound' => 'جهاز موجات فوق صوتية', 'x-ray' => 'جهاز أشعة سينية', 'xray' => 'جهاز أشعة سينية',
    'ecg' => 'جهاز تخطيط القلب', 'ekg' => 'جهاز تخطيط القلب', 'ventilator' => 'جهاز تنفس صناعي',
    'defibrillator' => 'جهاز صدمات القلب', 'infusion pump' => 'مضخة تسريب',
    'syringe pump' => 'مضخة حقن', 'incubator' => 'حاضنة أطفال', 'autoclave' => 'جهاز تعقيم',
    'centrifuge' => 'جهاز طرد مركزي', 'anesthesia' => 'جهاز تخدير',
    'ct scan' => 'جهاز أشعة مقطعية', 'mri' => 'جهاز رنين مغناطيسي',
    'diathermy' => 'جهاز كهرباء علاجية', 'esu' => 'وحدة جراحية كهربائية',
    'endoscope' => 'منظار', 'analyzer' => 'جهاز تحليل',
    'physiotherapy' => 'العلاج الطبيعي', 'operating room' => 'غرفة العمليات',
    'intensive care' => 'العناية المركزة', 'neonatal' => 'حديثي الولادة',
    'pediatric' => 'الأطفال', 'ophthalmic' => 'طب العيون',
    'portable' => 'متنقل', 'mobile' => 'متنقل',
    'contrast media' => 'وسيلة تباين', 'computed tomography' => 'التصوير المقطعي المحوسب',
    'exerciser' => 'جهاز تمارين', 'shortwave' => 'موجة قصيرة',
    'blood pressure' => 'ضغط الدم', 'patient monitor' => 'جهاز مراقبة المريض',
];

function dictLookup(string $text): ?string {
    global $dict;
    $lower = mb_strtolower(trim($text));
    if (isset($dict[$lower])) return $dict[$lower];
    foreach ($dict as $key => $val) {
        if (str_starts_with($lower, $key . ' ')) {
            return $val . ' ' . trim(mb_substr($text, mb_strlen($key)));
        }
    }
    return null;
}

function groqTranslateBatch(array $items): array {
    $apiKey = ai_key();
    $groqUrl = ai_base_url() . '/chat/completions';
    $numbered = [];
    foreach ($items as $i => $item) {
        $numbered[] = ($i + 1) . ". " . $item;
    }
    $list = implode("\n", $numbered);
    $systemPrompt = "أنت مهندس أجهزة طبية خبير في وزارة الصحة السعودية.
مهمتك: ترجمة أسماء الأجهزة الطبية من الإنجليزية إلى العربية.
قواعد صارمة:
1. ابدأ كل ترجمة بكلمة 'جهاز' أو 'وحدة' أو 'نظام' حسب التناسب.
2. أعطني الترجمة العربية فقط، بالترتيب، سطر واحد لكل جهاز، без أرقام أو علامات تنصيص.
3. إذا كان الاسم يحتوي اختصاراً طبياً مثل OCT أو ESU أو POC، احتفظ بالاختصار كما هو داخل قوسين.
4. ترجمة طبية دقيقة وليست حرفية.";
    $payload = json_encode([
        "model" => ai_model(),
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "ترجم هذه الأجهزة:\n$list"]
        ],
        "temperature" => 0.1,
        "max_tokens" => 800,
        "reasoning_effort" => "low"
    ]);
    $ch = curl_init($groqUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return [];
    $j = json_decode($res, true);
    $content = $j['choices'][0]['message']['content'] ?? '';
    if (empty($content) && !empty($j['choices'][0]['message']['reasoning'])) {
        $content = $j['choices'][0]['message']['reasoning'];
    }
    $content = preg_replace('/<think>.*?<\/think>/si', '', $content);
    $lines = array_filter(array_map('trim', explode("\n", $content)));
    $result = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
        $line = trim(str_replace(['"', "'", "ترجمة:", "الترجمة:"], '', $line));
        $result[] = $line;
    }
    while (count($result) < count($items)) $result[] = '';
    return array_slice($result, 0, count($items));
}

$descriptions = array_column($rows, 'description');
$dictResults = [];
$needAi = [];
$needAiIdx = [];

foreach ($descriptions as $i => $desc) {
    $d = dictLookup($desc);
    if ($d !== null) {
        $dictResults[$i] = $d;
    } else {
        $needAi[] = $desc;
        $needAiIdx[] = $i;
        $dictResults[$i] = null;
    }
}

$aiResults = [];
if (!empty($needAi)) {
    $aiResults = groqTranslateBatch($needAi);
}

$results = [];
foreach ($rows as $i => $row) {
    if ($dictResults[$i] !== null) {
        $ar = $dictResults[$i];
        $src = 'dictionary';
    } else {
        $aiIdx = array_search($i, $needAiIdx);
        $ar = $aiIdx !== false ? ($aiResults[$aiIdx] ?? '') : '';
        if ($ar !== '' && $ar !== $row['description']) {
            $src = 'groq';
        } else {
            $ar = '';
            $src = 'needs_retry';
        }
    }
    $results[] = [
        'id'         => (int)$row['id'],
        'tag_number' => $row['tag_number'],
        'en'         => $row['description'],
        'ar'         => $ar,
        'source'     => $src,
    ];
}

echo json_encode([
    'ok'              => true,
    'translated'      => $results,
    'remaining'       => max(0, $total_remaining - count(array_filter($results, fn($r) => $r['ar'] !== ''))),
    'total'           => (int)$total_all,
    'translated_count'=> (int)$total_translated,
], JSON_UNESCAPED_UNICODE);
