<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('complaints.create');
header('Content-Type: application/json; charset=utf-8');

function send(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$asset_id = (int) ($_POST['asset_id'] ?? 0);
if (get_setting('complaint_ai_suggestions', '1') !== '1') {
    send(['ai' => [], 'manuf' => '', 'model' => '', 'debug' => 'disabled']);
}
if ($asset_id < 1) send(['ai' => [], 'manuf' => '', 'model' => '', 'debug' => 'No asset ID']);

$stmt = $pdo->prepare("SELECT description, en_name, manufacturer_name, model_number FROM assets WHERE id=? LIMIT 1");
$stmt->execute([$asset_id]);
$info = $stmt->fetch();
if (!$info) send(['ai' => [], 'manuf' => '', 'model' => '', 'debug' => 'Asset not found']);

$desc   = trim($info['description'] ?? '');
$en_name = trim($info['en_name'] ?? '');
$manuf  = trim($info['manufacturer_name'] ?? '');
$model  = trim($info['model_number'] ?? '');

$cacheKey = md5($asset_id . '|' . $en_name . '|' . $manuf . '|' . $model);
$cacheTTL = -1;

$pdo->exec("CREATE TABLE IF NOT EXISTS complaint_ai_cache (
    cache_key VARCHAR(32) PRIMARY KEY,
    cache_data LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cacheStmt = $pdo->prepare("SELECT cache_data, created_at FROM complaint_ai_cache WHERE cache_key=?");
$cacheStmt->execute([$cacheKey]);
$cachedRow = $cacheStmt->fetch();

if ($cachedRow && (time() - strtotime($cachedRow['created_at'])) < $cacheTTL) {
    $cachedData = json_decode($cachedRow['cache_data'], true);
    if ($cachedData) {
        $cachedData['manuf'] = $manuf;
        $cachedData['model'] = $model;
        send($cachedData);
    }
}

$suggestions = [];

$key = ai_key();
if ($key) {
    // تنظيف الوصف وقصه إلى 150 حرف كحد أقصى عشان ما يعلق السيرفر
    $d = $en_name ?: $desc;
    $d = mb_substr(strip_tags($d), 0, 150); 
    if ($manuf) $d .= ' - ' . $manuf;
    if ($model) $d .= ' (' . $model . ')';

    // أمر قوي وموجه للذكاء الاصطناعي
    // إجبار الذكاء الاصطناعي ليكون دقيق جداً (Promt هندسي متقدم)
    $prompt = "You are a highly experienced Senior Biomedical and IT Engineer. The user is reporting a maintenance issue for the following device:\nDevice: $d\n\nTASK: Provide exactly 8 highly specific, technical, and common faults for THIS EXACT DEVICE (e.g., sensor calibration failure, mainboard overheating, mechanical wear, pneumatic leak). DO NOT provide generic answers like 'device broken' or 'not turning on'.\n\nOutput ONLY a valid JSON object without any markdown tags:\n{\"faults\":[{\"ar\":\"وصف العطل بدقة عالية (أقل من 6 كلمات)\",\"en\":\"2-3 english keywords\"}]}";

    $groqModel = ai_model();

    $ch = curl_init(ai_base_url() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $groqModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a technical maintenance expert. You output ONLY raw JSON without markdown blocks.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.5,
            'max_tokens' => 1500,
        ]),
        // هنا السر: رفعنا مهلة الانتظار من 15 إلى 45 ثانية
        CURLOPT_TIMEOUT => 45, 
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    ]);

    $http_code = 0;
    $groqResult = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $groqResult) {
        $data = json_decode($groqResult, true);
        $msg = $data['choices'][0]['message'] ?? [];
        $txt = $msg['content'] ?? '';
        if (is_array($txt)) $txt = '';
        if (empty(trim($txt)) && !empty($msg['reasoning'])) {
            $reasoning = $msg['reasoning'];
            $faultsPos = strpos($reasoning, '"faults"');
            if ($faultsPos !== false) {
                $before = substr($reasoning, 0, $faultsPos);
                $bracePos = strrpos($before, '{');
                if ($bracePos !== false) {
                    $candidate = substr($reasoning, $bracePos);
                    $depth = 0; $end = 0;
                    for ($i = 0, $len = strlen($candidate); $i < $len; $i++) {
                        if ($candidate[$i] === '{') $depth++;
                        elseif ($candidate[$i] === '}') { $depth--; if ($depth === 0) { $end = $i + 1; break; } }
                    }
                    if ($end > 0) $txt = substr($candidate, 0, $end);
                }
            }
        }
        if (!empty(trim($txt))) {
            $cleaned = preg_replace('/```json|```/', '', $txt);
            $r = json_decode($cleaned, true);
            $suggestions = $r['faults'] ?? [];
        }
    }
}

if (count($suggestions) < 3) {
    $cq = $pdo->prepare("SELECT fault_text AS ar, fault_text_en AS en FROM asset_fault_suggestions WHERE asset_id=? ORDER BY usage_count DESC, created_at DESC LIMIT 8");
    $cq->execute([$asset_id]);
    $hist = $cq->fetchAll();
    if (count($hist) >= 1) {
        $suggestions = array_merge($suggestions, $hist);
        $seen = [];
        $unique = [];
        foreach ($suggestions as $s) {
            $k = $s['ar'] ?? ($s[0] ?? '');
            if ($k && !in_array($k, $seen, true)) {
                $seen[] = $k;
                $unique[] = $s;
            }
        }
        $suggestions = array_values(array_slice($unique, 0, 8));
    }
}

$response = [
    'ai' => array_values($suggestions),
    'manuf' => $manuf,
    'model' => $model,
];

if (!empty($response['ai'])) {
    $valid = array_filter($response['ai'], function ($f) {
        $ar = $f['ar'] ?? '';
        return mb_strlen($ar) > 3 && $ar !== '...';
    });
    if (count($valid) >= 1) {
        $response['ai'] = array_values($valid);
        $ins = $pdo->prepare("INSERT INTO complaint_ai_cache (cache_key, cache_data, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE cache_data=VALUES(cache_data), created_at=NOW()");
        $ins->execute([$cacheKey, json_encode(['ai' => $response['ai']], JSON_UNESCAPED_UNICODE)]);
    }
}
//$response['ai'][] = ['ar' => "🚨 كود الاتصال: " . $http_code, 'en' => 'Debug'];
//$response['ai'][] = ['ar' => "🚨 رد قروك: " . mb_substr(strip_tags($groqResult), 0, 150), 'en' => 'Debug'];
send($response);
