<?php
/**
 * includes/maintenance.php — قالب وضع الصيانة
 * يُضمَّن تلقائيًا من دالة page_guard عند تفعيل صيانة الصفحة
 * المتغيرات المتاحة: $GLOBALS['maint_data']
 */
if (!defined('BASE_PATH')) {
    exit('No direct access');
}

$md         = $GLOBALS['maint_data'] ?? [];
$page_name  = $md['page_name']        ?? 'الصفحة';
$message    = $md['message']          ?? 'الصفحة تحت الصيانة';
$est_return = $md['estimated_return'] ?? null;
$updated_at = $md['updated_at']       ?? null;
$privileged = $md['is_privileged']    ?? false;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="120">
<title>صيانة — <?= e($page_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --amber:     #d97706;
  --amber-bg:  #fffbeb;
  --amber-brd: #fde68a;
  --primary:   #1565C0;
  --gray-50:   #f8fafc;
  --gray-100:  #f1f5f9;
  --gray-200:  #e2e8f0;
  --gray-400:  #94a3b8;
  --gray-600:  #475569;
  --gray-800:  #1e293b;
  --gray-900:  #0f172a;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Tajawal', sans-serif;
  background: var(--gray-50);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  direction: rtl;
}
.card {
  background: #fff;
  border-radius: 20px;
  padding: 52px 48px;
  max-width: 540px;
  width: 100%;
  text-align: center;
  box-shadow: 0 20px 60px rgba(0,0,0,.07);
  animation: fadeUp .4s ease both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

.icon-ring {
  width: 92px; height: 92px;
  margin: 0 auto 24px;
  background: var(--amber-bg);
  border: 2px solid var(--amber-brd);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  position: relative;
}
.icon-ring i { font-size: 42px; color: var(--amber); }
.spin-ring {
  position: absolute; inset: -8px;
  border-radius: 50%;
  border: 2px dashed var(--amber-brd);
  animation: rotateSlow 8s linear infinite;
}
@keyframes rotateSlow { to{transform:rotate(360deg)} }

.badge {
  display: inline-block;
  background: var(--amber-bg);
  border: 1px solid var(--amber-brd);
  border-radius: 50px;
  padding: 4px 14px;
  font-size: 12px;
  font-weight: 500;
  color: var(--amber);
  margin-bottom: 12px;
  letter-spacing: .04em;
}
h1 { font-size: 26px; font-weight: 700; color: var(--gray-900); margin-bottom: 10px; }
.page-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  color: var(--gray-600);
  background: var(--gray-100);
  border: 1px solid var(--gray-200);
  border-radius: 8px;
  padding: 6px 14px;
  margin-bottom: 20px;
}
.message {
  font-size: 15px;
  color: var(--gray-600);
  line-height: 1.75;
  margin-bottom: 28px;
}
.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 32px;
  text-align: right;
}
.info-item {
  background: var(--gray-50);
  border: 1px solid var(--gray-200);
  border-radius: 10px;
  padding: 12px 14px;
}
.info-label {
  font-size: 11px;
  color: var(--gray-400);
  margin-bottom: 3px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.info-value {
  font-size: 14px;
  font-weight: 500;
  color: var(--gray-800);
}
.countdown {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: var(--amber-bg);
  border: 1px solid var(--amber-brd);
  border-radius: 10px;
  padding: 12px 20px;
  margin-bottom: 28px;
  font-size: 14px;
  color: var(--amber);
}
.countdown i { font-size: 16px; }
.divider { border: none; border-top: 1px solid var(--gray-100); margin: 0 0 24px; }
.actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  height: 44px; padding: 0 20px; border-radius: 10px;
  font-family: 'Tajawal', sans-serif;
  font-size: 14px; font-weight: 500; cursor: pointer;
  text-decoration: none; border: none; transition: all .15s;
}
.btn-primary { background: linear-gradient(135deg, var(--primary), #003c8f); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(21,101,192,.3); }
.btn-outline { background: transparent; color: var(--gray-600); border: 1.5px solid var(--gray-200); }
.btn-outline:hover { background: var(--gray-100); }
.admin-note {
  margin-top: 24px;
  padding: 12px 16px;
  background: var(--gray-50);
  border: 1px dashed var(--gray-200);
  border-radius: 10px;
  font-size: 12px;
  color: var(--gray-400);
  text-align: right;
}
.admin-note strong { color: var(--primary); }
footer { margin-top: 28px; font-size: 12px; color: var(--gray-400); text-align: center; }
.refresh-note { font-size: 11px; color: var(--gray-300); margin-top: 8px; }
</style>
</head>
<body>
<div class="card">

  <div class="icon-ring">
    <div class="spin-ring"></div>
    <i class="fa-solid fa-screwdriver-wrench"></i>
  </div>

  <span class="badge">تحت الصيانة</span>
  <h1>الصفحة غير متاحة مؤقتًا</h1>

  <div class="page-label">
    <i class="fa-solid fa-file-circle-exclamation" style="color:#94a3b8"></i>
    <?= e($page_name) ?>
  </div>

  <p class="message"><?= e($message) ?></p>

  <?php if ($est_return || $updated_at): ?>
  <div class="info-grid">
    <?php if ($updated_at): ?>
    <div class="info-item">
      <div class="info-label"><i class="fa-regular fa-clock"></i> بدأت الصيانة</div>
      <div class="info-value"><?= date('h:i A — d/m/Y', strtotime($updated_at)) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($est_return): ?>
    <div class="info-item">
      <div class="info-label"><i class="fa-solid fa-flag-checkered"></i> العودة المتوقعة</div>
      <div class="info-value"><?= date('h:i A — d/m/Y', strtotime($est_return)) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="countdown">
    <i class="fa-solid fa-rotate"></i>
    <span>تحديث تلقائي للصفحة كل دقيقتين</span>
    <span id="cntdwn" style="font-weight:700;min-width:40px;text-align:center">2:00</span>
  </div>

  <hr class="divider">

  <div class="actions">
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">
      <i class="fa-solid fa-gauge-high"></i> لوحة التحكم
    </a>
    <a href="javascript:location.reload()" class="btn btn-outline">
      <i class="fa-solid fa-rotate-right"></i> تحديث الآن
    </a>
  </div>

  <?php if ($privileged): ?>
  <div class="admin-note">
    <i class="fa-solid fa-circle-info" style="margin-left:5px"></i>
    أنت ترى هذه الرسالة لأنك مدير. بإمكانك <strong><a href="<?= BASE_URL ?>/settings/index.php" style="color:inherit;text-decoration:underline">إيقاف الصيانة من الإعدادات</a></strong>.
  </div>
  <?php endif; ?>

</div>

<footer>
  <?= e(get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود')) ?>
  &nbsp;·&nbsp; <?= e(APP_NAME) ?>
  <p class="refresh-note">الصفحة ستُحدَّث تلقائيًا خلال <span id="refNote">120</span> ثانية</p>
</footer>

<script>
(function(){
  let secs = 120;
  const cntEl  = document.getElementById('cntdwn');
  const refEl  = document.getElementById('refNote');
  setInterval(() => {
    secs--;
    if (secs <= 0) { location.reload(); return; }
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    if (cntEl) cntEl.textContent = m + ':' + String(s).padStart(2,'0');
    if (refEl) refEl.textContent = secs;
  }, 1000);
})();
</script>
</body>
</html>
