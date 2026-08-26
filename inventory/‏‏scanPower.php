<?php
/**
 * inventory/scan.php — المسح الميداني للجرد (نسخة ثنائية اللغة - Enterprise)
 */
require_once dirname(__DIR__) . '/config.php';
if (!can('inventory.scan', 'view') && !can('inventory.create', 'manage')) abort(403);

// 🌟 ضبط اللغة ديناميكياً 🌟
$ui_lang = $_GET['lang'] ?? $_SESSION['lang'] ?? (is_rtl() ? 'ar' : 'en');
$is_ar = ($ui_lang === 'ar');
$rtl = $is_ar;

$session_id = (int)($_GET['session'] ?? $_POST['session'] ?? 0);
$is_admin = can('inventory.create', 'manage'); 

$sessions = [];
if (!$session_id) $sessions = $pdo->query("SELECT id, session_code, title, status FROM inventory_sessions WHERE status IN ('active','review') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$session = null; $total_scope = 0; $done_scope = 0;
if ($session_id) {
    $st = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
    $st->execute([$session_id]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session || !in_array($session['status'], ['active','review'], true)) {
        flash('error', $is_ar ? 'الجلسة غير نشطة.' : 'Session is inactive.'); 
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $session_id); exit;
    }
    $total_scope = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status NOT IN ('disposed','returned_to_supplier') AND location_id IS NOT NULL")->fetchColumn();
    $dq = $pdo->prepare("SELECT COUNT(DISTINCT asset_id) FROM inventory_audits WHERE session_id = ? AND asset_id IS NOT NULL");
    $dq->execute([$session_id]);
    $done_scope = (int)$dq->fetchColumn();
    
    // 🌟 جلب الاسم الإنجليزي من جدول الأقسام 🌟
    $filter_depts = $pdo->query("SELECT id, name, name_en FROM departments WHERE level = 1 AND is_active = 1 AND dept_category = 'clinical' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="<?= $is_ar ? 'ar' : 'en' ?>" dir="<?= $is_ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= $is_ar ? 'المسح الميداني' : 'Field Scan' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
<style>
:root{ --navy:#0f2545; --navy2:#1a3a6b; --blue:#1565C0; --teal-br:#00D4E8; --ink:#0f172a; --text2:#475569; --muted:#94a3b8; --line:#e2e8f0; --bg:#f8fafc; --green:#16a34a; --amber:#f59e0b; --orange:#f97316; --red:#dc2626; --green-l:#4ade80;}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body, button, input, select, textarea {margin:0; font-family:'Tajawal',sans-serif; background:var(--bg); color:var(--ink);}
body {max-width:520px; margin-inline:auto; min-height:100vh; padding-bottom:104px;}

.topbar{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff; padding:12px 16px;display:flex;align-items:center;gap:12px; position:sticky;top:0;z-index:20; box-shadow:0 2px 10px rgba(0,0,0,0.15);}
.ring{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;}
.ring b{width:36px;height:36px;border-radius:50%;background:var(--navy);display:grid;place-items:center;font-size:10.5px;color:var(--teal-br);font-weight:800;}
.topbar .t1{font-weight:800;font-size:13.5px;} .topbar .t2{font-size:11.5px;color:#7dd3fc;}
.backbtn{margin-inline-start:auto;background:rgba(255,255,255,.12);border:none;color:#fff; border-radius:10px;padding:9px 14px;font-weight:700;font-size:12.5px;cursor:pointer; transition:0.2s;}
.backbtn:active {background:rgba(255,255,255,.25);}

/* 🌟 زر تبديل اللغة في الشريط العلوي */
.lang-toggle { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; padding: 4px 10px; font-size: 11px; font-weight: 800; cursor: pointer; text-decoration: none; margin-inline-start: 8px; }
.lang-toggle:hover { background: rgba(255,255,255,0.25); }

.wrap{padding:12px 14px;}
.card{background:#fff;border:1px solid var(--line);border-radius:16px; padding:16px;margin-bottom:12px; box-shadow:0 2px 6px rgba(15,23,42,0.03);}
h3.sec{font-size:14px;margin:6px 2px 10px;color:var(--navy); font-weight:800;}
.hint{font-size:11.5px;color:var(--muted);font-weight:500;}
.screen{display:none;} .screen.on{display:block;animation:fadeIn .3s cubic-bezier(0.16, 1, 0.3, 1);}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:none;}}
@keyframes pulseWiz { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }

.btn{border-radius:12px;padding:12px;font-weight:800;font-size:13px;cursor:pointer;border:none; transition:0.2s; display:inline-flex; align-items:center; justify-content:center; gap:6px;}
.btn:active{transform:scale(0.97);}
.btn-g{background:linear-gradient(135deg, var(--green), #15803d); color:#fff; box-shadow:0 2px 8px rgba(22,163,74,0.25);} 
.btn-o{background:#fff;border:1.5px solid var(--line);color:var(--navy);}
.loading{text-align:center;color:var(--muted);padding:36px 0;}
.loading i{font-size:24px;display:block;margin-bottom:8px;animation:spin 1s linear infinite; color:var(--blue);}

.searchrow{display:flex;gap:8px;margin-bottom:12px;}
.searchrow input{flex:1;border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;font-size:13.5px; transition:0.2s;}
.searchrow input:focus{border-color:var(--blue); box-shadow:0 0 0 3px rgba(21,101,192,0.1); outline:none;}
.cambtn{background:var(--navy);color:#fff;border:none;border-radius:14px;width:50px;font-size:18px;cursor:pointer; box-shadow:0 2px 6px rgba(15,23,42,0.15);}

.alert{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:14px;padding:12px 14px;font-size:12.5px;color:#991b1b;margin-bottom:12px;display:none;}
.alert.show{display:block; animation:fadeIn 0.3s;}

.modal{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:flex-end;justify-content:center;z-index:999; backdrop-filter:blur(3px);}
.modal.show{display:flex; animation:fadeIn 0.2s;}
.sheet{background:#fff;border-radius:24px 24px 0 0;padding:24px 18px 32px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto; box-shadow:0 -5px 15px rgba(0,0,0,0.1); position:relative;}
.form-lbl { display:block; font-size:12.5px; font-weight:800; color:var(--navy); margin-bottom:6px; }

.input-wrap { position: relative; width: 100%; margin-bottom: 14px; }
.input-wrap .finp { margin-bottom: 0; }
.finp { width:100%; border:1.5px solid var(--line); border-radius:12px; padding:12px; font-size:13px; margin-bottom:14px; background:#fcfcfc; transition:0.2s;}
.finp:focus { border-color:var(--blue); background:#fff; outline:none; box-shadow:0 0 0 3px rgba(21,101,192,0.08);}
.mic-btn { position:absolute; top:6px; background:none; border:none; color:var(--blue); font-size:18px; cursor:pointer; padding:6px; transition:0.2s; z-index: 5;}
html[dir="rtl"] .mic-btn.left { left: 6px; right: auto; }
html[dir="rtl"] .mic-btn.right { right: 6px; left: auto; }
html[dir="ltr"] .mic-btn.left { right: 6px; left: auto; }
html[dir="ltr"] .mic-btn.right { left: 6px; right: auto; }

.locbtn{width:100%;background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;margin-bottom:10px;cursor:pointer;display:flex;align-items:center;gap:14px;text-align:start; box-shadow:0 2px 5px rgba(0,0,0,0.02); transition:0.2s;}
.locbtn:active{transform:scale(0.98); background:#f8fafc;}
.locbtn .ic{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg, #eff6ff, #dbeafe);color:var(--blue);display:grid;place-items:center;font-size:18px;flex-shrink:0;}
.locbtn .nm{font-weight:800;font-size:14px; margin-bottom:2px;} 
.locbtn .pt{font-size:11.5px;color:var(--muted);}
.locbtn .cnt{margin-inline-start:auto;text-align:center;flex-shrink:0; background:#f1f5f9; padding:6px 12px; border-radius:10px;}
.locbtn .cnt b{display:block;font-size:15px;color:var(--navy); font-weight:800;}
.locbtn .cnt span{font-size:10px;color:var(--green);font-weight:800;}
.locbtn.fp{border-color:#bae6fd;background:linear-gradient(180deg,#f4f9ff,#fff);}
.fpbadge{background:var(--blue);color:#fff;border-radius:12px;font-size:10px;font-weight:800;padding:3px 8px; margin-inline-end:4px;}
.morebtn{width:100%;background:#f1f5f9;border:1.5px dashed #cbd5e1;border-radius:14px;padding:14px;font-weight:800;font-size:13px;color:var(--text2);cursor:pointer;margin-bottom:10px; transition:0.2s;}
.bgroup{margin-bottom:6px;}
.bgroup>button{width:100%;background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-family:inherit;font-weight:800;font-size:13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;margin-bottom:8px; box-shadow:0 2px 4px rgba(0,0,0,0.02);}
.bgroup .rooms{display:none;padding-inline-start:8px;}
.bgroup.open .rooms{display:block;}

.tab-container {display:flex; background:#e2e8f0; border-radius:14px; padding:5px; margin-bottom:14px; border:1px solid var(--line);}
.tab-btn {flex:1; background:transparent; color:var(--muted); border-radius:11px; padding:12px 6px; font-size:13px; font-weight:800; transition:0.2s; border:none; cursor:pointer;}
.tab-btn.active {background:#fff; color:var(--blue); box-shadow:0 2px 6px rgba(0,0,0,0.06);}

.roomhead{background:linear-gradient(135deg,#eff6ff,#f0fdfa);border:1.5px solid #bae6fd;border-radius:16px;padding:16px;margin-bottom:16px; box-shadow:0 2px 8px rgba(3,105,161,0.05);}
.assetcard{width:100%;background:#fff;border:1.5px solid var(--line);border-radius:14px;padding:14px;margin-bottom:10px;display:flex;align-items:center;gap:12px;text-align:start;cursor:pointer; transition:all 0.2s; box-shadow:0 2px 4px rgba(15,23,42,0.03);}
.assetcard:active{transform:translateY(-2px) scale(0.98); box-shadow:0 4px 8px rgba(15,23,42,0.06); border-color:#cbd5e1;}
.assetcard.done{background:linear-gradient(135deg, #f0fdf4, #fff);border-color:#bbf7d0;}
.assetcard.miss{background:linear-gradient(135deg, #fef2f2, #fff);border-color:#fecaca;}
.crit{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:17px;flex-shrink:0;}
.crit.A{background:#fee2e2;color:#b91c1c;} .crit.B{background:#fef3c7;color:#b45309;} .crit.C{background:#f1f5f9;color:#64748b;}
.assetcard .stx{margin-inline-start:auto;font-size:20px;flex-shrink:0;}

.steps{display:flex;align-items:flex-start;padding:12px 22px 4px;gap:4px;}
.step{flex:1;text-align:center;position:relative;}
.step .dot{width:32px;height:32px;border-radius:50%;margin:0 auto 6px;display:grid;place-items:center;font-weight:800;font-size:14px;color:#fff;background:#cbd5e1;transition:.3s; z-index:2; position:relative;}
.step.done .dot{background:var(--green); box-shadow:0 0 0 4px rgba(22,163,74,0.15);}
.step.active .dot{background:var(--blue); box-shadow:0 0 0 5px rgba(21,101,192,.2);}
.step .lbl{font-size:11.5px;color:var(--muted);font-weight:700;}
.step.active .lbl{color:var(--navy);font-weight:800;}
.step:not(:last-child)::after{content:'';position:absolute;top:15px;inset-inline-start:calc(50% + 20px);width:calc(100% - 40px);height:4px;background:#e2e8f0; border-radius:2px; z-index:1;}
.step.done:not(:last-child)::after{background:var(--green);}

.idcard{border-inline-start:6px solid var(--green);display:flex;gap:12px;align-items:center;}
.idcard .nm{font-weight:800;font-size:14.5px;line-height:1.4; margin-bottom:2px;}
.idcard .sn{font-size:12px;color:var(--text2);direction:ltr;text-align:end;font-family:monospace; background:#f1f5f9; padding:2px 6px; border-radius:6px; display:inline-block;}

.smartloc{border:1.5px solid var(--line);background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 8px rgba(15,23,42,0.04); margin-bottom:16px;}
.smartloc.match{border-color:#86efac; background:linear-gradient(135deg, #f0fdf4, #fff);}
.smartloc.mismatch{border-color:#fcd34d; background:linear-gradient(135deg, #fffbeb, #fff);}
.smartloc .sl1{font-size:12.5px;color:var(--text2);margin-bottom:8px; line-height:1.5;}
.smartloc .sl2{font-weight:800;font-size:13.5px;margin-bottom:16px; line-height:1.5; color:var(--navy);}
.loc-badge {display:inline-block; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:800; margin-bottom:6px;}
.loc-badge.current {background:#e0f2fe; color:#0369a1;}
.loc-badge.registered {background:#f1f5f9; color:#475569;}
.btnrow{display:flex;gap:10px;}

.opsrow{display:flex;gap:8px;}
.opsbtn{flex:1;border:1.5px solid var(--line);background:#f8fafc;border-radius:14px;padding:14px 4px;font-weight:800;font-size:12px;cursor:pointer;color:var(--text2); transition:0.2s;}
.opsbtn .em{display:block;font-size:20px;margin-bottom:4px;}
.opsbtn.sel{color:#fff; transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,0.1);}
.opsbtn.sel.o-active{background:linear-gradient(135deg, var(--green), #15803d);border-color:transparent;}
.opsbtn.sel.o-maint{background:linear-gradient(135deg, var(--orange), #c2410c);border-color:transparent;}
.opsbtn.sel.o-out{background:linear-gradient(135deg, #334155, #0f172a);border-color:transparent;}
.opsbtn.sel.o-medical{background:linear-gradient(135deg, #2563eb, #1e40af);border-color:transparent;}
.opsbtn.sel.o-it{background:linear-gradient(135deg, #7c3aed, #5b21b6);border-color:transparent;}
.opsbtn.sel.o-general{background:linear-gradient(135deg, #0891b2, #0e7490);border-color:transparent;}

.health{display:flex;gap:6px;}
.hbtn{flex:1;border:1.5px solid var(--line);border-radius:12px;padding:12px 2px;background:#f8fafc;color:var(--text2);font-weight:800;font-size:11px;cursor:pointer;text-align:center;transition:.2s;line-height:1.4;}
.hbtn.sel{color:#fff;outline:none;transform:translateY(-2px);border-color:transparent;box-shadow:0 4px 8px rgba(0,0,0,0.15);}
.h5.sel{background:var(--green);} .h4.sel{background:var(--green-l); color:var(--navy);} .h3x.sel{background:#eab308; color:var(--navy);} .h2x.sel{background:var(--orange);} .h1x.sel{background:var(--red);}

.stage{display:none;} .stage.on{display:block;animation:fadeIn .25s;}
.savebar{position:fixed;bottom:0;inset-inline:0;max-width:520px;margin-inline:auto;background:rgba(255,255,255,0.95); backdrop-filter:blur(10px); border-top:1.5px solid var(--line);padding:14px 16px;display:none;gap:10px;z-index:30;}
.savebar.show{display:flex;}
.savebar .next{flex:1;background:var(--blue);color:#fff;border:none;border-radius:14px;padding:14px;font-weight:800;font-size:15px;cursor:pointer; box-shadow:0 2px 10px rgba(21,101,192,0.2);}
.savebar .next:disabled{background:#cbd5e1;cursor:not-allowed; box-shadow:none; transform:none;}
.savebar .next.save{background:var(--green); box-shadow:0 2px 10px rgba(22,163,74,0.2);}
.savebar .miss{background:#fff;border:2px solid var(--red);color:var(--red);border-radius:14px;padding:14px 16px;font-weight:800;font-size:13px;cursor:pointer;}

.chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.chip{background:linear-gradient(135deg, #f0f9ff, #e0f2fe);border:1px solid #bae6fd;color:#0369a1;border-radius:10px;padding:6px 12px;font-size:12px;font-weight:800; box-shadow:0 1px 2px rgba(0,0,0,0.03);}
#toast{position:fixed;bottom:104px;inset-inline:0;max-width:360px;margin-inline:auto;background:var(--navy);color:#fff;border-radius:14px;padding:14px 16px;font-size:13.5px;font-weight:700;text-align:center;opacity:0;pointer-events:none;transition:.3s;z-index:9999; box-shadow:0 10px 25px rgba(0,0,0,0.2);}
#toast.show{opacity:1; transform:translateY(-10px);}
@keyframes shake { 0%, 100% {transform: translateX(0);} 25% {transform: translateX(-5px);} 75% {transform: translateX(5px);} }
.error-highlight { border: 2px solid #ef4444 !important; box-shadow: 0 0 10px rgba(239,68,68,0.3) !important; animation: shake 0.4s ease-in-out; background-color:#fef2f2 !important; }
</style>
</head>
<body>

<div class="topbar">
  <div class="ring" id="ring"><b id="ringTxt">—</b></div>
  <div>
    <div class="t1"><?= e($session ? ($session['title'] . ' — ' . $session['session_code']) : ($is_ar ? 'المسح الميداني' : 'Field Scan')) ?></div>
    <div class="t2" id="topSub"><?= $is_ar ? 'حدّد موقعك للبدء' : 'Select location to begin' ?></div>
  </div>
  <!-- 🌟 زر تبديل اللغة 🌟 -->
  <a href="?session=<?= $session_id ?>&lang=<?= $is_ar ? 'en' : 'ar' ?>" class="lang-toggle">
      <i class="fa-solid fa-globe"></i> <?= $is_ar ? 'English' : 'عربي' ?>
  </a>
  <button class="backbtn" id="backBtn" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i> <?= $is_ar ? 'رجوع' : 'Back' ?></button>
</div>

<?php if ($session): ?>
<script>
// 🌟 دوال المعالجة المركزية (Bulletproof) لقلب لغات المواقع وتصحيحها 🌟
function getRoomName(rm) {
    if(!rm) return '';
    // نبحث عن العربي في أي حقل يحمل دلالة (ar) أو الحقل المقلوب (name_en)
    const ar = rm.name_en || rm.room_name_en || rm.name_ar || rm.room_name_ar || rm.name || rm.room_name || '';
    // نبحث عن الإنجليزي في الحقول الأصلية (name)
    const en = rm.name || rm.room_name || rm.name_en || rm.room_name_en || '';
    return window.IS_AR ? ar : en;
}
function getBldName(rm) {
    if(!rm) return '';
    const ar = rm.building_en || rm.building_name_en || rm.building_ar || rm.building_name_ar || rm.building || rm.building_name || '';
    const en = rm.building || rm.building_name || rm.building_en || rm.building_name_en || '';
    return window.IS_AR ? ar : en;
}
function getFlrName(rm) {
    if(!rm) return '';
    const ar = rm.floor_en || rm.floor_name_en || rm.floor_ar || rm.floor_name_ar || rm.floor || rm.floor_name || '';
    const en = rm.floor || rm.floor_name || rm.floor_en || rm.floor_name_en || '';
    return window.IS_AR ? ar : en;
}
function getCatName(c) {
    if(!c) return '';
    const ar = c.name_ar || c.name_en || c.name || '';
    const en = c.name_en || c.name || c.name_ar || '';
    return window.IS_AR ? ar : en;
}
</script>

<div class="screen on" id="scrLoc">
  <div class="wrap">
    <div class="card" style="padding:12px;">
      <div style="font-size:12px;font-weight:800;color:var(--navy);margin-bottom:8px; padding-inline-end:4px;"><i class="fa-solid fa-filter"></i> <?= $is_ar ? 'طريقة الاستعراض' : 'Filter View' ?></div>
      <div style="display:flex; gap:8px;">
        <select id="deptFilter" onchange="loadLocations()" class="finp" style="margin:0">
          <option value="">🗺️ <?= $is_ar ? 'حسب الموقع (كل المواقع)' : 'By Location (All)' ?></option>
          <!-- 🌟 دمج الاسم الإنجليزي في قائمة الأقسام 🌟 -->
          <?php foreach ($filter_depts as $fd): ?><option value="<?= (int)$fd['id'] ?>">🎯 <?= e($is_ar ? $fd['name'] : (!empty($fd['name_en']) ? $fd['name_en'] : $fd['name'])) ?></option><?php endforeach; ?>
        </select>
        <button id="clearDeptBtn" class="btn btn-o" style="display:none;padding:10px 14px;" onclick="clearDeptFilter()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    
    <div class="searchrow">
      <input type="text" id="globalSearchIn" lang="en" inputmode="latin" placeholder="<?= $is_ar ? '🔍 بحث شامل بالتاج أو السيريال…' : '🔍 Search by Tag or Serial...' ?>" onkeydown="if(event.key==='Enter') doLookup(this.value, 'globalSearchIn')">
      <button class="cambtn" style="background:linear-gradient(135deg, var(--blue), #1e3a8a);" onclick="startIdentifierDictation('globalSearchIn')"><i class="fa-solid fa-microphone"></i></button>
      <button class="cambtn" onclick="toggleCamera('global')"><i class="fa-solid fa-camera"></i></button>
    </div>
    <div id="globalCameraBox" style="display:none; border:2px solid var(--navy); border-radius:14px; overflow:hidden; margin-bottom:12px; box-shadow:0 4px 10px rgba(0,0,0,0.15)"><div id="globalQrReader"></div></div>
    <div class="alert" id="globalLookupErr"></div>

    <div id="deptStats" style="display:none"></div>
    
    <div style="margin-top:16px; margin-bottom:10px;">
        <h3 class="sec" id="locTitle" style="margin:0 0 10px 0;"><i class="fa-solid fa-map-location-dot"></i> <?= $is_ar ? 'أين تقف الآن؟' : 'Where are you now?' ?> <span class="hint" id="fpHint"></span></h3>
        <div class="searchrow" style="margin:0;" id="locSearchBox">
            <input type="text" id="locFilterIn" placeholder="<?= $is_ar ? '🔍 ابحث للوصول السريع (مبنى، دور، غرفة)...' : '🔍 Quick search (Building, Floor, Room)...' ?>" class="finp" style="margin:0; background:#fff; border-radius:14px; box-shadow:inset 0 1px 3px rgba(0,0,0,0.02);" onkeyup="filterLocList()">
        </div>
    </div>

    <div id="fpList"><div class="loading"><i class="fa-solid fa-circle-notch"></i> <?= $is_ar ? 'جاري تحميل المواقع...' : 'Loading locations...' ?></div></div>
    
    <button class="morebtn" id="moreBtn" onclick="toggleOthers()" style="display:none"><i class="fa-solid fa-layer-group"></i> <?= $is_ar ? 'عرض كافة المواقع والمباني الأخرى' : 'Show all other buildings & locations' ?></button>
    <div id="othersList" style="display:none"></div>
  </div>
</div>

<div class="screen" id="scrRoom">
  <div class="wrap">
    <div class="roomhead">
      <div style="font-weight:800;font-size:16px;color:var(--navy); margin-bottom:4px;" id="roomName">—</div>
      <div style="font-size:12.5px;color:var(--text2);font-weight:500;" id="roomPath">—</div>
      <div style="height:10px;background:#e2e8f0;border-radius:5px;margin-top:14px;overflow:hidden; box-shadow:inset 0 1px 2px rgba(0,0,0,0.1);"><i id="roomBar" style="display:block;height:100%;background:linear-gradient(90deg,var(--teal),var(--green));transition:width .4s;width:0%"></i></div>
    </div>
    <div class="searchrow">
      <input type="text" id="searchIn" lang="en" inputmode="latin" placeholder="<?= $is_ar ? '🔍 مسح التاج أو السيريال…' : '🔍 Scan Tag or Serial...' ?>" onkeydown="if(event.key==='Enter') doLookup(this.value, 'searchIn')">
      <button class="cambtn" style="background:linear-gradient(135deg, var(--blue), #1e3a8a);" onclick="startIdentifierDictation('searchIn')"><i class="fa-solid fa-microphone"></i></button>
      <button class="cambtn" onclick="toggleCamera('room')"><i class="fa-solid fa-camera"></i></button>
    </div>
    <div id="cameraBox" style="display:none; border:2px solid var(--navy); border-radius:14px; overflow:hidden; margin-bottom:12px;"><div id="qrReader"></div></div>
    <div class="alert" id="lookupErr"></div>
    <div id="assetList"></div>
  </div>
</div>

<div class="screen" id="scrDevice">
  <div class="steps" id="deviceSteps">
    <div class="step done"><div class="dot">✓</div><div class="lbl"><?= $is_ar ? 'الهوية' : 'ID' ?></div></div>
    <div class="step active" id="st2"><div class="dot">2</div><div class="lbl"><?= $is_ar ? 'الموقع والحالة' : 'Location & Status' ?></div></div>
    <div class="step" id="st3"><div class="dot">3</div><div class="lbl"><?= $is_ar ? 'البيانات' : 'Data' ?></div></div>
  </div>

  <div class="wrap">
    <div class="card idcard">
      <div class="crit B" id="dCrit">—</div>
      <div>
        <div class="nm" id="dName">—</div>
        <div class="sn" id="dTag">—</div>
      </div>
    </div>

    <div id="deviceDoneMsg" style="display:none; background:linear-gradient(135deg, #f0fdf4, #dcfce7); border:1.5px solid #bbf7d0; border-radius:16px; padding:24px; text-align:center; box-shadow:0 4px 10px rgba(22,163,74,0.1);">
        <div style="font-size:36px; margin-bottom:12px; color:var(--green)"><i class="fa-solid fa-circle-check"></i></div>
        <div style="font-weight:800; font-size:18px; color:var(--green); margin-bottom:8px;"><?= $is_ar ? 'تم جرد هذا الجهاز مسبقاً' : 'This device has already been audited' ?></div>
        <div style="font-size:13px; color:#166534; font-weight:700; background:#fff; display:inline-block; padding:8px 16px; border-radius:12px; margin-bottom:16px;" id="deviceDoneDetails"></div>
        
        <div style="font-size:12.5px; color:var(--text2); margin-bottom:20px; background:#fff; padding:12px; border-radius:12px; text-align:start;">
            <div style="margin-bottom:6px;"><i class="fa-regular fa-user" style="color:var(--blue)"></i> <?= $is_ar ? 'الموظف' : 'User' ?>: <b id="auditorName"></b></div>
            <div><i class="fa-regular fa-clock" style="color:var(--orange)"></i> <?= $is_ar ? 'الوقت' : 'Time' ?>: <b id="auditedAt" dir="ltr"></b></div>
        </div>

        <div style="display:flex; flex-direction:column; gap:10px;">
            <button class="btn btn-o" style="color:var(--orange); border-color:var(--orange);" onclick="openReauditModal()"><i class="fa-solid fa-flag"></i> <?= $is_ar ? 'طلب إعادة تحقق للجهاز' : 'Request Re-audit' ?></button>
            <button id="adminUnlockBtn" class="btn btn-o" style="display:none; color:var(--blue); border-color:var(--blue);" onclick="unlockDevice()"><i class="fa-solid fa-unlock"></i> <?= $is_ar ? 'فتح البطاقة الآن (صلاحية مدير)' : 'Unlock Card Now (Admin)' ?></button>
        </div>
    </div>

    <div id="deviceActiveForms">
        <div class="alert" id="serialAlert" style="display:none"><b>⚠️ <?= $is_ar ? 'السيريال غير مسجَّل' : 'Serial not registered' ?></b></div>
        <div class="alert" id="saveErr"></div>

        <div class="stage on" id="stage2">
          <div class="card agecard" id="ageCard">
            <div class="agehead"><span>⏳ <?= $is_ar ? 'العمر الافتراضي' : 'Life Expectancy' ?></span> <span class="agetag" id="ageTag">—</span></div>
            <div class="agebar"><i id="ageBarFill"></i></div>
            <div class="agemeta" id="ageMeta">—</div>
          </div>

          <h3 class="sec">📍 <?= $is_ar ? 'التحقق من الموقع' : 'Location Verification' ?></h3>
          <div class="smartloc" id="smartLoc">
            <div class="sl1" id="slIntro">—</div>
            <div class="sl2" id="dLoc">—</div>
            <div class="btnrow">
              <button class="btn btn-g" id="locOk" style="flex:2;" onclick="confirmLoc()"><i class="fa-solid fa-check"></i> <?= $is_ar ? 'تأكيد الموقع' : 'Confirm Location' ?></button>
              <button class="btn btn-o" id="locMove" style="flex:1;" onclick="openRoomPicker()"><i class="fa-solid fa-pen"></i> <?= $is_ar ? 'تغيير' : 'Change' ?></button>
            </div>
            <div style="font-size:12.5px;color:var(--green);font-weight:800;margin-top:12px;display:none; background:#f0fdf4; padding:8px; border-radius:8px; text-align:center;" id="locHint">✓ <?= $is_ar ? 'تم تأكيد الموقع' : 'Location Confirmed' ?></div>
          </div>

          <h3 class="sec" style="margin-top:16px">⚙️ <?= $is_ar ? 'الحالة العامة' : 'General Status' ?></h3>
          <div class="card">
            <div class="opsrow">
              <button class="opsbtn o-active" data-v="active" onclick="pickOps(this,'<?= $is_ar ? 'نشط' : 'Active' ?>')"><span class="em">🟢</span><?= $is_ar ? 'نشط' : 'Active' ?></button>
              <button class="opsbtn o-maint"  data-v="under_maintenance" onclick="pickOps(this,'<?= $is_ar ? 'قيد الصيانة' : 'Maintenance' ?>')"><span class="em">🛠️</span><?= $is_ar ? 'صيانة' : 'Maint.' ?></button>
              <button class="opsbtn o-out"    data-v="inactive" onclick="pickOps(this,'<?= $is_ar ? 'خارج الخدمة' : 'Inactive' ?>')"><span class="em">⚫</span><?= $is_ar ? 'خارج الخدمة' : 'Inactive' ?></button>
            </div>
          </div>

          <h3 class="sec">🔧 <?= $is_ar ? 'الحالة الفنية' : 'Condition' ?></h3>
          <div class="card">
            <div class="health">
              <button class="hbtn h5" data-v="100" onclick="pickH(this,'<?= $is_ar ? 'ممتاز' : 'Excellent' ?>')"><?= $is_ar ? 'ممتاز' : 'Excel.' ?></button>
              <button class="hbtn h4" data-v="80"  onclick="pickH(this,'<?= $is_ar ? 'جيد' : 'Good' ?>')"><?= $is_ar ? 'جيد' : 'Good' ?></button>
              <button class="hbtn h3x" data-v="60" onclick="pickH(this,'<?= $is_ar ? 'مقبول' : 'Fair' ?>')"><?= $is_ar ? 'مقبول' : 'Fair' ?></button>
              <button class="hbtn h2x" data-v="40" onclick="pickH(this,'<?= $is_ar ? 'يحتاج صيانة' : 'Needs Repair' ?>')"><?= $is_ar ? 'صيانة' : 'Repair' ?></button>
              <button class="hbtn h1x" data-v="20" onclick="pickH(this,'<?= $is_ar ? 'ضعيف جداً' : 'Poor' ?>')"><?= $is_ar ? 'ضعيف' : 'Poor' ?></button>
            </div>
            <div class="hstate" id="hstate"></div>
          </div>
          <div class="alert" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;" id="consistHint">💡 <?= $is_ar ? 'اخترت "خارج الخدمة" — هل حالته ضعيفة؟' : 'You selected Inactive, is the condition poor?' ?></div>
        </div>

        <div class="stage" id="stage3">
          <h3 class="sec">📋 <?= $is_ar ? 'البيانات المرافقة' : 'Additional Data' ?></h3>
          <div class="card">
            <div class="chips" id="dChips"></div>
            
            <div style="border:2px solid var(--amber);background:#fffbeb;border-radius:14px;padding:14px;margin-bottom:14px; display:block;" id="serialField">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                  <label id="serialLabel" style="display:block;font-size:13px;font-weight:800;color:#b45309;"><?= $is_ar ? 'السيريال نمبر' : 'Serial Number' ?></label>
                  <span id="existingSerialBadge" style="display:none; font-family:monospace; background:#fde68a; color:#0369a1; padding:3px 8px; border-radius:8px; font-size:12px; font-weight:bold;"></span>
              </div>
              
              <div style="display:flex;gap:8px;align-items:stretch">
                <input type="text" id="serialIn" lang="en" inputmode="latin" class="finp" style="margin:0;direction:ltr;font-family:monospace;flex:1" onblur="handleSerialInput(this.value)" oninput="updateBar()">
                <button type="button" class="cambtn" style="background:linear-gradient(135deg, var(--blue), #1e3a8a);width:46px;border-radius:11px" onclick="startIdentifierDictation('serialIn')" title="<?= $is_ar ? 'إملاء صوتي ذكي' : 'Smart Voice Dictation' ?>"><i class="fa-solid fa-microphone"></i></button>
                <button type="button" class="cambtn" style="width:46px;border-radius:11px" onclick="toggleCamera('device')" title="<?= $is_ar ? 'مسح بالكاميرا' : 'Camera Scan' ?>"><i class="fa-solid fa-camera"></i></button>
              </div>
              <div id="deviceCameraBox" style="display:none; border:2px solid var(--navy); border-radius:14px; overflow:hidden; margin-top:10px;"><div id="deviceQrReader"></div></div>
              
              <div id="serialMatchResult" style="display:none; margin-top:10px; font-size:12.5px; padding:12px; border-radius:10px;"></div>
              
              <div id="deviceSerialDupAlert" style="display:none; background:#fef2f2; border:1.5px solid #fca5a5; border-radius:10px; padding:10px; margin-top:10px; font-size:12.5px;">
                <div style="color:#b91c1c; font-weight:800; margin-bottom:4px;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $is_ar ? 'تنبيه: هذا السيريال مسجل مسبقاً لجهاز آخر!' : 'Alert: Serial already registered to another device!' ?></div>
                <div style="color:#7f1d1d; line-height:1.6;" id="deviceSerialDupDetails"></div>
              </div>
            </div>
            
            <textarea id="notesIn" class="finp" style="margin:0; min-height:80px;" placeholder="<?= $is_ar ? 'اكتب ملاحظات إضافية (اختياري)...' : 'Additional notes (optional)...' ?>"></textarea>
          </div>
        </div>
    </div>
  </div>
</div>

<div class="savebar" id="savebar">
  <button class="next" id="nextBtn" onclick="go()"><?= $is_ar ? 'التالي ←' : 'Next →' ?></button>
  <button class="miss" onclick="openMissSheet()"><?= $is_ar ? 'غير موجود ✗' : 'Missing ✗' ?></button>
</div>

<!-- ════ النوافذ المنبثقة (Modals) ════ -->
<div class="modal" id="missModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="sheet">
    <h4 style="margin:0 0 16px;color:var(--navy);font-weight:800;"><i class="fa-solid fa-circle-question"></i> <?= $is_ar ? 'الجهاز غير موجود؟' : 'Device is missing?' ?></h4>
    <button class="btn btn-o" style="width:100%;margin-bottom:10px;text-align:start;padding:14px;" onclick="submitMiss('missing')"><span style="font-weight:800;font-size:14px;color:var(--red);">✗ <?= $is_ar ? 'مفقود' : 'Missing' ?></span> <small style="display:block;color:var(--text2);margin-top:4px;"><?= $is_ar ? 'غير موجود ولا يُعرف مكانه حالياً' : 'Not found and location unknown' ?></small></button>
    <button class="btn btn-o" style="width:100%;margin-bottom:12px;text-align:start;padding:14px;" onclick="submitMiss('missing_disposed_previously')"><span style="font-weight:800;font-size:14px;color:var(--navy);">💀 <?= $is_ar ? 'مُكهَّن سابقاً' : 'Disposed Previously' ?></span> <small style="display:block;color:var(--text2);margin-top:4px;"><?= $is_ar ? 'سبق التخلص منه أو إتلافه' : 'Already disposed or scrapped' ?></small></button>
    <button class="btn" style="width:100%;background:#f1f5f9;color:var(--text2);font-weight:800;" onclick="document.getElementById('missModal').classList.remove('show')"><?= $is_ar ? 'إلغاء' : 'Cancel' ?></button>
  </div>
</div>

<div class="modal" id="reauditModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="sheet">
    <h4 style="margin:0 0 16px;color:var(--orange);font-weight:800;"><i class="fa-solid fa-flag"></i> <?= $is_ar ? 'طلب إعادة تحقق للجهاز' : 'Request Re-audit' ?></h4>
    <div style="font-size:12.5px; color:var(--text2); margin-bottom:18px;"><?= $is_ar ? 'اكتب سبب رغبتك في إعادة تقييم هذا الجهاز، وسيتم إشعار مدير النظام لفتح البطاقة لك.' : 'State the reason for re-audit, system admin will be notified to unlock it.' ?></div>
    <div class="input-wrap">
        <textarea id="reauditReason" class="finp" style="min-height:80px; padding-inline-start:40px;" placeholder="<?= $is_ar ? 'السبب...' : 'Reason...' ?>"></textarea>
        <button class="mic-btn left" onclick="startDictation('<?= $is_ar ? 'ar-SA' : 'en-US' ?>', 'reauditReason')"><i class="fa-solid fa-microphone"></i></button>
    </div>
    <button class="btn btn-o" style="width:100%; border-color:var(--orange); color:var(--orange); margin-bottom:10px;" onclick="submitReauditRequest()"><i class="fa-solid fa-paper-plane"></i> <?= $is_ar ? 'إرسال الطلب' : 'Submit Request' ?></button>
    <button class="btn" style="width:100%;background:#f1f5f9;color:var(--text2);font-weight:800;" onclick="document.getElementById('reauditModal').classList.remove('show')"><?= $is_ar ? 'إلغاء' : 'Cancel' ?></button>
  </div>
</div>

<div class="modal" id="roomPicker" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="sheet" style="max-height:85vh;overflow-y:auto; padding-top:24px;">
    <h4 style="margin:0 0 16px;color:var(--navy);font-weight:800;"><i class="fa-solid fa-map-location-dot"></i> <?= $is_ar ? 'اختر الموقع الفعلي' : 'Select Actual Location' ?></h4>
    <div class="searchrow" style="margin-bottom:16px;">
        <input type="text" id="roomSearchInput" onkeyup="filterRoomPicker()" placeholder="<?= $is_ar ? '🔍 ابحث عن غرفة، دور، أو مبنى...' : '🔍 Search room, floor, or building...' ?>" style="width:100%; border:1.5px solid var(--line); border-radius:12px; padding:12px; font-size:13px; background:#f8fafc;">
    </div>
    <div id="pickerList" style="max-height:45vh; overflow-y:auto; padding-inline-end:4px;"></div>
    <button class="btn" style="width:100%;background:#f1f5f9;color:var(--text2);font-weight:800;margin-top:16px;" onclick="document.getElementById('roomPicker').classList.remove('show')"><?= $is_ar ? 'إلغاء' : 'Cancel' ?></button>
  </div>
</div>

<!-- ════ بطاقة تسجيل أصل زيادة (Surplus) ثنائية اللغة ومصححة المواقع ════ -->
<div class="modal" id="surplusModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="sheet">
    <button class="btn btn-g" style="width:100%; margin-bottom:15px; background:linear-gradient(135deg, #7c3aed, #4c1d95); box-shadow:0 4px 10px rgba(124,58,237,0.3); border:none; color:white; font-size:14px;" onclick="VoiceWizard.start()" type="button"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $is_ar ? 'بدء التسجيل الصوتي الذكي' : 'Start Smart Voice Wizard' ?></button>

    <h4 style="color:var(--blue); margin-bottom:5px; font-weight:800;"><i class="fa-solid fa-plus-circle"></i> <?= $is_ar ? 'تسجيل جهاز (أصل زيادة)' : 'Register Surplus Asset' ?></h4>
    <div style="font-size:12.5px; color:var(--text2); margin-bottom:18px; font-weight:700;"><?= $is_ar ? 'جميع الحقول أدناه <span style="color:var(--red);">إلزامية</span> لضمان دقة النظام.' : 'All fields are <span style="color:var(--red);">mandatory</span>.' ?></div>

    <div id="surplusDupAlert" style="display:none; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; padding:12px; margin-bottom:14px; font-size:12.5px;">
      <div style="color:#b91c1c; font-weight:800; margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $is_ar ? 'تنبيه: هذا السيريال أو التاج مسجل مسبقاً!' : 'Alert: Tag or Serial already registered!' ?></div>
      <div style="color:#7f1d1d; line-height:1.6;" id="surplusDupDetails"></div>
    </div>

    <!-- حقول التاج والسيريال -->
    <div style="display:flex; gap:10px; margin-bottom:14px;">
        <div style="flex:1">
            <label class="form-lbl"><?= $is_ar ? 'رقم التاج' : 'Tag Number' ?> <span style="color:var(--red)">*</span></label>
            <div style="display:flex;gap:4px;align-items:stretch">
                <input type="text" id="surplusTag" lang="en" inputmode="latin" class="finp" style="margin:0;font-family:monospace;direction:ltr;flex:1" placeholder="BHC..." onblur="checkLiveDupSurplus()">
                <button type="button" class="cambtn" style="background:linear-gradient(135deg, var(--blue), #1e3a8a);width:40px;border-radius:11px" onclick="startIdentifierDictation('surplusTag')" title="إملاء صوتي"><i class="fa-solid fa-microphone"></i></button>
                <button type="button" class="cambtn" style="width:40px;border-radius:11px" onclick="toggleCamera('surplusTag')" title="مسح بالكاميرا"><i class="fa-solid fa-camera"></i></button>
            </div>
        </div>
        <div style="flex:1">
            <label class="form-lbl"><?= $is_ar ? 'السيريال نمبر' : 'Serial Number' ?> <span style="color:var(--red)">*</span></label>
            <div style="display:flex;gap:4px;align-items:stretch">
                <input type="text" id="surplusSerial" lang="en" inputmode="latin" class="finp" style="margin:0;font-family:monospace;direction:ltr;flex:1" onblur="checkLiveDupSurplus()">
                <button type="button" class="cambtn" style="background:linear-gradient(135deg, var(--blue), #1e3a8a);width:40px;border-radius:11px" onclick="startIdentifierDictation('surplusSerial')" title="إملاء صوتي"><i class="fa-solid fa-microphone"></i></button>
                <button type="button" class="cambtn" style="width:40px;border-radius:11px" onclick="toggleCamera('surplusSerial')" title="مسح بالكاميرا"><i class="fa-solid fa-camera"></i></button>
            </div>
        </div>
    </div>
    <div id="surplusCameraBox" style="display:none; border:2px solid var(--navy); border-radius:14px; overflow:hidden; margin-bottom:14px;"><div id="surplusQrReader"></div></div>

    <label class="form-lbl"><?= $is_ar ? 'نوع الجهاز' : 'Asset Type' ?> <span style="color:var(--red)">*</span></label>
    <div class="opsrow" id="surplusAssetTypeRow" style="margin-bottom:14px; border-radius:16px;">
        <button type="button" class="opsbtn o-medical" data-v="medical" onclick="pickAssetType(this,'medical')">🏥 <?= $is_ar ? 'طبي' : 'Medical' ?></button>
        <button type="button" class="opsbtn o-it" data-v="it" onclick="pickAssetType(this,'it')">💻 <?= $is_ar ? 'تقنية' : 'IT' ?></button>
        <button type="button" class="opsbtn o-general" data-v="other" onclick="pickAssetType(this,'other')">🔧 <?= $is_ar ? 'عام' : 'General' ?></button>
    </div>

    <?php if ($is_ar): ?>
        <label class="form-lbl">الوصف (عربي) <span style="color:var(--red)">*</span></label>
        <div class="input-wrap">
            <input type="text" id="surplusDescAr" class="finp" style="padding-inline-start:40px;" oninput="smartTranslate(this.value, 'surplusDescAr', 'surplusDescEn')">
            <button type="button" class="mic-btn left" onclick="startDictation('ar-SA', 'surplusDescAr')"><i class="fa-solid fa-microphone"></i></button>
        </div>
        <label class="form-lbl">الوصف (إنجليزي) <span style="color:var(--red)">*</span></label>
        <div class="input-wrap">
            <input type="text" id="surplusDescEn" class="finp" style="direction:ltr; padding-inline-end:40px;" oninput="smartTranslate(this.value, 'surplusDescAr', 'surplusDescEn')">
            <button type="button" class="mic-btn right" onclick="startDictation('en-US', 'surplusDescEn')"><i class="fa-solid fa-microphone"></i></button>
        </div>
    <?php else: ?>
        <label class="form-lbl">Description (English) <span style="color:var(--red)">*</span></label>
        <div class="input-wrap">
            <input type="text" id="surplusDescEn" class="finp" style="direction:ltr; padding-inline-end:40px;" oninput="smartTranslate(this.value, 'surplusDescEn', 'surplusDescAr')">
            <button type="button" class="mic-btn right" onclick="startDictation('en-US', 'surplusDescEn')"><i class="fa-solid fa-microphone"></i></button>
        </div>
        <label class="form-lbl">Description (Arabic) <span style="color:var(--red)">*</span></label>
        <div class="input-wrap">
            <input type="text" id="surplusDescAr" class="finp" style="padding-inline-start:40px;" oninput="smartTranslate(this.value, 'surplusDescEn', 'surplusDescAr')">
            <button type="button" class="mic-btn left" onclick="startDictation('ar-SA', 'surplusDescAr')"><i class="fa-solid fa-microphone"></i></button>
        </div>
    <?php endif; ?>

    <!-- القوائم المنسدلة -->
    <div id="manualDropdownsBox" style="border-radius:14px; transition:0.3s; padding:2px;">
        <label class="form-lbl"><?= $is_ar ? 'التصنيف' : 'Category' ?> <span style="color:var(--red)">*</span></label>
        <select id="cat1" class="finp" onchange="filterCat2()"><option value="">-- <?= $is_ar ? 'رئيسي' : 'Main' ?> --</option></select>
        <div style="display:flex; gap:10px;">
            <select id="cat2" class="finp" onchange="filterCat3()"><option value="">-- <?= $is_ar ? 'فرعي' : 'Sub' ?> --</option></select>
            <select id="cat3" class="finp"><option value="">-- <?= $is_ar ? 'دقيق' : 'Micro' ?> --</option></select>
        </div>

        <label class="form-lbl"><?= $is_ar ? 'الموقع' : 'Location' ?> <span style="color:var(--red)">*</span></label>
        <select id="locBld" class="finp" onchange="filterFloor()"><option value="">-- <?= $is_ar ? 'المبنى' : 'Building' ?> --</option></select>
        <div style="display:flex; gap:10px;">
            <select id="locFlr" class="finp" onchange="filterRoom()"><option value="">-- <?= $is_ar ? 'الدور' : 'Floor' ?> --</option></select>
            <select id="locRm" class="finp"><option value="">-- <?= $is_ar ? 'الغرفة' : 'Room' ?> --</option></select>
        </div>
    </div>

    <div style="display:flex; gap:12px; margin-top:14px;">
        <button id="btnSaveSurplus" class="btn btn-g" style="flex:2;" onclick="submitSurplus()"><i class="fa-solid fa-floppy-disk"></i> <?= $is_ar ? 'حفظ كأصل زيادة' : 'Save Surplus Asset' ?></button>
        <button class="btn btn-o" style="flex:1;" onclick="document.getElementById('surplusModal').classList.remove('show'); VoiceWizard.stop();"><?= $is_ar ? 'إلغاء' : 'Cancel' ?></button>
    </div>
  </div>
</div>
<div id="toast"></div>

<script>
window.IS_AR = <?= $is_ar ? 'true' : 'false' ?>;
window.isSpeakingWarning = false;

const SID = <?= (int)$session_id ?>;
const BASE = '<?= BASE_URL ?>';
const TOTAL_SCOPE = <?= (int)$total_scope ?>;
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>; 
let doneScope = <?= (int)$done_scope ?>;

let screen = 'loc', curRoom = null, cur = null, stage = 2;
let surplusAssetType = null; 
let locConfirmed = false, locMoved = false, ops = null, opsLabel = '', health = null, healthLabel = '', saving = false;
let moveTargetId = null, othersOpen = false, qrScanner = null;
let allCategories = [], allLocs = [], roomAssets = [], showTargetOnly = false;

const $ = id => document.getElementById(id);
const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(t){const e=$('toast');e.textContent=t;e.classList.add('show');setTimeout(()=>e.classList.remove('show'),2400);}

const ARABIC_DIGITS = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
const LATIN_DIGITS  = ['0','1','2','3','4','5','6','7','8','9'];
function toLatinDigits(s) {
  if (!s) return s;
  let out = '';
  for (const ch of s) {
    if (ch >= '٠' && ch <= '٩') out += LATIN_DIGITS[ARABIC_DIGITS.indexOf(ch)];
    else out += ch;
  }
  return out;
}
['globalSearchIn','searchIn','serialIn','surplusTag','surplusSerial'].forEach(id => {
  const el = $(id); if (!el) return;
  el.addEventListener('blur', function() {
    const v = toLatinDigits(this.value);
    if (v !== this.value) { this.value = v; if (typeof updateBar==='function') updateBar(); }
  });
});

function paintRing(){
  const pct = TOTAL_SCOPE ? Math.min(100, Math.round(doneScope/TOTAL_SCOPE*100)) : 0;
  $('ringTxt').textContent = pct+'%';
  $('ring').style.background = `conic-gradient(var(--teal-br) 0 ${pct}%, rgba(255,255,255,.15) ${pct}% 100%)`;
  $('topSub').textContent = window.IS_AR ? `تم إنجاز ${doneScope} من ${TOTAL_SCOPE} أصلاً` : `Completed ${doneScope} of ${TOTAL_SCOPE}`;
}

function show(s){
  ['scrLoc','scrRoom','scrDevice'].forEach(x=>$(x)&&$(x).classList.remove('on'));
  $(s==='loc'?'scrLoc':s==='room'?'scrRoom':'scrDevice').classList.add('on');
  screen = s;
  $('backBtn').innerHTML = s==='loc' ? (window.IS_AR?'<i class="fa-solid fa-house"></i> لوحة الجلسة':'<i class="fa-solid fa-house"></i> Dashboard') : (window.IS_AR?'<i class="fa-solid fa-arrow-right"></i> رجوع':'<i class="fa-solid fa-arrow-left"></i> Back');
  
  if (s==='device' && cur && !cur.done) $('savebar').classList.add('show');
  else $('savebar').classList.remove('show');
  
  stopCamera(); window.scrollTo({top:0});
}
function goBack(){
  if (screen==='device') { if (!curRoom) { show('loc'); loadLocations(); } else { show('room'); } }
  else if (screen==='room') { show('loc'); loadLocations(); }
  else { location.href = `${BASE}/inventory/session.php?id=${SID}`; }
}

function deptSel(){ const s=$('deptFilter'); return s ? (parseInt(s.value)||0) : 0; }
function clearDeptFilter() { $('deptFilter').value = ''; loadLocations(); }
function toggleOthers(){
  othersOpen = !othersOpen; $('othersList').style.display = othersOpen?'block':'none';
  $('moreBtn').innerHTML = othersOpen ? (window.IS_AR?'<i class="fa-solid fa-chevron-up"></i> إخفاء المواقع الأخرى':'<i class="fa-solid fa-chevron-up"></i> Hide others') : (window.IS_AR?'<i class="fa-solid fa-layer-group"></i> عرض كافة المواقع والمباني الأخرى':'<i class="fa-solid fa-layer-group"></i> Show all buildings');
}

function filterLocList() {
    const q = $('locFilterIn').value.toLowerCase();
    if (q.trim() !== '') {
        $('othersList').style.display = 'block';
        if($('moreBtn')) $('moreBtn').style.display = 'none';
    } else {
        $('othersList').style.display = othersOpen ? 'block' : 'none';
        if($('moreBtn')) $('moreBtn').style.display = 'block';
    }
    document.querySelectorAll('#fpList .locbtn').forEach(btn => {
        const txt = btn.innerText.toLowerCase();
        btn.style.display = txt.includes(q) ? 'flex' : 'none';
    });
    document.querySelectorAll('#othersList .bgroup').forEach(bg => {
        let hasVisibleRoom = false;
        const bldgName = bg.querySelector('span').innerText.toLowerCase();
        bg.querySelectorAll('.locbtn').forEach(btn => {
            const txt = btn.innerText.toLowerCase();
            const match = txt.includes(q) || bldgName.includes(q);
            btn.style.display = match ? 'flex' : 'none';
            if(match) hasVisibleRoom = true;
        });
        if (hasVisibleRoom && q.trim() !== '') {
            bg.style.display = 'block'; bg.classList.add('open');
        } else if (hasVisibleRoom && q.trim() === '') {
            bg.style.display = 'block'; bg.classList.remove('open');
        } else {
            bg.style.display = 'none';
        }
    });
}

function getRoomName(rm) {
    if(!rm) return '';
    const ar = rm.name_en || rm.room_name_en || rm.name_ar || rm.room_name_ar || rm.name || rm.room_name || '';
    const en = rm.name || rm.room_name || rm.name_en || rm.room_name_en || '';
    return window.IS_AR ? ar : en;
}
function getBldName(rm) {
    if(!rm) return '';
    const ar = rm.building_en || rm.building_name_en || rm.building_ar || rm.building_name_ar || rm.building || rm.building_name || '';
    const en = rm.building || rm.building_name || rm.building_en || rm.building_name_en || '';
    return window.IS_AR ? ar : en;
}
function getFlrName(rm) {
    if(!rm) return '';
    const ar = rm.floor_en || rm.floor_name_en || rm.floor_ar || rm.floor_name_ar || rm.floor || rm.floor_name || '';
    const en = rm.floor || rm.floor_name || rm.floor_en || rm.floor_name_en || '';
    return window.IS_AR ? ar : en;
}
function getCatName(c) {
    if(!c) return '';
    const ar = c.name_ar || c.name_en || c.name || '';
    const en = c.name_en || c.name || c.name_ar || '';
    return window.IS_AR ? ar : en;
}

async function loadLocations(){
  curRoom = null; 
  const dep = deptSel();
  if ($('clearDeptBtn')) $('clearDeptBtn').style.display = dep > 0 ? 'block' : 'none';
  $('fpList').innerHTML = `<div class="loading"><i class="fa-solid fa-circle-notch"></i> ${window.IS_AR?'جاري تحميل المواقع...':'Loading...'}</div>`;
  if($('moreBtn')) $('moreBtn').style.display = 'none'; 
  if($('othersList')) $('othersList').style.display = 'none'; 
  othersOpen = false;
  if($('locSearchBox')) $('locSearchBox').style.display = 'none'; 
  if($('locFilterIn')) $('locFilterIn').value = '';
  
  try{
    const r = await fetch(`${BASE}/inventory/api/locations_summary.php?session_id=${SID}` + (dep ? `&dept_id=${dep}` : ''));
    const j = await r.json();
    if(!j.ok) {
        $('fpList').innerHTML = `<div class="card" style="text-align:center;color:var(--red)"><i class="fa-solid fa-triangle-exclamation" style="font-size:24px;margin-bottom:8px"></i><br>${esc(j.error)}</div>`;
        return;
    }
    allCategories = j.categories || []; allLocs = j.all_locs || [];

    const roomBtn = (rm, fp) => `
      <button class="locbtn ${fp?'fp':''} ${rm.done>=rm.total?'complete':''}" onclick="openRoom(${rm.id||rm.room_id})">
        <div class="ic"><i class="fa-solid fa-door-open"></i></div>
        <div style="flex:1"><div class="nm">${esc(getRoomName(rm))} ${fp?(window.IS_AR?'<span class="fpbadge">المعتاد</span>':'<span class="fpbadge">Usual</span>'):''}</div>
             <div class="pt">${esc(getBldName(rm))} / ${esc(getFlrName(rm))}</div></div>
        <div class="cnt"><b>${rm.total}</b><span>${rm.done} ✓</span></div>
      </button>`;

    if (j.dept_mode){
      const s = j.stats;
      $('deptStats').innerHTML = `
        <div class="card" style="background:linear-gradient(135deg,#f0f9ff,#f0fdfa);border-color:#bae6fd">
          <div style="font-weight:800;font-size:14px;color:var(--navy);margin-bottom:10px"><i class="fa-solid fa-bullseye"></i> ${esc(j.dept.name)}</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:12px;font-weight:800">
            <span style="background:#dcfce7;color:#166534;border-radius:10px;padding:6px 12px; box-shadow:0 1px 2px rgba(0,0,0,0.05)">${window.IS_AR?'مؤكدة':'Conf.'}: ${s.custody}</span>
            <span style="background:#dcfce7;color:#166534;border-radius:10px;padding:6px 12px; box-shadow:0 1px 2px rgba(0,0,0,0.05)">🟢 ${window.IS_AR?'عالية':'High'}: ${s.high}</span>
            <span style="background:#fef3c7;color:#b45309;border-radius:10px;padding:6px 12px; box-shadow:0 1px 2px rgba(0,0,0,0.05)">🟡 ${window.IS_AR?'متوسطة':'Med.'}: ${s.medium}</span>
          </div>
        </div>`;
      $('deptStats').style.display='';
      $('locTitle').innerHTML = window.IS_AR ? `📍 مواقع الأجهزة <span class="hint">(${j.rooms.length} موقع)</span>` : `📍 Locations <span class="hint">(${j.rooms.length})</span>`;
      $('fpList').innerHTML = j.rooms.map(rm=>roomBtn(rm,false)).join('') || '<div class="card" style="text-align:center; color:var(--muted)">Empty.</div>';
      if(j.rooms.length > 0 && $('locSearchBox')) $('locSearchBox').style.display = 'block';
    } else {
      $('deptStats').style.display='none';
      $('locTitle').innerHTML = window.IS_AR ? `🗺️ أين تقف الآن؟ <span class="hint" id="fpHint"></span>` : `🗺️ Where are you? <span class="hint" id="fpHint"></span>`;
      $('fpList').innerHTML = j.fingerprint.map(rm=>roomBtn(rm,true)).join('');
      if($('locSearchBox')) $('locSearchBox').style.display = 'block';

      if (j.others && j.others.length){
        if($('moreBtn')) $('moreBtn').style.display = 'block';
        if($('othersList')) {
            $('othersList').innerHTML = j.others.map((b,i)=>`
              <div class="bgroup" id="bg${i}">
                <button onclick="document.getElementById('bg${i}').classList.toggle('open')">
                  <span><i class="fa-solid fa-building"></i> ${esc(getBldName(b))}</span>
                  <span class="hint" style="background:#e2e8f0; color:var(--navy); padding:2px 8px; border-radius:10px;">${b.rooms.length} ${window.IS_AR?'غرفة':'Rooms'}</span></button>
                <div class="rooms">${b.rooms.map(rm=>roomBtn(rm,false)).join('')}</div>
              </div>`).join('');
        }
      }
    }
  }catch(e){
    $('fpList').innerHTML = `<div class="card" style="text-align:center; color:var(--red)"><i class="fa-solid fa-wifi" style="font-size:24px;margin-bottom:8px"></i><br>Connection error.</div>`;
  }
}

async function openRoom(roomId){
  show('room');
  $('assetList').innerHTML = `<div class="loading"><i class="fa-solid fa-circle-notch"></i> ${window.IS_AR?'جاري تحميل الأجهزة...':'Loading assets...'}</div>`;
  const currentDept = deptSel();
  showTargetOnly = (currentDept > 0); 
  try{
    const r = await fetch(`${BASE}/inventory/api/room_assets.php?session_id=${SID}&room_id=${roomId}&dept_id=${currentDept}`);
    const j = await r.json();
    if(!j.ok) {
        $('assetList').innerHTML = `<div class="card" style="text-align:center; padding:30px;"><i class="fa-solid fa-triangle-exclamation" style="font-size:30px; color:var(--red); margin-bottom:10px;"></i><br><b>Error:</b> ${esc(j.error)}</div>`;
        return;
    }
    curRoom = j.room; roomAssets = j.assets;
    renderRoom();
  }catch(e){
    $('assetList').innerHTML = `<div class="card" style="text-align:center; padding:30px;"><i class="fa-solid fa-wifi" style="font-size:30px; color:var(--orange); margin-bottom:10px;"></i><br><b>Connection failed.</b></div>`;
  }
}

function renderRoom(){
  $('roomName').textContent = getRoomName(curRoom);
  const done = roomAssets.filter(a=>a.done).length;
  $('roomPath').innerHTML = `${esc(getBldName(curRoom))} / ${esc(getFlrName(curRoom))} &nbsp;&mdash;&nbsp; <b style="color:var(--green)">${done} / ${roomAssets.length}</b> ${window.IS_AR?'منجز':'Done'}`;
  $('roomBar').style.width = roomAssets.length ? (done/roomAssets.length*100)+'%' : '0%';
  
  let html = '';
  const currentDept = deptSel();
  if (currentDept > 0) {
    const targetCount = roomAssets.filter(a => a.is_target).length;
    html += `
    <div class="tab-container">
      <button class="tab-btn ${showTargetOnly ? 'active' : ''}" onclick="showTargetOnly=true; renderRoom();"><i class="fa-solid fa-bullseye"></i> ${window.IS_AR?'الأجهزة المطلوبة':'Target'} (${targetCount})</button>
      <button class="tab-btn ${!showTargetOnly ? 'active' : ''}" onclick="showTargetOnly=false; renderRoom();"><i class="fa-solid fa-layer-group"></i> ${window.IS_AR?'كل الغرفة':'All'} (${roomAssets.length})</button>
    </div>`;
  }

  const displayedAssets = (showTargetOnly && currentDept > 0) ? roomAssets.filter(a => a.is_target) : roomAssets;
  html += displayedAssets.map(a=>{
    const missy = a.done && (a.last_action||'').startsWith('missing');
    return `
    <button class="assetcard ${a.done?(missy?'miss':'done'):''}" onclick="openDevice(${a.id})">
      <div class="crit ${esc(a.crit)}">${esc(a.crit)}</div>
      <div style="flex:1"><div style="font-weight:800;font-size:13.5px;margin-bottom:4px;color:var(--navy)">${esc(window.IS_AR?(a.name_ar||a.name):(a.name||a.name_ar))}</div>
           <div style="font-size:11.5px;color:var(--text2);font-family:monospace;">${esc(a.tag||(window.IS_AR?'بلا تاج':'No Tag'))}${a.serial?' • '+esc(a.serial):''}</div></div>
      <div class="stx">${a.done?(missy?'<i class="fa-solid fa-circle-xmark" style="color:var(--red)"></i>':'<i class="fa-solid fa-circle-check" style="color:var(--green)"></i>'):'<i class="fa-regular fa-circle" style="color:var(--muted)"></i>'}</div>
    </button>`;
  }).join('') || `<div class="card" style="text-align:center; color:var(--muted); padding:30px;"><i class="fa-solid fa-box-open" style="font-size:30px; margin-bottom:10px; opacity:0.5"></i><br>${window.IS_AR?'لا توجد أجهزة مطابقة.':'No matching devices.'}</div>`;
  $('assetList').innerHTML = html;
}

function openDevice(id){
  const a = roomAssets.find(x=>x.id===id);
  if(!a) return;
  a.external=false;
  openDeviceObj(a);
}

function openDeviceObj(a){
  cur=a; stage=2; locConfirmed=false; locMoved=false;
  ops=null; opsLabel=''; health=null; healthLabel=''; saving=false;
  $('saveErr').classList.remove('show');

  $('dName').textContent = window.IS_AR ? (a.name_ar || a.name) : (a.name || a.name_ar);
  $('dTag').textContent = (a.tag||(window.IS_AR?'بلا تاج':'No Tag')) + (a.serial?' • SN '+a.serial:'');
  $('dCrit').textContent = a.crit; $('dCrit').className='crit '+a.crit;

  if(a.done) {
      $('deviceSteps').style.display = 'none';
      $('deviceActiveForms').style.display = 'none';
      $('deviceDoneMsg').style.display = 'block';
      $('savebar').classList.remove('show');
      
      let actionTxt = window.IS_AR ? 'مكتمل ✓' : 'Confirmed ✓';
      if(a.last_action && a.last_action.startsWith('miss')) actionTxt = window.IS_AR ? 'مسجل كمفقود ✗' : 'Missing ✗';
      else if(a.last_action === 'condition_damaged') actionTxt = window.IS_AR ? 'مسجل كمعطل/تالف 🛠️' : 'Damaged 🛠️';
      
      $('deviceDoneDetails').textContent = (window.IS_AR ? 'الإجراء الأخير: ' : 'Last Action: ') + actionTxt;
      $('auditorName').textContent = a.auditor || (window.IS_AR ? 'مستخدم النظام' : 'System User');
      $('auditedAt').textContent = a.audited_at || (window.IS_AR ? 'غير محدد' : 'Unknown');
      
      if (IS_ADMIN) $('adminUnlockBtn').style.display = 'inline-flex';
      else $('adminUnlockBtn').style.display = 'none';
      show('device'); return;
  }
  
  $('deviceSteps').style.display = 'flex';
  $('deviceActiveForms').style.display = 'block';
  $('deviceDoneMsg').style.display = 'none';
  $('deviceSerialDupAlert').style.display = 'none';
  $('serialMatchResult').style.display = 'none';
  window.awaitingSerialConfirm = false;

  $('serialField').style.display = 'block'; 
  $('serialIn').value = '';
  
  if (a.serial) {
      $('serialLabel').textContent = window.IS_AR ? 'السيريال المسجل مسبقاً بالنظام' : 'Registered Serial';
      $('existingSerialBadge').textContent = a.serial;
      $('existingSerialBadge').style.display = 'inline-block';
      $('serialIn').placeholder = window.IS_AR ? "امسح السيريال للتحقق..." : "Scan to verify...";
      $('serialAlert').style.display = 'none';
  } else {
      $('serialLabel').textContent = window.IS_AR ? 'السيريال نمبر — (إلزامي للتسجيل)' : 'Serial Number (Mandatory)';
      $('existingSerialBadge').style.display = 'none';
      $('serialIn').placeholder = window.IS_AR ? "أدخل السيريال هنا..." : "Enter serial here...";
      $('serialAlert').style.display = 'block';
  }

  $('notesIn').value='';

  if(a.age){
    $('ageCard').style.display='';
    const p=a.age.pct;
    $('ageTag').textContent = p+'% '+(p>=100?(window.IS_AR?'متجاوز':'Over'):p>=70?(window.IS_AR?'قارب الانتهاء':'Warning'):(window.IS_AR?'ضمن العمر':'Good'));
    $('ageTag').className = 'agetag '+(p>=100?'age-over':p>=70?'age-warn':'age-ok');
    $('ageBarFill').style.width = Math.min(p,100)+'%';
    $('ageBarFill').style.background = p>=100 ? 'linear-gradient(90deg,#f97316,#dc2626)' : p>=70 ? '#eab308' : 'var(--green)';
    $('ageMeta').textContent = window.IS_AR ? `تشغيل: ${a.age.placed} • مقرر: ${a.age.life} شهر • مستهلك: ${a.age.elapsed} شهر` : `Placed: ${a.age.placed} • Life: ${a.age.life}m • Elapsed: ${a.age.elapsed}m`;
  } else { $('ageCard').style.display='none'; }

  const herePath = curRoom ? `${getRoomName(curRoom)} — ${getBldName(curRoom)} / ${getFlrName(curRoom)}` : '';
  
  if (!curRoom || a.external){
    $('smartLoc').className = 'smartloc mismatch';
    if (!curRoom) {
        $('slIntro').innerHTML = `<span class="loc-badge current">${window.IS_AR?'موقعك الحالي':'Current Location'}</span><br>🌍 <b>${window.IS_AR?'وضع البحث الشامل':'Global Search Mode'}</b>`;
        $('dLoc').innerHTML = `<span class="loc-badge registered">${window.IS_AR?'الموقع المسجل للجهاز':'Registered Location'}</span><br>${esc(a.loc_text||(window.IS_AR?'غير محدد':'Unknown'))} <div style="color:#b45309; font-size:12px; margin-top:4px;"><i class="fa-solid fa-triangle-exclamation"></i> ${window.IS_AR?'يرجى التأكد من موقعه الفعلي':'Please verify actual location'}</div>`;
        $('locOk').innerHTML = window.IS_AR ? '<i class="fa-solid fa-check"></i> نعم، الجهاز في موقعه المسجّل' : '<i class="fa-solid fa-check"></i> Yes, device is in registered location';
    } else {
        $('slIntro').innerHTML = `<span class="loc-badge current">${window.IS_AR?'أنت تقف في':'You are in'}</span><br><b>${esc(herePath)}</b>`;
        $('dLoc').innerHTML = `<span class="loc-badge registered">${window.IS_AR?'الموقع المسجل للجهاز':'Registered Location'}</span><br>${esc(a.loc_text||(window.IS_AR?'غير محدد':'Unknown'))} <div style="color:#b45309; font-size:12px; margin-top:4px;"><i class="fa-solid fa-triangle-exclamation"></i> ${window.IS_AR?'غير مطابق لموقعك الحالي':'Mismatch with current location'}</div>`;
        $('locOk').innerHTML = window.IS_AR ? '<i class="fa-solid fa-check"></i> الموقع المسجَّل صحيح' : '<i class="fa-solid fa-check"></i> Registered location is correct';
    }
  } else {
    $('smartLoc').className = 'smartloc match';
    $('slIntro').innerHTML = `<span class="loc-badge current">${window.IS_AR?'أنت تقف في':'You are in'}</span><br><b>${esc(getRoomName(curRoom))}</b>`;
    $('dLoc').innerHTML = `<span class="loc-badge registered">${window.IS_AR?'الموقع المسجل للجهاز':'Registered Location'}</span><br>${esc(herePath)} <div style="color:var(--green); font-size:12px; margin-top:4px;"><i class="fa-solid fa-circle-check"></i> ${window.IS_AR?'مطابق لموقعك':'Matches your location'}</div>`;
    $('locOk').innerHTML = window.IS_AR ? '<i class="fa-solid fa-check"></i> تأكيد الموقع' : '<i class="fa-solid fa-check"></i> Confirm Location';
  }
  
  moveTargetId=null;
  $('locOk').classList.remove('on'); $('locOk').disabled=false; $('locOk').style.opacity='';
  $('locMove').disabled=false; $('locMove').style.opacity='1';
  $('locHint').style.display='none';

  $('stage2').classList.add('on'); $('stage3').classList.remove('on');
  $('st2').className='step active'; $('st2').querySelector('.dot').textContent='2';
  $('st3').className='step'; $('st3').querySelector('.dot').textContent='3';
  document.querySelectorAll('.opsbtn').forEach(b=>b.classList.remove('sel'));
  document.querySelectorAll('.hbtn').forEach(b=>b.classList.remove('sel'));
  
  if (a.status && ['active','under_maintenance','inactive'].includes(a.status)){
    const b=document.querySelector(`.opsbtn[data-v="${a.status}"]`);
    if(b){b.classList.add('sel');ops=a.status;opsLabel=b.textContent.trim();}
  }
  $('hstate').textContent=''; $('consistHint').style.display='none';
  
  $('dChips').innerHTML = (a.chips||[]).map(c=>`<span class="chip"><i class="fa-solid fa-tag"></i> ${esc(c)}</span>`).join('');
  if((a.chips||[]).length === 0) $('dChips').innerHTML = `<span class="hint" style="display:block; width:100%; margin-bottom:10px;">${window.IS_AR?'لا توجد بيانات إضافية':'No additional data'}</span>`;
  
  $('nextBtn').classList.remove('save');
  show('device'); updateBar();
}

function confirmLoc(){
  locConfirmed=true; locMoved=false; moveTargetId=null;
  $('locOk').classList.add('on');
  $('locMove').disabled=false; $('locMove').style.opacity='1';
  $('locHint').innerHTML=`<i class="fa-solid fa-check"></i> ${window.IS_AR?'تم تأكيد الموقع':'Location Confirmed'}`; $('locHint').style.display='block';
  updateBar();
}

function unlockDevice() {
    $('deviceDoneMsg').style.display = 'none';
    $('deviceSteps').style.display = 'flex';
    $('deviceActiveForms').style.display = 'block';
    $('savebar').classList.add('show');
    updateBar();
}

function openReauditModal() { $('reauditModal').classList.add('show'); }
function submitReauditRequest() {
    const reason = $('reauditReason').value.trim();
    if(!reason) { alert(window.IS_AR?'الرجاء كتابة سبب إعادة التحقق.':'Please enter reason.'); return; }
    alert(window.IS_AR?'تم إرسال طلب إعادة التحقق لمدير النظام بنجاح!':'Request sent to admin successfully!');
    $('reauditModal').classList.remove('show');
}

function openRoomPicker(){
  const cur_id = curRoom ? curRoom.id : 0;
  const list = [];
  if (curRoom) {
    list.push(`<button class="btn room-picker-btn" data-search="${esc(getRoomName(curRoom))} ${esc(getBldName(curRoom))} ${esc(getFlrName(curRoom))}" style="width:100%;margin-bottom:8px;border:1.5px solid var(--green);background:#f0fdf4;text-align:start;color:var(--navy);" onclick="applyMove(${curRoom.id}, '${esc(getRoomName(curRoom))}')"><i class="fa-solid fa-location-dot" style="color:var(--green)"></i> ${window.IS_AR?'موقعي الحالي':'Current Location'}: ${esc(getRoomName(curRoom))}</button>`);
  }
  if (allLocs && allLocs.length > 0) {
      allLocs.forEach(rm=>{
        if(rm.room_id === cur_id) return;
        const rName = getRoomName(rm);
        const bld = getBldName(rm);
        const flr = getFlrName(rm);
        const searchStr = `${rName} ${bld} ${flr}`.toLowerCase();
        list.push(`<button class="btn btn-o room-picker-btn" data-search="${esc(searchStr)}" style="width:100%;margin-bottom:8px;text-align:start;" onclick="applyMove(${rm.room_id||rm.id}, '${esc(rName)}')"><span style="font-weight:800; font-size:13.5px;">${esc(rName)}</span> <small style="display:block;color:var(--muted);margin-top:4px;">${esc(bld)} / ${esc(flr)}</small></button>`);
      });
  }
  $('pickerList').innerHTML = list.join('') || `<div class="hint" style="text-align:center;padding:20px;">${window.IS_AR?'لا توجد مواقع متاحة':'No locations available'}</div>`;
  $('roomSearchInput').value = ''; 
  $('roomPicker').classList.add('show');
  setTimeout(() => { if($('roomSearchInput')) $('roomSearchInput').focus(); }, 100);
}

function filterRoomPicker() {
    const query = $('roomSearchInput').value.toLowerCase();
    const buttons = document.querySelectorAll('#pickerList .room-picker-btn');
    buttons.forEach(btn => {
        const text = btn.getAttribute('data-search') || '';
        if (text.includes(query)) btn.style.display = 'block';
        else btn.style.display = 'none';
    });
}

function applyMove(roomId, roomName){
  $('roomPicker').classList.remove('show');
  locConfirmed=true; locMoved=true; moveTargetId=roomId;
  $('locOk').classList.remove('on'); 
  $('locHint').innerHTML=`<i class="fa-solid fa-share"></i> ${window.IS_AR?'سيُنقل إلى':'Moving to'}: ${roomName}`; $('locHint').style.display='block';
  updateBar();
}

function pickOps(btn,label){
  document.querySelectorAll('.opsbtn').forEach(b=>b.classList.remove('sel'));
  btn.classList.add('sel'); ops=btn.dataset.v; opsLabel=label;
  $('consistHint').style.display = (ops==='inactive' && health && health>20) ? 'block' : 'none';
  updateBar();
}
function pickH(btn,label){
  document.querySelectorAll('.hbtn').forEach(b=>b.classList.remove('sel'));
  btn.classList.add('sel'); health=+btn.dataset.v; healthLabel=label;
  $('hstate').textContent='✓ '+label;
  $('consistHint').style.display = (ops==='inactive' && health>20) ? 'block' : 'none';
  updateBar();
}

function updateBar(){
  const b=$('nextBtn');
  if(stage===2){
    const ready = locConfirmed && ops && health;
    b.disabled=!ready; b.classList.remove('save');
    b.innerHTML = ready ? (window.IS_AR?'التالي <i class="fa-solid fa-arrow-left"></i>':'Next <i class="fa-solid fa-arrow-right"></i>') : (window.IS_AR?'أكمل: الموقع + العامة + الفنية':'Complete: Location + Status');
  }else{
    const needSerial = cur && !cur.serial;
    const s=$('serialIn').value.trim();
    const hasError = $('deviceSerialDupAlert').style.display === 'block';
    const pendingConfirm = window.awaitingSerialConfirm === true;
    
    const ready = (!needSerial || s) && !saving && !hasError && !pendingConfirm;
    
    b.disabled=!ready; b.classList.add('save');
    if(saving) b.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> ${window.IS_AR?'جاري الحفظ…':'Saving...'}`;
    else if(ready) b.innerHTML = `<i class="fa-solid fa-floppy-disk"></i> ${window.IS_AR?'حفظ التحقق':'Save Verification'}`;
    else if(pendingConfirm) b.innerHTML = window.IS_AR?'يرجى تأكيد السيريال':'Please confirm serial';
    else b.innerHTML = window.IS_AR?'أكمل السيريال بشكل صحيح':'Complete serial correctly';
  }
}

function go(){
  if(stage===2){
    stage=3;
    $('stage2').classList.remove('on'); $('stage3').classList.add('on');
    $('st2').className='step done'; $('st2').querySelector('.dot').textContent='✓';
    $('st3').className='step active';
    updateBar(); window.scrollTo({top:0,behavior:'smooth'});
  } else { submitConfirm(); }
}

function handleSerialInput(val) {
    checkLiveSerialDevice(val);
}

async function checkLiveSerialDevice(scannedVal) {
    const s = scannedVal.trim();
    const dupAlert = $('deviceSerialDupAlert');
    const dupDetails = $('deviceSerialDupDetails');
    const matchResult = $('serialMatchResult');
    
    dupAlert.style.display = 'none';
    matchResult.style.display = 'none';
    window.awaitingSerialConfirm = false;
    
    if (!s) { updateBar(); return; }

    if (cur.serial && s === cur.serial) {
        matchResult.innerHTML = `<div style="color:#166534; font-weight:800;"><i class="fa-solid fa-circle-check"></i> ${window.IS_AR?'السيريال مطابق تماماً للمسجل بالنظام.':'Serial matches exactly.'}</div>`;
        matchResult.style.background = '#dcfce7';
        matchResult.style.border = '1.5px solid #bbf7d0';
        matchResult.style.display = 'block';
        beep(true);
        updateBar();
        return;
    }

    try {
        const r = await fetch(`${BASE}/inventory/api/lookup.php?session=${SID}&tag=${encodeURIComponent(s)}`);
        const j = await r.json();
        
        if (j.found && j.asset && j.asset.id !== cur.id) {
            const a = j.asset;
            dupDetails.innerHTML = `<b>${window.IS_AR?'الجهاز':'Device'}:</b> ${esc(window.IS_AR?(a.description_ar||a.en_name):(a.en_name||a.description_ar))}<br><b>${window.IS_AR?'التاج':'Tag'}:</b> <span style="font-family:monospace" dir="ltr">${esc(a.tag_number)}</span><br><b>${window.IS_AR?'السيريال':'Serial'}:</b> <span style="font-family:monospace" dir="ltr">${esc(a.serial_number)}</span><br><b>${window.IS_AR?'الموقع':'Location'}:</b> ${esc([a.loc_building, a.loc_floor, a.loc_room].filter(Boolean).join(' / '))}`;
            dupAlert.style.display = 'block';
            beep(false);
            
            $('serialIn').value = '';
            $('serialIn').classList.add('error-highlight');
            setTimeout(() => $('serialIn').classList.remove('error-highlight'), 2000);
            if (!VoiceWizard.isActive) $('serialIn').focus();
            
        } else {
            if (cur.serial && s !== cur.serial) {
                matchResult.innerHTML = `
                    <div style="color:#b45309; font-weight:800; margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation"></i> ${window.IS_AR?'السيريال الممسوح غير مطابق للمسجل!':'Scanned serial does not match!'}</div>
                    <div style="color:#7f1d1d; font-size:12.5px; margin-bottom:10px;">${window.IS_AR?'هل أنت متأكد من استبدال السيريال القديم':'Are you sure you want to replace old serial'} <span style="font-family:monospace; direction:ltr; background:#fde68a; padding:1px 5px; border-radius:4px;">${esc(cur.serial)}</span> ${window.IS_AR?'بالسيريال الجديد؟':'with new serial?'}</div>
                    <div style="display:flex; gap:8px;">
                        <button class="btn btn-g" style="flex:1; padding:8px; font-size:12.5px;" onclick="acceptNewSerial()"><i class="fa-solid fa-check"></i> ${window.IS_AR?'نعم، استخدم الجديد':'Yes, replace'}</button>
                        <button class="btn btn-o" style="flex:1; padding:8px; font-size:12.5px;" onclick="rejectNewSerial()"><i class="fa-solid fa-xmark"></i> ${window.IS_AR?'لا، تراجع':'No, cancel'}</button>
                    </div>
                `;
                matchResult.style.background = '#fffbeb';
                matchResult.style.border = '1.5px solid #fcd34d';
                matchResult.style.display = 'block';
                
                window.awaitingSerialConfirm = true; 
                beep(false);
            } else {
                matchResult.innerHTML = `<div style="color:#166534; font-weight:800;"><i class="fa-solid fa-circle-check"></i> ${window.IS_AR?'السيريال متاح وسليم.':'Serial is available and valid.'}</div>`;
                matchResult.style.background = '#dcfce7';
                matchResult.style.border = '1.5px solid #bbf7d0';
                matchResult.style.display = 'block';
                beep(true);
            }
        }
        updateBar(); 
    } catch(e) {}
}

function acceptNewSerial() {
    window.awaitingSerialConfirm = false;
    $('serialMatchResult').innerHTML = `<div style="color:#166534; font-weight:800;"><i class="fa-solid fa-circle-check"></i> ${window.IS_AR?'تم اعتماد السيريال الجديد بنجاح. سيتم حفظه.':'New serial accepted. Will be saved.'}</div>`;
    $('serialMatchResult').style.background = '#dcfce7';
    $('serialMatchResult').style.border = '1.5px solid #bbf7d0';
    updateBar();
}

function rejectNewSerial() {
    window.awaitingSerialConfirm = false;
    $('serialIn').value = '';
    $('serialMatchResult').style.display = 'none';
    updateBar();
}

async function submitConfirm(){
  if(saving) return; saving=true; updateBar();
  $('saveErr').classList.remove('show');
  const action = (ops==='under_maintenance' || health<=40) ? 'condition_damaged' : 'confirmed';
  const payload = {
    session_id: SID, asset_id: cur.id,
    scanned_tag: cur.tag||'', scanned_serial: cur.serial||'', scan_method: 'manual', match_method: 'manual_search',
    action: action, location_confirmed: locConfirmed, health_confirmed: !!health,
    new_serial: $('serialIn').value.trim(),
    new_health_score: health, new_status: ops,
    new_location_id: (locMoved && moveTargetId) ? moveTargetId : null,
    condition_notes: $('notesIn').value.trim() + (opsLabel?` | ${window.IS_AR?'عامة':'Gen'}: ${opsLabel}`:'') + (healthLabel?` | ${window.IS_AR?'فنية':'Cond'}: ${healthLabel}`:''),
    device_info: navigator.userAgent.slice(0,180),
  };
  try{
    const r = await fetch(`${BASE}/inventory/api/submit.php`,{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const j = await r.json();
    if(!j.ok){
      saving=false; updateBar();
      beep(false); $('saveErr').innerHTML = '⚠️ ' + esc(j.message || j.error); $('saveErr').classList.add('show');
      window.scrollTo({top:0,behavior:'smooth'}); return;
    }
    if(!cur.done) doneScope++;
    if (curRoom) markDone(cur.id, action);
    paintRing(); beep(true); toast(window.IS_AR?'✅ تم حفظ التحقق 🎉':'✅ Verification saved 🎉'); saving=false;
    if(curRoom){ openRoom(curRoom.id); } else { show('loc'); loadLocations(); }
  }catch(e){ saving=false; updateBar(); $('saveErr').textContent=window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Connection failed'; $('saveErr').classList.add('show'); }
}

function openMissSheet(){ if(screen==='device') $('missModal').classList.add('show'); }
async function submitMiss(action){
  $('missModal').classList.remove('show');
  if(saving) return; saving=true;
  try{
    const r = await fetch(`${BASE}/inventory/api/submit.php`,{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({session_id:SID, asset_id:cur.id, scanned_tag:cur.tag||'', scan_method:'manual', match_method:'manual_search', action:action, condition_notes:$('notesIn').value.trim(), device_info:navigator.userAgent.slice(0,180)})});
    const j = await r.json(); saving=false;
    if(!j.ok){ toast('⚠️ '+(j.message||j.error)); return; }
    if(!cur.done) doneScope++;
    if (curRoom) markDone(cur.id, action);
    paintRing(); beep(true); toast(action==='missing' ? (window.IS_AR?'✗ سُجِّل كمفقود':'✗ Marked Missing') : (window.IS_AR?'💀 سُجِّل كمُكهَّن':'💀 Marked Disposed'));
    if(curRoom){ openRoom(curRoom.id); } else { show('loc'); loadLocations(); }
  }catch(e){ saving=false; toast(window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Connection failed'); }
}

function markDone(id, action){ const a = roomAssets.find(x=>x.id===id); if(a){ a.done=true; a.last_action=action; } }

async function doLookup(val, sourceId){
  const q = (val || $(sourceId).value).trim();
  const errBox = sourceId === 'globalSearchIn' ? $('globalLookupErr') : $('lookupErr');
  errBox.classList.remove('show');
  if(!q) return;

  try{
    const r = await fetch(`${BASE}/inventory/api/lookup.php?session=${SID}&tag=${encodeURIComponent(q)}`);
    const j = await r.json();
    if(!j.found){
      beep(false);
      errBox.innerHTML = window.IS_AR 
        ? `⚠️ غير مسجّل. <button class="btn btn-o" style="margin-top:10px;padding:12px;width:100%; border-color:var(--blue); color:var(--blue)" onclick="quickRegister('${esc(q)}')"><i class="fa-solid fa-plus-circle"></i> تسجيله كأصل زيادة الآن</button>`
        : `⚠️ Not found. <button class="btn btn-o" style="margin-top:10px;padding:12px;width:100%; border-color:var(--blue); color:var(--blue)" onclick="quickRegister('${esc(q)}')"><i class="fa-solid fa-plus-circle"></i> Register as Surplus Now</button>`;
      errBox.classList.add('show');
    } else {
      const local = (roomAssets && roomAssets.length) ? roomAssets.find(a=>a.id===j.asset.id) : null;
      if (local) { beep(true); openDevice(local.id); }
      else {
        const a = j.asset;
        let locName = [a.loc_building, a.loc_floor, a.loc_room].filter(Boolean).join(' / ');
        if (!locName && a.location_id && allLocs && allLocs.length) {
            const locObj = allLocs.find(l => l.room_id == a.location_id || l.id == a.location_id);
            if (locObj) {
                locName = [getBldName(locObj), getFlrName(locObj), getRoomName(locObj)].filter(Boolean).join(' / ');
            }
        }
        beep(true);
        openDeviceObj({
            id:+a.id, name:a.en_name||a.description, name_ar:a.description_ar,
            tag:a.tag_number, serial:a.serial_number, crit:a.criticality_class||'C',
            status:a.status, health:a.health_score!==null?+a.health_score:null,
            loc_text: locName,
            chips:[a.manufacturer_name?(window.IS_AR?'الشركة: ':'Mfg: ')+a.manufacturer_name:null, a.model_number?(window.IS_AR?'الموديل: ':'Model: ')+a.model_number:null].filter(Boolean),
            done:j.had_audit, external:true,
            auditor: a.auditor_name, audited_at: a.audited_at
        });
      }
    }
    if($(sourceId)) $(sourceId).value='';
  }catch(e){}
}

let _actx = null;
function beep(ok){
  try{
    _actx = _actx || new (window.AudioContext||window.webkitAudioContext)();
    if(_actx.state==='suspended') _actx.resume();
    const o=_actx.createOscillator(), g=_actx.createGain();
    o.connect(g); g.connect(_actx.destination);
    o.frequency.value = ok?1200:260; o.type='square';
    g.gain.setValueAtTime(.16,_actx.currentTime);
    g.gain.exponentialRampToValueAtTime(.001,_actx.currentTime+(ok?.14:.32));
    o.start(); o.stop(_actx.currentTime+(ok?.15:.33));
  }catch(e){}
  try{ if(navigator.vibrate) navigator.vibrate(ok?60:[90,60,90]); }catch(e){}
}

let _wBuf='', _wLast=0;
document.addEventListener('keydown', function(e){
  const el = document.activeElement;
  const typing = el && (el.tagName==='INPUT'||el.tagName==='TEXTAREA'||el.tagName==='SELECT');
  const now = Date.now();
  if (e.key==='Enter'){
    if (!typing && _wBuf.length>=4){
      const code=_wBuf; _wBuf='';
      if (screen==='device' && stage===3 && cur){
        $('serialIn').value=code; checkLiveSerialDevice(code); updateBar();
        toast(window.IS_AR?'📷 سيريال من الماسح: ':'📷 Scanner Serial: '+code);
      } else if (screen==='room'){ $('searchIn').value=code; doLookup(code,'searchIn'); }
      else { if($('globalSearchIn')) $('globalSearchIn').value=code; doLookup(code,'globalSearchIn'); }
      e.preventDefault();
    } else { _wBuf=''; }
    return;
  }
  if (e.key.length===1){
    if (now-_wLast>50) _wBuf='';
    _wBuf+=e.key; _wLast=now;
  }
});

function toggleCamera(mode){
  let box, readerId, sourceInput;
  if (mode === 'global') { box = $('globalCameraBox'); readerId = 'globalQrReader'; sourceInput = 'globalSearchIn'; }
  else if (mode === 'device') { box = $('deviceCameraBox'); readerId = 'deviceQrReader'; sourceInput = 'serialIn'; }
  else if (mode === 'surplusTag') { box = $('surplusCameraBox'); readerId = 'surplusQrReader'; sourceInput = 'surplusTag'; }
  else if (mode === 'surplusSerial') { box = $('surplusCameraBox'); readerId = 'surplusQrReader'; sourceInput = 'surplusSerial'; }
  else { box = $('cameraBox'); readerId = 'qrReader'; sourceInput = 'searchIn'; }

  if (box.style.display==='block'){ stopCamera(); return; }
  stopCamera(); box.style.display='block';
  qrScanner = new Html5Qrcode(readerId);
  qrScanner.start({facingMode:'environment'}, {fps:10, qrbox:{width:230,height:140}},
    txt=>{
      stopCamera();
      const t = txt.trim();
      if (mode === 'device'){
        $('serialIn').value = t; beep(true); toast((window.IS_AR?'📷 سيريال من الكاميرا: ':'📷 Camera Serial: ') + t);
        checkLiveSerialDevice(t); updateBar();
      } else if (mode === 'surplusTag') {
        $('surplusTag').value = t; beep(true); checkLiveDupSurplus();
      } else if (mode === 'surplusSerial') {
        $('surplusSerial').value = t; beep(true); checkLiveDupSurplus();
      } else { doLookup(t, sourceInput); }
    }, ()=>{}).catch(()=>{});
}
function stopCamera(){
  if(qrScanner) qrScanner.stop().then(()=>qrScanner.clear()).catch(()=>{});
  ['cameraBox', 'globalCameraBox', 'deviceCameraBox', 'surplusCameraBox'].forEach(id => {
     if($(id)) $(id).style.display='none';
  });
}

let translationTimer;
async function smartTranslate(text, fieldArId, fieldEnId) {
    if(!text.trim()) return;
    const isArabic = /[\u0600-\u06FF]/.test(text);
    const sourceLang = isArabic ? 'ar' : 'en';
    const targetLang = isArabic ? 'en' : 'ar';
    const targetFieldId = isArabic ? fieldEnId : fieldArId;
    const originalFieldId = isArabic ? fieldArId : fieldEnId;
    
    $(originalFieldId).value = text;

    clearTimeout(translationTimer);
    translationTimer = setTimeout(async () => {
        try {
            const res = await fetch(`https://translate.googleapis.com/translate_a/single?client=gtx&sl=${sourceLang}&tl=${targetLang}&dt=t&q=${encodeURIComponent(text)}`);
            const json = await res.json();
            $(targetFieldId).value = json[0].map(item => item[0]).join('');
        } catch(e) {}
    }, 500);
}

let _activeDictation = null;
function startDictationUI(input, recognition, postProcess, onDone) {
    const DURATION = 4;
    const targetId = input.id;
    const oldPlace = input.placeholder;
    let finalText = '';
    let isActive = true;
    let timerEl = null;
    let lastInterim = '';

    function stop() {
        if (!isActive) return;
        isActive = false;
        try { recognition.stop(); } catch(e) {}
        clearInterval(countdownInt); clearTimeout(maxTimer);
        if (timerEl && timerEl.parentNode) timerEl.remove();
        input.placeholder = oldPlace;
        _activeDictation = null;
        
        const sourceText = finalText.trim() || lastInterim.trim();
        const processed = postProcess ? postProcess(sourceText) : sourceText;
        if (processed) input.value = processed;
        if (onDone) onDone();
    }

    input.placeholder = window.IS_AR ? '🎤 تحدث الآن... اضغط للإيقاف' : '🎤 Speak now... tap to stop';
    timerEl = document.createElement('div');
    timerEl.style.cssText = 'position:absolute; background:linear-gradient(135deg, #dc2626, #b91c1c); color:#fff; padding:6px 12px; border-radius:8px; font-weight:800; font-size:12px; z-index:1000; box-shadow:0 4px 10px rgba(220, 38, 38, 0.4); pointer-events:none; transition: opacity 0.2s; max-width:280px;';
    timerEl.textContent = window.IS_AR ? `🎤 ${DURATION} ث — اضغط للإيقاف` : `🎤 ${DURATION}s — tap to stop`;
    document.body.appendChild(timerEl);
    
    const rect = input.getBoundingClientRect();
    timerEl.style.left = (rect.left + window.scrollX + 10) + 'px';
    timerEl.style.top  = (rect.top + window.scrollY - 36) + 'px';

    let remaining = DURATION;
    const countdownInt = setInterval(() => {
        remaining--;
        if (remaining > 0) { timerEl.textContent = window.IS_AR ? `🎤 ${remaining} ث — اضغط للإيقاف` : `🎤 ${remaining}s — tap to stop`; } 
        else { timerEl.textContent = window.IS_AR ? '🎤 جاري الإيقاف...' : '🎤 Stopping...'; }
    }, 1000);
    const maxTimer = setTimeout(stop, DURATION * 1000);
    _activeDictation = { stop: stop, targetId: targetId };

    input.addEventListener('click', function onceStop(ev) {
        if (isActive) { ev.stopPropagation(); ev.preventDefault(); stop(); input.removeEventListener('click', onceStop); }
    });

    recognition.onresult = function(e) {
        for (let i = e.resultIndex; i < e.results.length; i++) {
            if (e.results[i].isFinal) finalText += ' ' + e.results[i][0].transcript;
        }
        const interimArr = Array.from(e.results).filter(r => !r.isFinal).map(r => r[0].transcript);
        const interim = interimArr.join(' ').trim();
        if (interim) lastInterim = interim;
        
        const displayText = (finalText + ' ' + interim).trim();
        if (displayText) { input.value = postProcess ? postProcess(displayText) : displayText; }
    };
    recognition.onerror = function(e) { 
        if(e.error === 'no-speech') return;
        stop(); 
    };
    recognition.onend = function() { if (isActive) stop(); };
    recognition.start();
}

function normalizeAlphanumeric(text) {
    let t = text.trim();
    const ARABIC_DIGITS = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    const LATIN_DIGITS  = ['0','1','2','3','4','5','6','7','8','9'];
    t = t.replace(/[٠-٩]/g, d => LATIN_DIGITS[ARABIC_DIGITS.indexOf(d)]);
    t = t.replace(/بي\s*اتش\s*سي/gi, 'BHC').replace(/بي\s*إتش\s*سي/gi, 'BHC');

    const map = {
        "صفر": "0", "زيرو": "0", "واحد": "1", "ون": "1", "اثنين": "2", "اتنين": "2", "تو": "2", "two": "2",
        "ثلاثة": "3", "ثلاثه": "3", "تلاتة": "3", "تلاته": "3", "ثري": "3", "ثلاث": "3", "three": "3",
        "اربعة": "4", "اربعه": "4", "اربع": "4", "أربعة": "4", "فور": "4", "four": "4",
        "خمسة": "5", "خمسه": "5", "خمس": "5", "فايف": "5", "five": "5",
        "ستة": "6", "سته": "6", "ست": "6", "سكس": "6", "six": "6",
        "سبعة": "7", "سبعه": "7", "سبع": "7", "سفن": "7", "seven": "7",
        "ثمانية": "8", "ثمانيه": "8", "ثمان": "8", "ايت": "8", "eight": "8",
        "تسعة": "9", "تسعه": "9", "تسع": "9", "ناين": "9", "nine": "9",
        "إيه": "A", "اي": "A", "ايه": "A", "أيه": "A",
        "بي": "B", "سي": "C", "دي": "D", "إي": "E", "ايي": "E",
        "اف": "F", "إف": "F", "جي": "G", "جيي": "G",
        "اتش": "H", "إتش": "H", "هتش": "H", 
        "آي": "I", "جيه": "J", "كي": "K", 
        "ال": "L", "إل": "L", "ام": "M", "إم": "M", "ان": "N", "إن": "N", 
        "او": "O", "أو": "O", "كيو": "Q", "ار": "R", "آر": "R", 
        "اس": "S", "إس": "S", "تي": "T", "يو": "U", "في": "V", 
        "دبليو": "W", "اكس": "X", "إكس": "X", "واي": "Y", "زد": "Z", "زبد": "Z",
        "شرطة": "-", "شرطه": "-", "داش": "-", "ناقص": "-", "dash": "-"
    };

    let words = t.split(/\s+/);
    words = words.map(w => map[w.toLowerCase()] !== undefined ? map[w.toLowerCase()] : w);
    t = words.join('');
    t = t.replace(/[^a-zA-Z0-9\-]/g, '');
    return t.toUpperCase();
}

function startIdentifierDictation(targetId) {
    if (!window.hasOwnProperty('webkitSpeechRecognition')) { alert(window.IS_AR?"استخدم Google Chrome.":"Use Chrome."); return; }
    const recognition = new webkitSpeechRecognition();
    recognition.continuous = true; recognition.interimResults = true; 
    recognition.lang = window.IS_AR ? 'ar-SA' : 'en-US';
    const inp = $(targetId); 
    
    startDictationUI(inp, recognition, function(text) {
        return normalizeAlphanumeric(text);
    }, async function(){ 
        const val = inp.value;
        if (targetId === 'globalSearchIn' || targetId === 'searchIn') { doLookup(val, targetId); } 
        else if (targetId === 'serialIn') { checkLiveSerialDevice(val); updateBar(); } 
        else if (targetId === 'surplusTag' || targetId === 'surplusSerial') { await checkLiveDupSurplus(); }
    });
}

function startDictation(lang, targetId) {
    if (!window.hasOwnProperty('webkitSpeechRecognition')) { alert(window.IS_AR?"متصفحك لا يدعم.":"Browser not supported."); return; }
    const recognition = new webkitSpeechRecognition();
    recognition.continuous = true; recognition.interimResults = true; recognition.lang = lang;
    const inp = $(targetId);
    startDictationUI(inp, recognition, function(text) { return text.trim(); }, function() {
        if(window.IS_AR) smartTranslate(inp.value, 'surplusDescAr', 'surplusDescEn');
        else smartTranslate(inp.value, 'surplusDescEn', 'surplusDescAr');
    });
}

async function checkLiveDupSurplus() {
    const tag = $('surplusTag').value.trim();
    const serial = $('surplusSerial').value.trim();
    const alertBox = $('surplusDupAlert'); const detailsBox = $('surplusDupDetails'); const btnSave = $('btnSaveSurplus');
    
    if (!tag && !serial) { 
        alertBox.style.display = 'none'; btnSave.disabled = false; btnSave.style.opacity = '1'; 
        return false; 
    }
    try {
        let match = null, matchField = '';
        if (tag) {
            const r1 = await fetch(`${BASE}/inventory/api/lookup.php?session=${SID}&tag=${encodeURIComponent(tag)}`);
            const j1 = await r1.json();
            if (j1.found && j1.asset) { match = j1.asset; matchField = 'surplusTag'; }
        }
        if (!match && serial) {
            const r2 = await fetch(`${BASE}/inventory/api/lookup.php?session=${SID}&tag=${encodeURIComponent(serial)}`);
            const j2 = await r2.json();
            if (j2.found && j2.asset) { match = j2.asset; matchField = 'surplusSerial'; }
        }
        
        if (match) {
            btnSave.disabled = true; btnSave.style.opacity = '0.5';
            let fieldAr = matchField === 'surplusTag' ? (window.IS_AR?'رقم التاج':'Tag') : (window.IS_AR?'السيريال نمبر':'Serial');
            
            let locStr = [match.loc_building, match.loc_floor, match.loc_room].filter(Boolean).join(' / ');
            if (!locStr && match.location_id && allLocs && allLocs.length) {
                const locObj = allLocs.find(l => l.room_id == match.location_id || l.id == match.location_id);
                if (locObj) locStr = [getBldName(locObj), getFlrName(locObj), getRoomName(locObj)].filter(Boolean).join(' / ');
            }

            detailsBox.innerHTML = `<b>${window.IS_AR?'مكرر في':'Dup. in'} ${fieldAr} — ${window.IS_AR?'الجهاز':'Device'}:</b> ${esc(window.IS_AR?(match.description_ar||match.en_name):(match.en_name||match.description_ar))}<br><b>${window.IS_AR?'التاج':'Tag'}:</b> <span style="font-family:monospace" dir="ltr">${esc(match.tag_number)}</span><br><b>${window.IS_AR?'السيريال':'Serial'}:</b> <span style="font-family:monospace" dir="ltr">${esc(match.serial_number)}</span><br><b>${window.IS_AR?'الموقع':'Location'}:</b> ${esc(locStr)}`;
            alertBox.style.display = 'block'; beep(false);

            const offendingInput = $(matchField);
            offendingInput.value = ''; 
            offendingInput.classList.add('error-highlight');
            setTimeout(() => { if(offendingInput) offendingInput.classList.remove('error-highlight'); }, 2000);
            if (!VoiceWizard.isActive) offendingInput.focus();
            
            return true; 
        } else {
            alertBox.style.display = 'none'; btnSave.disabled = false; btnSave.style.opacity = '1';
            return false;
        }
    } catch(e) { return false; }
}

function pickAssetType(btn, val) {
    document.querySelectorAll('#surplusModal .opsbtn').forEach(b => b.classList.remove('sel'));
    btn.classList.add('sel'); surplusAssetType = val;
}

function quickRegister(scannedText) {
    $('lookupErr').classList.remove('show'); $('globalLookupErr').classList.remove('show');
    $('surplusDupAlert').style.display = 'none'; $('btnSaveSurplus').disabled = false; $('btnSaveSurplus').style.opacity = '1';

    surplusAssetType = null;
    document.querySelectorAll('#surplusModal .opsbtn').forEach(b => b.classList.remove('sel'));
    document.querySelectorAll('#surplusModal .error-highlight').forEach(el => el.classList.remove('error-highlight'));

    const txt = scannedText.toUpperCase();
    if (txt.startsWith('BHC')) { $('surplusTag').value = scannedText; $('surplusSerial').value = ''; }
    else { $('surplusTag').value = ''; $('surplusSerial').value = scannedText; }
    checkLiveDupSurplus();
    $('surplusDescAr').value = ''; $('surplusDescEn').value = '';

    const c1 = [...new Set(allCategories.filter(c=>c.level==1).map(c=>getCatName(c)))];
    $('cat1').innerHTML = `<option value="">-- ${window.IS_AR?'رئيسي':'Main'} --</option>` + c1.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join('');
    $('cat2').innerHTML = `<option value="">-- ${window.IS_AR?'فرعي':'Sub'} --</option>`; $('cat3').innerHTML = `<option value="">-- ${window.IS_AR?'دقيق':'Micro'} --</option>`;

    const blds = [...new Map(allLocs.map(l => [l.building_id, getBldName(l)])).entries()];
    $('locBld').innerHTML = `<option value="">-- ${window.IS_AR?'المبنى':'Building'} --</option>` + blds.map(b=>`<option value="${b[0]}">${esc(b[1])}</option>`).join('');
    
    if (curRoom && curRoom.building_id && curRoom.floor_id) {
        $('locBld').value = curRoom.building_id; filterFloor();
        $('locFlr').value = curRoom.floor_id; filterRoom();
        $('locRm').value = curRoom.id;
    } else {
        $('locFlr').innerHTML = `<option value="">-- ${window.IS_AR?'الدور':'Floor'} --</option>`; $('locRm').innerHTML = `<option value="">-- ${window.IS_AR?'الغرفة':'Room'} --</option>`;
    }
    
    VoiceWizard.stop(); 
    document.querySelectorAll('#surplusModal .finp, #surplusModal .opsrow').forEach(el => { el.style.boxShadow = ''; el.style.borderColor = ''; });
    $('manualDropdownsBox').style.boxShadow = '';
    $('manualDropdownsBox').style.background = '';
    
    $('surplusModal').classList.add('show');
}

function filterCat2() {
    const pId = allCategories.find(c=>getCatName(c) === $('cat1').value && c.level==1)?.id;
    const c2 = allCategories.filter(c=>c.parent_id == pId && c.level==2);
    $('cat2').innerHTML = `<option value="">-- ${window.IS_AR?'فرعي':'Sub'} --</option>` + c2.map(c=>`<option value="${esc(getCatName(c))}">${esc(getCatName(c))}</option>`).join('');
    $('cat3').innerHTML = `<option value="">-- ${window.IS_AR?'دقيق':'Micro'} --</option>`;
}
function filterCat3() {
    const pId = allCategories.find(c=>getCatName(c) === $('cat2').value && c.level==2)?.id;
    const c3 = allCategories.filter(c=>c.parent_id == pId && c.level==3);
    $('cat3').innerHTML = `<option value="">-- ${window.IS_AR?'دقيق':'Micro'} --</option>` + c3.map(c=>`<option value="${esc(getCatName(c))}">${esc(getCatName(c))}</option>`).join('');
}
function filterFloor() {
    const bId = $('locBld').value;
    const flrs = [...new Map(allLocs.filter(l=>l.building_id == bId).map(l => [l.floor_id, getFlrName(l)])).entries()];
    $('locFlr').innerHTML = `<option value="">-- ${window.IS_AR?'الدور':'Floor'} --</option>` + flrs.map(f=>`<option value="${f[0]}">${esc(f[1])}</option>`).join('');
    $('locRm').innerHTML = `<option value="">-- ${window.IS_AR?'الغرفة':'Room'} --</option>`;
}
function filterRoom() {
    const fId = $('locFlr').value;
    const rms = allLocs.filter(l=>l.floor_id == fId);
    $('locRm').innerHTML = `<option value="">-- ${window.IS_AR?'الغرفة':'Room'} --</option>` + rms.map(r=>`<option value="${r.room_id||r.id}">${esc(getRoomName(r))}</option>`).join('');
}

async function submitSurplus() {
    const tag = $('surplusTag').value.trim(); const serial = $('surplusSerial').value.trim();
    const descAr = $('surplusDescAr').value.trim(); const descEn = $('surplusDescEn').value.trim();
    const c1 = $('cat1').value; const c2 = $('cat2').value; const c3 = $('cat3').value; const locRm = $('locRm').value;

    const reqInputs = ['surplusTag', 'surplusSerial', 'surplusDescAr', 'surplusDescEn', 'cat1', 'cat2', 'cat3', 'locRm'];
    for (const id of reqInputs) {
        const el = $(id);
        if (!el || el.value.trim() === '') {
            el.classList.add('error-highlight');
            el.scrollIntoView({behavior: 'smooth', block: 'center'});
            setTimeout(() => el.classList.remove('error-highlight'), 2000);
            return;
        }
    }
    
    if (!surplusAssetType) {
        const row = $('surplusAssetTypeRow'); row.classList.add('error-highlight');
        row.scrollIntoView({behavior: 'smooth', block: 'center'});
        setTimeout(() => row.classList.remove('error-highlight'), 2000);
        return;
    }

    if (ARABIC_RE.test(tag) || ARABIC_RE.test(serial)) {
        const field = ARABIC_RE.test(tag) ? $('surplusTag') : $('surplusSerial');
        field.classList.add('error-highlight'); field.focus(); field.select();
        setTimeout(() => field.classList.remove('error-highlight'), 2500);
        alert(window.IS_AR?'الحقل يحتوي على حروف عربية غير مقبولة.':'Field contains invalid Arabic characters.');
        return;
    }

    const payload = {
        session_id: SID, tag_number: tag, serial_number: serial,
        description_ar: descAr, description_en: descEn, asset_type: surplusAssetType,
        cat_level1: c1, cat_level2: c2, cat_level3: c3, location_id: locRm
    };

    try {
        const r = await fetch(`${BASE}/inventory/api/quick_register.php`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
        const j = await r.json();
        if (j.ok) {
            $('surplusModal').classList.remove('show'); alert(window.IS_AR?'تم تسجيل الأصل بنجاح!':'Asset registered successfully!');
            if(!curRoom) loadLocations(); else openRoom(curRoom.id);
        } else { alert('⚠️ ' + (j.message || j.error)); }
    } catch (e) { alert(window.IS_AR?'فشل الاتصال':'Connection failed'); }
}

const VoiceWizard = {
    isActive: false, step: 0, recognition: null, finalText: '', ignoreResults: false,
    steps: window.IS_AR ? [
        { id: 'surplusTag', label: 'رقم التاج', type: 'alphanumeric', lang: 'ar-SA' },
        { id: 'surplusSerial', label: 'السيريال نمبر', type: 'alphanumeric', lang: 'ar-SA' },
        { id: 'assetType', label: 'نوع الجهاز (قل: طبي، تقنية، عام)', type: 'options', lang: 'ar-SA' },
        { id: 'surplusDescAr', label: 'الوصف (عربي)', type: 'text', lang: 'ar-SA' }
    ] : [
        { id: 'surplusTag', label: 'Tag Number', type: 'alphanumeric', lang: 'en-US' },
        { id: 'surplusSerial', label: 'Serial Number', type: 'alphanumeric', lang: 'en-US' },
        { id: 'assetType', label: 'Asset Type (Say: Medical, IT, General)', type: 'options', lang: 'en-US' },
        { id: 'surplusDescEn', label: 'Description (English)', type: 'text', lang: 'en-US' }
    ],
    uiElement: null,

    init() {
        if (!window.hasOwnProperty('webkitSpeechRecognition')) { alert(window.IS_AR?"المتصفح لا يدعم المساعد الصوتي.":"Voice Wizard not supported."); return; }
        this.recognition = new webkitSpeechRecognition();
        this.recognition.continuous = true;
        this.recognition.interimResults = true;
        this.recognition.onstart = () => { this.ignoreResults = false; };
        this.recognition.onresult = (e) => this.handleResult(e);
        this.recognition.onerror = (e) => { 
            if(e.error === 'no-speech') return;
            this.stop(); beep(false); toast(window.IS_AR?"خطأ في المايكروفون":"Mic Error"); 
        };
        this.recognition.onend = () => { 
            if (this.isActive) { try { this.recognition.start(); } catch(e){} } 
        };
    },

    start() {
        if (!this.recognition) this.init();
        if (!this.recognition) return;
        this.isActive = true; this.step = 0; this.finalText = '';
        this.recognition.lang = this.steps[0].lang; 
        this.buildUI(); 
        this.focusCurrentStep();
        try { this.recognition.start(); } catch(e){}
    },

    stop() {
        this.isActive = false;
        try { this.recognition.stop(); } catch(e){}
        if (this.uiElement) this.uiElement.remove();
        document.querySelectorAll('#surplusModal .finp, #surplusModal .opsrow').forEach(el => el.style.boxShadow = '');
    },

    buildUI() {
        if (this.uiElement) this.uiElement.remove();
        this.uiElement = document.createElement('div');
        this.uiElement.style.cssText = 'position:sticky; top:0; background:linear-gradient(135deg, #7c3aed, #4c1d95); color:#fff; padding:12px; border-radius:12px; margin-bottom:15px; box-shadow:0 4px 15px rgba(124,58,237,0.3); z-index:100; text-align:center; font-weight:800; font-size:14px;';
        $('surplusModal').querySelector('.sheet').prepend(this.uiElement);
    },

    focusCurrentStep() {
        if (this.step >= this.steps.length) { this.finish(); return; }
        const s = this.steps[this.step];

        if (this.recognition.lang !== s.lang) {
            this.recognition.lang = s.lang;
            try { this.recognition.stop(); } catch(e){}
        }

        const wizTitle = window.IS_AR ? '🎙️ المساعد يستمع...' : '🎙️ Wizard is listening...';
        const wizField = window.IS_AR ? 'حقل:' : 'Field:';
        const wizHint = window.IS_AR ? 'قل "التالي" للانتقال، أو "امسح" للبدء من جديد' : 'Say "Next" to continue, or "Clear" to clear';

        this.uiElement.innerHTML = `<div style="animation:pulseWiz 1.5s infinite;">${wizTitle}</div><div style="color:#ddd; font-size:12.5px; margin-top:4px;">${wizField} <span style="color:#fde047; font-weight:900">${s.label}</span></div><div style="font-size:11px; margin-top:6px; font-weight:normal; background:rgba(0,0,0,0.2); border-radius:6px; padding:4px;">${wizHint}</div>`;
        
        document.querySelectorAll('#surplusModal .finp, #surplusModal .opsrow').forEach(el => el.style.boxShadow = '');
        let targetEl = s.id === 'assetType' ? $('surplusAssetTypeRow') : $(s.id);
        
        targetEl.style.boxShadow = '0 0 0 4px rgba(124,58,237,0.4)';
        targetEl.scrollIntoView({behavior:'smooth', block:'center'});
        if (s.id !== 'assetType') $(s.id).focus();
    },

    async handleResult(e) {
        if (!this.isActive || window.isSpeakingWarning || this.ignoreResults) { 
            return; 
        }

        let interim = '', final = '';
        for (let i = e.resultIndex; i < e.results.length; i++) {
            if (e.results[i].isFinal) final += e.results[i][0].transcript;
            else interim += e.results[i][0].transcript;
        }
        
        let text = (this.finalText + ' ' + final + ' ' + interim).trim();
        
        const isNext = window.IS_AR ? /(^|\s)(التال[يى]|نكست|نكس|نيكس|next)(?=\s|$)/i.test(text) : /(^|\s)(next)(?=\s|$)/i.test(text);
        const isClear = window.IS_AR ? /(^|\s)(امسح|إمسح|إلغاء|الغاء|كلير)(?=\s|$)/i.test(text) : /(^|\s)(clear)(?=\s|$)/i.test(text);
        const isPrev = window.IS_AR ? /(^|\s)(السابق|تراجع|باك|اندو|أندو)(?=\s|$)/i.test(text) : /(^|\s)(back|undo|previous)(?=\s|$)/i.test(text);

        if (isClear) { this.finalText = ''; this.updateFieldValue(''); beep(false); return; }
        if (isPrev) { 
            this.finalText = ''; if (this.step > 0) this.step--; 
            this.ignoreResults = true; try { this.recognition.stop(); } catch(e){} 
            this.focusCurrentStep(); beep(false); return; 
        }

        let cleanText = window.IS_AR 
            ? text.replace(/(^|\s)(التال[يى]|نكست|نكس|نيكس|next|امسح|إمسح|إلغاء|الغاء|كلير|السابق|تراجع|باك|اندو|أندو)(?=\s|$)/gi, ' ').trim()
            : text.replace(/(^|\s)(next|clear|back|undo|previous)(?=\s|$)/gi, ' ').trim();

        const s = this.steps[this.step];

        if (s.id === 'assetType') {
            let matched = false;
            if (/(^|\s)(طبي|medical)(?=\s|$)/i.test(cleanText)) {
                pickAssetType(document.querySelector('.o-medical'), 'medical'); matched = true;
            } else if (/(^|\s)(تقنية|حاسب|كمبيوتر|لابتوب|آي تي|it)(?=\s|$)/i.test(cleanText)) {
                pickAssetType(document.querySelector('.o-it'), 'it'); matched = true;
            } else if (/(^|\s)(عام|اخرى|أخرى|general|other)(?=\s|$)/i.test(cleanText)) {
                pickAssetType(document.querySelector('.o-general'), 'other'); matched = true;
            }
            
            if (matched || (surplusAssetType && isNext)) {
                this.goToNextStep();
            }
        } else {
            let processed = cleanText;
            if (s.type === 'alphanumeric') processed = normalizeAlphanumeric(cleanText);
            this.updateFieldValue(processed);
            
            if (isNext) {
                if (processed.length > 0) {
                    if (s.id === 'surplusTag' || s.id === 'surplusSerial') {
                        let isDup = await checkLiveDupSurplus();
                        if (isDup) { this.finalText = ''; this.updateFieldValue(''); return; }
                    } else if (s.id === 'surplusDescAr') {
                        smartTranslate(processed, 'surplusDescAr', 'surplusDescEn');
                    } else if (s.id === 'surplusDescEn') {
                        smartTranslate(processed, 'surplusDescEn', 'surplusDescAr');
                    }
                    this.goToNextStep();
                } else {
                    beep(false); toast(window.IS_AR?"الحقل فارغ، قل النص أولاً":"Field empty, speak first"); this.finalText = '';
                }
            } else {
                if (final) this.finalText += ' ' + final;
            }
        }
    },
    
    updateFieldValue(val) {
        const s = this.steps[this.step];
        if (s.id !== 'assetType') $(s.id).value = val;
    },

    goToNextStep() {
        beep(true); 
        this.finalText = ''; 
        this.step++; 
        this.ignoreResults = true; 
        try { this.recognition.stop(); } catch(e){} 
        this.focusCurrentStep();
    },

    finish() {
        this.stop();
        $('manualDropdownsBox').style.boxShadow = '0 0 0 4px rgba(234, 179, 8, 0.4)';
        $('manualDropdownsBox').style.background = '#fffbeb';
        $('manualDropdownsBox').scrollIntoView({behavior:'smooth', block:'center'});
        
        if ('speechSynthesis' in window) {
            window.isSpeakingWarning = true;
            const msg = new SpeechSynthesisUtterance();
            if (window.IS_AR) {
                msg.text = "تم تسجيل البيانات بنجاح. الرجاء تحديد التصنيف والموقع من القوائم لإتمام الحفظ.";
                msg.lang = 'ar-SA';
            } else {
                msg.text = "Data recorded successfully. Please select the location and category.";
                msg.lang = 'en-US';
            }
            msg.rate = 1.0;
            msg.onend = () => { window.isSpeakingWarning = false; };
            msg.onerror = () => { window.isSpeakingWarning = false; };
            window.speechSynthesis.speak(msg);
        }
    }
};

loadLocations();
</script>
</body>
</html>
<?php endif; ?>