<?php
/**
 * inventory/test_api.php — أداة اختبار APIs الجرد
 * ⚠️ احذف هذا الملف بعد الانتهاء من الاختبار!
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index'); // يجب أن تكون مسجل دخول

$rtl = is_rtl();
$user = current_user();

// ── معالجة الطلبات ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test = $_POST['test'] ?? '';
    $session_id = (int)($_POST['session_id'] ?? 0);
    
    $endpoints = [
        'lookup'          => BASE_URL . '/inventory/api/lookup.php?session=' . $session_id . '&tag=TEST',
        'submit'          => BASE_URL . '/inventory/api/submit.php',
        'quick_register'  => BASE_URL . '/inventory/api/quick_register.php',
        'reaudit_request' => BASE_URL . '/inventory/api/reaudit_request.php',
    ];
    
    $payloads = [
        'lookup'          => null, // GET
        'submit'          => json_encode([
            'session_id' => $session_id,
            'asset_id'   => 1,
            'action'     => 'confirmed',
            'scanned_tag'=> 'TEST-001',
        ]),
        'quick_register'  => json_encode([
            'session_id'     => $session_id,
            'tag_number'     => 'BHC-TEST-' . time(),
            'serial_number'  => 'SN-TEST-' . time(),
            'description_ar' => 'جهاز اختبار',
            'description_en' => 'Test Device',
            'asset_type'     => 'medical',
            'cat_level1'     => 'أجهزة طبية',
            'cat_level2'     => 'أجهزة تشخيص',
            'cat_level3'     => 'أجهزة أشعة',
            'location_id'    => 1,
        ]),
        'reaudit_request' => json_encode([
            'session_id' => $session_id,
            'asset_id'   => 1,
            'reason'     => 'اختبار',
        ]),
    ];
    
    if (isset($endpoints[$test])) {
        $ch = curl_init($endpoints[$test]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        if ($payloads[$test] !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloads[$test]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ]);
        }
        
        // تمرير كوكي الجلسة لنحافظ على تسجيل الدخول
        if (isset($_SERVER['HTTP_COOKIE'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($ch);
        
        $_SESSION['_test_result'] = [
            'test'     => $test,
            'http'     => $httpCode,
            'response' => $body,
        ];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$result = $_SESSION['_test_result'] ?? null;
unset($_SESSION['_test_result']);

// ── جلب الجلسات النشطة ──
$sessions = $pdo->query("SELECT id, session_code, title FROM inventory_sessions WHERE status IN ('active','review') ORDER BY id DESC LIMIT 10")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>اختبار APIs الجرد</title>
<style>
body { font-family: 'Tajawal', sans-serif; background: #f1f5f9; padding: 20px; }
.wrap { max-width: 900px; margin: 0 auto; }
h1 { color: #0f172a; }
.warn { background: #fef2f2; border: 2px solid #fca5a5; border-radius: 12px; padding: 14px; color: #991b1b; font-weight: 800; margin-bottom: 20px; }
.card { background: #fff; border-radius: 14px; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e8f0; }
.card h3 { margin: 0 0 12px; color: #1e293b; }
label { display: block; font-weight: 800; margin-bottom: 6px; color: #475569; font-size: 13px; }
select, button { font-family: 'Tajawal'; font-size: 14px; padding: 10px 14px; border-radius: 10px; border: 1.5px solid #cbd5e1; }
select { width: 100%; margin-bottom: 12px; }
button { background: #2563eb; color: #fff; border: none; font-weight: 800; cursor: pointer; margin: 4px; }
button:hover { background: #1d4ed8; }
button.danger { background: #dc2626; }
.result { background: #0f172a; color: #38bdf8; border-radius: 10px; padding: 16px; font-family: monospace; font-size: 12.5px; white-space: pre-wrap; max-height: 400px; overflow-y: auto; margin-top: 12px; }
.http-ok { color: #4ade80; }
.http-err { color: #f87171; }
.user-info { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; font-size: 13px; color: #1e40af; margin-bottom: 16px; }
</style>
</head>
<body>
<div class="wrap">
<h1>🧪 أداة اختبار APIs الجرد</h1>
<div class="warn">⚠️ احذف هذا الملف بعد الانتهاء من الاختبارات — لا تتركه في بيئة الإنتاج!</div>

<div class="user-info">
    <b>المستخدم الحالي:</b> <?= e($user['full_name'] ?? '') ?> 
    (ID: <?= e($user['id'] ?? 0) ?>) — 
    <?= is_admin() ? '🔑 أدمن' : 'مستخدم عادي' ?>
</div>

<?php if ($result): ?>
<div class="card">
    <h3>نتيجة اختبار: <code><?= e($result['test']) ?></code></h3>
    <div class="result"><span class="<?= $result['http'] < 400 ? 'http-ok' : 'http-err' ?>">HTTP <?= $result['http'] ?></span>

<?= e($result['response']) ?></div>
</div>
<?php endif; ?>

<div class="card">
    <h3>1️⃣ اختيار جلسة للاختبار</h3>
    <form method="POST">
        <label>الجلسة:</label>
        <select name="session_id" id="sessionSelect">
            <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['id'] ?>"><?= e($s['session_code']) ?> — <?= e($s['title']) ?></option>
            <?php endforeach; ?>
        </select>
        
        <h3 style="margin-top:16px">2️⃣ اختر API لاختباره</h3>
        <button type="submit" name="test" value="lookup">🔍 lookup.php (قراءة)</button>
        <button type="submit" name="test" value="submit">💾 submit.php (كتابة)</button>
        <button type="submit" name="test" value="quick_register">➕ quick_register.php (إنشاء)</button>
        <button type="submit" name="test" value="reaudit_request">🔄 reaudit_request.php (طلب)</button>
    </form>
</div>

<div class="card">
    <h3>📋 ما يجب أن نراه (بعد تطبيق الحراس)</h3>
    <table style="width:100%; font-size:13px;">
        <tr><th>الحساب</th><th>النتيجة المتوقعة</th></tr>
        <tr><td>عضو لجنة</td><td style="color:#16a34a">✅ HTTP 200 + ok:true</td></tr>
        <tr><td>أدمن / مدير أصول</td><td style="color:#16a34a">✅ HTTP 200 + ok:true</td></tr>
        <tr><td><b>ليس عضواً</b></td><td style="color:#dc2626">⛔ HTTP 403 + "لست عضواً"</td></tr>
    </table>
</div>

<p style="text-align:center; color:#94a3b8; font-size:12px; margin-top:20px;">
    <a href="<?= BASE_URL ?>/inventory/index.php">← العودة لقائمة الجرد</a>
</p>
</div>
</body>
</html>