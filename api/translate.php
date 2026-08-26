<?php
/**
 * api/translate.php — مترجم طبي ذكي (مُصحَّح: موديل فعّال حالياً + تشخيص أخطاء)
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';

$data = json_decode(file_get_contents('php://input'), true);
$textToTranslate = trim($data['text'] ?? '');

if (empty($textToTranslate)) {
    echo json_encode(['translation' => '']);
    exit;
}

// ==========================================
// ① قاموس ثابت للمصطلحات الشائعة — يُفحص أولاً، مطابقة دقيقة 100%
// لا اعتماد على مزاج النموذج لأشهر الأجهزة وأكثرها تكراراً
// أضف أي مصطلح جديد يتكرر عندك هنا تدريجياً
// ==========================================
$fixedDictionary = [
    'ultrasound'      => 'جهاز موجات فوق صوتية',
    'x-ray'           => 'جهاز أشعة سينية',
    'xray'            => 'جهاز أشعة سينية',
    'ecg'             => 'جهاز تخطيط القلب',
    'ekg'             => 'جهاز تخطيط القلب',
    'ventilator'      => 'جهاز تنفس صناعي',
    'defibrillator'   => 'جهاز صدمات القلب',
    'monitor'         => 'جهاز مراقبة',
    'patient monitor' => 'جهاز مراقبة العلامات الحيوية للمريض',
    'infusion pump'   => 'مضخة تسريب',
    'syringe pump'    => 'مضخة حقن',
    'incubator'       => 'حاضنة أطفال',
    'autoclave'       => 'جهاز تعقيم',
    'centrifuge'      => 'جهاز طرد مركزي',
    'anesthesia'      => 'جهاز تخدير',
    'ct scan'         => 'جهاز أشعة مقطعية',
    'mri'             => 'جهاز رنين مغناطيسي',
];
$dictKey = mb_strtolower(trim($textToTranslate));
if (isset($fixedDictionary[$dictKey])) {
    echo json_encode(['translation' => $fixedDictionary[$dictKey], 'source' => 'fixed_dictionary']);
    exit;
}
// مطابقة جزئية: لو الكلمة الأولى من النص تطابق مفتاحاً (مثل "Ultrasound General Advance")
foreach ($fixedDictionary as $key => $val) {
    if (str_starts_with($dictKey, $key . ' ')) {
        echo json_encode(['translation' => $val . ' ' . trim(mb_substr($textToTranslate, mb_strlen($key))), 'source' => 'fixed_dictionary_partial']);
        exit;
    }
}

// مفتاح AI يُقرأ من الإعدادات الموحدة (DB أولاً، ثم config.php)
// يشفّر في DB تلقائياً عبر صفحة الإعدادات
$apiKey = ai_key();

// ==========================================
// المحاولة الأولى: عبر محرك Groq
// ⚠️ النموذج llama3-70b-8192 مسحوب رسمياً من Groq منذ مايو 2025 (decommissioned)
// تم التحديث لـ openai/gpt-oss-120b — الموديل الموصى به رسمياً حالياً (يونيو 2026)
// راجع: https://console.groq.com/docs/deprecations قبل أي تعديل مستقبلي على اسم الموديل
// ==========================================
$groqUrl = ai_base_url() . '/chat/completions';

$systemPrompt = "أنت مهندس أجهزة طبية خبير في وزارة الصحة السعودية.
مهمتك الوحيدة: ترجمة أسماء الأجهزة الطبية التجارية من الإنجليزية إلى مصطلحات طبية عربية صحيحة ومعتمدة في المستشفيات.
قواعد صارمة جداً:
1. إياك والترجمة الحرفية (مثال: Advance تعني متقدم، وليست تقدم).
2. قم دائماً بوضع كلمة 'جهاز' في بداية الترجمة.
3. كلمة Ultrasound تترجم دائماً إلى 'أشعة صوتية' أو 'موجات فوق صوتية'.
4. أعطني الترجمة العربية فقط كإجابة نهائية، بدون أي مقدمات، بدون علامات تنصيص، وبدون النص الإنجليزي.

أمثلة يجب أن تتعلم منها:
- Ultrasound General Advance -> جهاز أشعة صوتية عام متقدم
- Portable X-Ray -> جهاز أشعة سينية متنقل
- Patient Vital Signs Monitor -> جهاز مراقبة العلامات الحيوية للمريض
- Advanced ECG Machine -> جهاز تخطيط قلب متقدم";

$payload = json_encode([
    "model" => ai_model(),
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => "ترجم هذا الجهاز: " . $textToTranslate]
    ],
    "temperature" => 0.1,
    "max_tokens" => 300,
    "reasoning_effort" => "low"
]);

$ch = curl_init($groqUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 🔎 سجل تشخيصي صامت — يكتب فقط عند الفشل، لمعرفة السبب الحقيقي دون كسر الواجهة
if ($httpCode !== 200) {
    error_log('[translate.php] Groq failed — HTTP:'.$httpCode.' cURL:'.$curlErr.' Body:'.substr((string)$response,0,300));
}

if ($httpCode === 200) {
    $responseData = json_decode($response, true);
    $translatedText = $responseData['choices'][0]['message']['content'] ?? '';
    // حماية: إزالة أي كتلة تفكير داخلي
    $translatedText = preg_replace('/<think>.*?<\/think>/si', '', $translatedText);
    $translatedText = trim(str_replace(['"', "'", "\n", "\r", "*", "ترجمة:", "الترجمة:"], '', $translatedText));

    // إذا كان content فارغاً (نماذج التفكير)، نستخدم reasoning field
    if (empty($translatedText) && !empty($responseData['choices'][0]['message']['reasoning'])) {
        $translatedText = trim($responseData['choices'][0]['message']['reasoning']);
        $translatedText = preg_replace('/<think>.*?<\/think>/si', '', $translatedText);
        $translatedText = trim(str_replace(['"', "'", "\n", "\r", "*", "ترجمة:", "الترجمة:"], '', $translatedText));
    }

    if (!empty($translatedText)) {
        echo json_encode(['translation' => $translatedText, 'source' => 'groq']);
        exit;
    }
}

echo json_encode(['translation' => $textToTranslate, 'source' => 'none']);