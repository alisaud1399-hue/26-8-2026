<?php
/**
 * api/complaint_duplicate_check.php — فحص تكرار البلاغ على جهاز معيّن
 * مرحَّلة من check_duplicate_request.php (النظام القديم) بمعايير pmsh_assets:
 * page_guard، $pdo، CSRF غير مطلوب هنا لأنه فحص قراءة فقط لا تغيير حالة.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('complaints.create');
header('Content-Type: application/json; charset=utf-8');

function send(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$asset_id = (int) ($_POST['asset_id'] ?? 0);
$desc = trim($_POST['description'] ?? '');

if (!$asset_id) send(['similar' => false]);

$statuses_ar = [
    'open' => 'مفتوح', 'acknowledged' => 'تم الاستلام', 'in_progress' => 'جاري العمل',
    'stalled' => 'متعثر', 'escalated' => 'مُصعَّد', 'resolved' => 'تم الحل',
];

$open_q = $pdo->prepare("
    SELECT id, request_number, description, status, created_at
    FROM complaints
    WHERE asset_id = ? AND status IN ('open','acknowledged','in_progress','stalled','escalated','resolved')
    ORDER BY created_at DESC LIMIT 5
");
$open_q->execute([$asset_id]);
$open_reqs = $open_q->fetchAll();

if (!$open_reqs) send(['similar' => false]);

// فحص فوري عند اختيار الجهاز فقط (بدون وصف بعد) — تحذير صريح إن وُجد بلاغ مفتوح فعلاً
if (!$desc) {
    $r = $open_reqs[0];
    send([
        'similar' => true,
        'asset_open' => true,
        'request_number' => $r['request_number'],
        'request_id' => (int) $r['id'],
        'status' => $r['status'],
        'status_label' => $statuses_ar[$r['status']] ?? $r['status'],
        'created_at' => $r['created_at'],
        'link' => 'complaints/my.php?id=' . $r['id'],
    ]);
}

// فحص تشابه الوصف عبر الذكاء الاصطناعي (Groq)
$reqs_text = '';
foreach ($open_reqs as $r) {
    $st = $statuses_ar[$r['status']] ?? $r['status'];
    $reqs_text .= "- رقم: {$r['request_number']} | {$r['description']} | $st\n";
}

$api_key = ai_key();

if (!$api_key) {
    // تراجع بلا ذكاء اصطناعي: تشابه كلمات مشتركة (50%+)
    if (get_setting('complaint_ai_dup_check', '1') !== '1') {
        send(['similar' => false, 'disabled' => true]);
    }
    $similar = false; $found = null;
    $d_lower = mb_strtolower($desc);
    foreach ($open_reqs as $r) {
        $ex = mb_strtolower($r['description']);
        $words = array_filter(explode(' ', $d_lower), fn($w) => mb_strlen($w) > 2);
        $match = 0;
        foreach ($words as $w) { if (mb_strpos($ex, $w) !== false) $match++; }
        if (count($words) && ($match / count($words)) >= 0.5) { $similar = true; $found = $r; break; }
    }
    $out = ['similar' => $similar, 'fallback' => true];
    if ($similar && $found) {
        $out['request_number'] = $found['request_number'];
        $out['request_id'] = (int) $found['id'];
        $out['reason'] = 'وصف مشابه لبلاغ مرفوع مسبقاً على هذا الجهاز';
        $out['link'] = 'complaints/my.php?id=' . $found['id'];
    }
    send($out);
}

$prompt = "أنت مساعد نظام صيانة مستشفى.\n"
    . "البلاغ الجديد:\n\"$desc\"\n\n"
    . "البلاغات الموجودة على نفس الجهاز:\n$reqs_text\n"
    . "هل البلاغ الجديد مشابه لأي من البلاغات الموجودة؟\n"
    . "أجب بـ JSON فقط: {\"similar\":true_أو_false,\"request_id\":id_أو_null,\"reason\":\"سبب موجز بالعربي\"}";

$ch = curl_init(ai_base_url() . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => ai_model(),
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.1,
        'max_tokens' => 250,
        'response_format' => ['type' => 'json_object'],
    ]),
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
]);
$res = curl_exec($ch);
$cerr = curl_error($ch);
curl_close($ch);

if ($cerr || !$res) send(['similar' => false]);

$data = json_decode($res, true);
$txt = $data['choices'][0]['message']['content'] ?? '{}';
$result = json_decode(preg_replace('/```json|```/', '', $txt), true);

if (!$result || !isset($result['similar'])) send(['similar' => false]);

if (!empty($result['request_id'])) {
    $rid = (int) $result['request_id'];
    foreach ($open_reqs as $r) {
        if ((int) $r['id'] === $rid) {
            $result['request_number'] = $r['request_number'];
            $result['link'] = 'complaints/my.php?id=' . $rid;
            break;
        }
    }
}
send($result);