<?php
/**
 * api/ai_chat.php — وكيل Groq للمساعد الذكي
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('{"error":"Method not allowed"}');
}

$body     = json_decode(file_get_contents('php://input'), true);
$messages = array_slice($body['messages'] ?? [], -12); // آخر 12 رسالة فقط

if (empty($messages)) {
    http_response_code(400); die('{"error":"No messages"}');
}

// ── نظام المعرفة ───────────────────────────────────────────
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$system_prompt = <<<PROMPT
أنت مساعد ذكي متخصص في نظام إدارة الأصول والصيانة في {$hospital} — تجمع الباحة الصحي.

## مهمتك:
- مساعدة الموظفين في استخدام النظام بفعالية
- الإجابة على أسئلة الإجراءات وخطوات العمل
- شرح سير العمل خطوة بخطوة

## معلومات النظام:
**الأصول:** النظام يدير 2800+ أصل مقسّمة لـ:
- أجهزة طبية (Seg 18, 19) → تتبعها الصيانة الطبية
- تقنية معلومات (Seg 13) → تتبعها إدارة تقنية المعلومات
- صيانة عامة (بنية تحتية، تكييف، أثاث) → الصيانة العامة
- فئات الحساسية: A (عالية)، B (متوسطة)، C (عادية)

**دورة دخول الأصول:**
1. تشكيل لجنة استلام (مدير الإمداد + فني + مستلمون)
2. محضر الاستلام → توقيع تسلسلي من أعضاء اللجنة
3. بيان التوزيع → توزيع الوحدات على الأقسام
4. شهادة التوريد والتركيب والتشغيل → الجهاز يصبح ACTIVE
5. ربط العهدة بالموظفين المستلمين

**نظام البلاغات:**
1. المبلّغ يختار: القسم → نوع الجهاز → الجهاز المحدد
2. لا يُسمح ببلاغين على جهاز واحد في نفس الوقت
3. التوجيه التلقائي:
   - جهاز طبي → مدير الصيانة الطبية
   - جهاز IT → إدارة تقنية المعلومات
   - صيانة عامة → إدارة الصيانة العامة
4. مدير الصيانة يختار: تم الحل / إنشاء أمر عمل / تعذّر الحل
5. أمر العمل → شركة الصيانة → إعادة للمدير → تأكيد المبلّغ

**الصلاحيات:** كل مستخدم يرى فقط ما يخص دوره.

## أسلوب الإجابة:
- أجب باللغة العربية دائماً
- كن مختصراً ومباشراً (لا تكتب أكثر من 150 كلمة)
- استخدم قوائم مرقّمة للخطوات
- إذا لم تعرف شيئاً قل "هذا خارج نطاق معرفتي، يُرجى التواصل مع المسؤول"
PROMPT;

// ── استدعاء Groq (أو أي مزود آخر عبر ai_base_url) ─────────────
$payload = [
    'model'       => ai_model(),
    'max_tokens'  => 600,
    'temperature' => 0.4,
    'messages'    => array_merge(
        [['role'=>'system','content'=>$system_prompt]],
        $messages
    ),
];

$ch = curl_init(ai_base_url() . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ai_key(),
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Groq API error', 'code' => $httpCode]);
    exit;
}

$data    = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';

echo json_encode([
    'reply'  => $content,
    'tokens' => $data['usage']['total_tokens'] ?? 0,
], JSON_UNESCAPED_UNICODE);