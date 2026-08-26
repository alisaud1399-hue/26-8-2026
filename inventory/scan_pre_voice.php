<?php
/**
 * inventory/scan.php — المسح الميداني للجرد (Enterprise - Full Version)
 * ✅ بوابة قفل صارمة: فقط الجلسات النشطة
 * ✅ معالجة البصمة الفارغة (أول جرد)
 * ✅ تضمين قفل الغرفة الذكي
 */
require_once dirname(__DIR__) . '/config.php';
if (!can('inventory.scan', 'view') && !can('inventory.create', 'manage')) abort(403);

$ui_lang = $_GET['lang'] ?? $_SESSION['lang'] ?? (is_rtl() ? 'ar' : 'en');
$is_ar = ($ui_lang === 'ar');
$rtl = $is_ar;
$session_id = (int)($_GET['session'] ?? $_POST['session'] ?? 0);
$is_admin = can('inventory.create', 'manage');

// حارس العضوية
if ($session_id > 0 && !inv_session_guard($session_id)) {
    log_activity('inventory.scan.denied', 'session:' . $session_id, 'user_not_member');
    flash('warning', $is_ar ? 'أنت لست عضواً في لجنة الجرد لهذه الجلسة.' : 'You are not a member of this session\'s committee.');
    redirect('/inventory/index.php');
}

$session = null; $total_scope = 0; $done_scope = 0; $filter_depts = [];
$my_team_names = [];
if ($session_id) {
    $st = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
    $st->execute([$session_id]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if ($session && !is_admin() && !can_see_all()) {
        $tt = $pdo->prepare("SELECT t.name FROM inventory_session_teams t JOIN inventory_session_team_members tm ON tm.team_id=t.id WHERE t.session_id=? AND tm.user_id=?");
        $tt->execute([$session_id, current_user()['id']]);
        $my_team_names = $tt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($session) {
        $tt = $pdo->prepare("SELECT t.name FROM inventory_session_teams t WHERE t.session_id=?");
        $tt->execute([$session_id]);
        $my_team_names = $tt->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!$session) {
        flash('error', $is_ar ? 'الجلسة غير موجودة.' : 'Session not found.');
        header('Location: ' . BASE_URL . '/inventory/index.php'); exit;
    }
    // ★ بوابة القفل: فقط "active" يسمح بالمسح
    if ($session['status'] !== 'active') {
        $LOCK = [
            'planning'  => $is_ar ? 'الجلسة لم تُفعَّل بعد — المسح غير متاح.' : 'Session not activated yet.',
            'review'    => $is_ar ? 'الجلسة موقوفة للمراجعة — المسح مقفل.' : 'Session paused for review.',
            'completed' => $is_ar ? 'الجلسة مكتملة ومغلقة — لا يمكن المسح.' : 'Session completed & closed.',
            'cancelled' => $is_ar ? 'الجلسة ملغاة.' : 'Session cancelled.',
        ][$session['status']] ?? ($is_ar ? 'الجلسة غير نشطة.' : 'Session inactive.');
        $ST_LBL = $is_ar ? ['planning'=>'تحت التخطيط','review'=>'قيد المراجعة','completed'=>'مكتملة','cancelled'=>'ملغاة'] : ['planning'=>'Planning','review'=>'Under Review','completed'=>'Completed','cancelled'=>'Cancelled'];
        ?>
<!DOCTYPE html><html lang="<?= $is_ar?'ar':'en' ?>" dir="<?= $is_ar?'rtl':'ltr' ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $is_ar?'المسح مقفل':'Scan Locked' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>*{font-family:'Tajawal',sans-serif;box-sizing:border-box}body{background:#f8fafc;margin:0}
.lb-top{background:linear-gradient(135deg,#0f2545,#1a3a6b);color:#fff;padding:14px 16px;font-weight:800;font-size:14px}
.lb-wrap{max-width:520px;margin:24px auto;padding:0 14px}
.lb-alert{background:#fef2f2;border:2px solid #fca5a5;border-radius:16px;padding:16px;color:#991b1b;font-weight:800;font-size:14px;display:flex;gap:12px;align-items:flex-start}
.lb-alert i{font-size:22px;color:#dc2626;flex-shrink:0}
.lb-badge{display:inline-block;margin-top:14px;background:#fff;border:1.5px solid #e2e8f0;border-radius:99px;padding:6px 16px;font-size:12.5px;font-weight:800;color:#475569}
.lb-btn{display:block;margin-top:18px;background:#1565C0;color:#fff;text-align:center;text-decoration:none;border-radius:14px;padding:14px;font-weight:800;font-size:14px}
/* ═══ نموذج الجهاز المطوّر ═══ */
.dev-hero{display:flex;gap:12px;align-items:center;background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:18px;padding:14px;color:#fff;margin-bottom:12px;box-shadow:0 6px 18px rgba(15,37,69,.25)}
.dev-crit{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-weight:900;font-size:18px;background:rgba(255,255,255,.15);color:#fff;flex-shrink:0}
.dev-id{flex:1;min-width:0}.dev-id #dName{font-weight:800;font-size:14.5px}.dev-id #dTag{font-size:11.5px;opacity:.8;font-family:Inter,monospace}
.dev-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
.dchip{background:rgba(255,255,255,.15);border-radius:99px;padding:2px 9px;font-size:10.5px;font-weight:700}
.dev-voice{width:46px;height:46px;border-radius:50%;border:none;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:18px;cursor:pointer;flex-shrink:0}
.dev-voice.on{background:linear-gradient(135deg,#dc2626,#ef4444);animation:pulse 1.2s infinite}
.voice-bar{display:flex;align-items:center;gap:8px;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:12px;padding:8px 12px;margin-bottom:12px;font-size:12px;font-weight:700;color:#6d28d9}
.vb-dot{width:9px;height:9px;border-radius:50%;background:#dc2626;animation:pulse 1s infinite;flex-shrink:0}
.voice-bar button{margin-inline-start:auto;border:none;background:#7c3aed;color:#fff;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit}
.serial-row{display:flex;gap:6px}.serial-row input{flex:1}
.iconbtn{width:44px;border:1.5px solid var(--line);background:#fff;border-radius:12px;font-size:16px;cursor:pointer;color:var(--navy);flex-shrink:0}
.iconbtn.wide{width:100%;margin-top:6px;font-size:12px;font-weight:800;height:38px}
.loc-warn{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:8px 10px;font-size:11.5px;font-weight:700;color:#92400e;margin-top:8px}
</style></head><body>
<div class="lb-top"><i class="fa-solid fa-lock"></i> <?= e($session['title'] ?? '') ?> — <?= e($session['session_code'] ?? '') ?></div>
<div class="lb-wrap">
<div class="lb-alert"><i class="fa-solid fa-triangle-exclamation"></i><div><?= e($LOCK) ?></div></div>
<span class="lb-badge"><i class="fa-solid fa-circle-info"></i> <?= $is_ar ? 'حالة الجلسة: ' : 'Status: ' ?><?= e($ST_LBL[$session['status']] ?? $session['status']) ?></span>
<a class="lb-btn" href="<?= BASE_URL ?>/inventory/session.php?id=<?= $session_id ?>"><i class="fa-solid fa-arrow-right"></i> <?= $is_ar ? 'العودة لصفحة الجلسة' : 'Back to session' ?></a>
</div></body></html>
<?php exit; }
    $total_scope = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status NOT IN ('disposed','returned_to_supplier') AND location_id IS NOT NULL")->fetchColumn();
    $dq = $pdo->prepare("SELECT COUNT(DISTINCT asset_id) FROM inventory_audits WHERE session_id = ? AND asset_id IS NOT NULL");
    $dq->execute([$session_id]);
    $done_scope = (int)$dq->fetchColumn();
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
.lang-toggle { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; padding: 4px 10px; font-size: 11px; font-weight: 800; cursor: pointer; text-decoration: none; margin-inline-start: 8px; }
.wrap{padding:12px 14px;}
.card{background:#fff;border:1px solid var(--line);border-radius:16px; padding:16px;margin-bottom:12px; box-shadow:0 2px 6px rgba(15,23,42,0.03);}
h3.sec{font-size:14px;margin:6px 2px 10px;color:var(--navy); font-weight:800;}
.hint{font-size:11.5px;color:var(--muted);font-weight:500;}
.screen{display:none;} .screen.on{display:block;animation:fadeIn .3s cubic-bezier(0.16, 1, 0.3, 1);}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:none;}}
.btn{border-radius:12px;padding:12px;font-weight:800;font-size:13px;cursor:pointer;border:none; transition:0.2s; display:inline-flex; align-items:center; justify-content:center; gap:6px;}
.btn:active{transform:scale(0.97);}
.btn-g{background:linear-gradient(135deg, var(--green), #15803d); color:#fff; box-shadow:0 2px 8px rgba(22,163,74,0.25);}
.btn-o{background:#fff;border:1.5px solid var(--line);color:var(--navy);}
.loading{text-align:center;color:var(--muted);padding:36px 0;}
.loading i{font-size:24px;display:block;margin-bottom:8px;animation:spin 1s linear infinite; color:var(--blue);}
.searchrow{display:flex;gap:8px;margin-bottom:12px;}
.searchrow input{flex:1;border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;font-size:13.5px; transition:0.2s;}
.cambtn{background:var(--navy);color:#fff;border:none;border-radius:14px;width:50px;font-size:18px;cursor:pointer;}
.alert{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:14px;padding:12px 14px;font-size:12.5px;color:#991b1b;margin-bottom:12px;display:none;}
.alert.show{display:block; animation:fadeIn 0.3s;}
.modal{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:flex-end;justify-content:center;z-index:999;}
.modal.show{display:flex; animation:fadeIn 0.2s;}
.sheet{background:#fff;border-radius:24px 24px 0 0;padding:24px 18px 32px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;}
.finp { width:100%; border:1.5px solid var(--line); border-radius:12px; padding:12px; font-size:13px; margin-bottom:14px; background:#fcfcfc;}
.locbtn{width:100%;background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;margin-bottom:10px;cursor:pointer;display:flex;align-items:center;gap:14px;text-align:start;}
.locbtn .ic{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg, #eff6ff, #dbeafe);color:var(--blue);display:grid;place-items:center;font-size:18px;flex-shrink:0;}
.locbtn .nm{font-weight:800;font-size:14px; margin-bottom:2px;}
.locbtn .pt{font-size:11.5px;color:var(--muted);}
.locbtn .cnt{margin-inline-start:auto;text-align:center;flex-shrink:0; background:#f1f5f9; padding:6px 12px; border-radius:10px;}
.locbtn .cnt b{display:block;font-size:15px;color:var(--navy);}
.locbtn .cnt span{font-size:10px;color:var(--green);font-weight:800;}
.locbtn.fp{border-color:#bae6fd;background:linear-gradient(180deg,#f4f9ff,#fff);}
.fpbadge{background:var(--blue);color:#fff;border-radius:12px;font-size:10px;font-weight:800;padding:3px 8px;}
.roomhead{background:linear-gradient(135deg,#eff6ff,#f0fdfa);border:1.5px solid #bae6fd;border-radius:16px;padding:16px;margin-bottom:16px;}
.rl-lockbar{background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border-radius:12px;padding:8px 12px;font-size:12px;font-weight:800;margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.rlb-btn{background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:8px;padding:6px 10px;font-size:14px;cursor:pointer}
.assetcard{width:100%;background:#fff;border:1.5px solid var(--line);border-radius:14px;padding:14px;margin-bottom:10px;display:flex;align-items:center;gap:12px;text-align:start;cursor:pointer;}
.assetcard.done{background:linear-gradient(135deg, #f0fdf4, #fff);border-color:#bbf7d0;}
.assetcard.miss{background:linear-gradient(135deg, #fef2f2, #fff);border-color:#fecaca;}
.crit{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:17px;flex-shrink:0;}
.crit.A{background:#fee2e2;color:#b91c1c;} .crit.B{background:#fef3c7;color:#b45309;} .crit.C{background:#f1f5f9;color:#64748b;}
.assetcard .stx{margin-inline-start:auto;font-size:20px;flex-shrink:0;}
.savebar{position:fixed;bottom:0;inset-inline:0;max-width:520px;margin-inline:auto;background:rgba(255,255,255,0.95); backdrop-filter:blur(10px); border-top:1.5px solid var(--line);padding:14px 16px;display:none;gap:10px;z-index:30;}
.savebar.show{display:flex;}
.savebar .next{flex:1;background:var(--blue);color:#fff;border:none;border-radius:14px;padding:14px;font-weight:800;font-size:15px;cursor:pointer;}
.savebar .next:disabled{background:#cbd5e1;cursor:not-allowed;}
.savebar .miss{background:#fff;border:2px solid var(--red);color:var(--red);border-radius:14px;padding:14px 16px;font-weight:800;font-size:13px;cursor:pointer;}
.opsbtn{flex:1;border:1.5px solid var(--line);background:#f8fafc;border-radius:14px;padding:14px 4px;font-weight:800;font-size:12px;cursor:pointer;color:var(--text2);}
.opsbtn .em{display:block;font-size:20px;margin-bottom:4px;}
.opsbtn.sel{color:#fff; transform:translateY(-2px);}
.opsbtn.sel.o-active{background:linear-gradient(135deg, var(--green), #15803d);border-color:transparent;}
.opsbtn.sel.o-maint{background:linear-gradient(135deg, var(--orange), #c2410c);border-color:transparent;}
.opsbtn.sel.o-out{background:linear-gradient(135deg, #334155, #0f172a);border-color:transparent;}
.health{display:flex;gap:6px;}
.hbtn{flex:1;border:1.5px solid var(--line);border-radius:12px;padding:12px 2px;background:#f8fafc;color:var(--text2);font-weight:800;font-size:11px;cursor:pointer;text-align:center;}
.hbtn.sel{color:#fff;outline:none;transform:translateY(-2px);border-color:transparent;}
.h5.sel{background:var(--green);} .h4.sel{background:var(--green-l); color:var(--navy);} .h3x.sel{background:#eab308; color:var(--navy);} .h2x.sel{background:var(--orange);} .h1x.sel{background:var(--red);}
#toast{position:fixed;bottom:104px;inset-inline:0;max-width:360px;margin-inline:auto;background:var(--navy);color:#fff;border-radius:14px;padding:14px 16px;font-size:13.5px;font-weight:700;text-align:center;opacity:0;pointer-events:none;transition:.3s;z-index:9999;}
#toast.show{opacity:1; transform:translateY(-10px);}

/* ═══ شاشة تسجيل دخول غرفة الجرد (Modern) ═══ */
.qr-hero{text-align:center;padding:24px 8px 20px;animation:fadeInUp .6s cubic-bezier(.16,1,.3,1);}
.qr-orb{width:84px;height:84px;border-radius:24px;margin:0 auto 14px;background:linear-gradient(135deg,var(--blue),#0284c7);color:#fff;display:grid;place-items:center;font-size:36px;box-shadow:0 12px 30px rgba(21,101,192,.25),inset 0 -8px 16px rgba(0,0,0,.1);animation:float 4s ease-in-out infinite;}
.qr-hero h1{font-size:19px;font-weight:800;color:var(--navy);margin:0 0 4px;letter-spacing:-.3px}
.qr-hero p{font-size:12.5px;color:var(--text2);margin:0;line-height:1.5}

.qr-scan-card{background:#fff;border:1.5px solid var(--line);border-radius:22px;padding:20px;margin-bottom:18px;box-shadow:0 4px 20px rgba(15,23,42,.04);animation:fadeInUp .6s .1s cubic-bezier(.16,1,.3,1) both;}
.qr-scan-btn{position:relative;width:100%;padding:18px;border:none;border-radius:16px;background:linear-gradient(135deg,var(--green),#15803d);color:#fff;font-weight:800;font-size:15.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-family:inherit;overflow:hidden;box-shadow:0 6px 18px rgba(22,163,74,.28);transition:transform .2s;}
.qr-scan-btn:active{transform:scale(.97);}
.qr-scan-btn i{font-size:22px;}
.qr-scan-btn .pulse-ring{position:absolute;inset:-4px;border-radius:18px;border:2px solid rgba(34,197,94,.5);animation:pulse 2s ease-out infinite;pointer-events:none;}
.qr-scan-btn.scanning{background:linear-gradient(135deg,#dc2626,#ef4444);box-shadow:0 6px 18px rgba(220,38,38,.3);}
.qr-scan-btn.scanning .pulse-ring{border-color:rgba(239,68,68,.5);animation:pulse 1s ease-out infinite;}

.qr-divider{display:flex;align-items:center;gap:10px;margin:18px 0 12px;color:var(--muted);font-size:11.5px;font-weight:700;}
.qr-divider::before,.qr-divider::after{content:'';flex:1;height:1px;background:var(--line);}
.qr-divider span{padding:0 6px;}

.qr-manual{display:flex;gap:8px;align-items:stretch;}
.qr-manual input{flex:1;border:1.5px solid var(--line);border-radius:14px;padding:14px 16px;font-size:14.5px;font-weight:700;font-family:monospace;background:#fcfcfc;color:var(--navy);text-align:center;letter-spacing:.5px;transition:.2s;}
.qr-manual input:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(21,101,192,.12);}
.qr-manual input::placeholder{color:var(--muted);font-weight:500;font-family:'Tajawal',sans-serif;letter-spacing:normal;}
.qr-manual button{background:var(--navy);color:#fff;border:none;border-radius:14px;width:54px;font-size:18px;cursor:pointer;transition:transform .2s;}
.qr-manual button:active{transform:scale(.92);}

.qr-cam{border:2px solid var(--navy);border-radius:18px;overflow:hidden;margin-bottom:18px;animation:fadeInUp .4s;}

.qr-cam{border:2px solid var(--navy);border-radius:18px;overflow:hidden;margin-bottom:18px;animation:fadeInUp .4s;}

/* ═══ قائمة اختيار غرفة الجرد ═══ */
.picker-section{animation:fadeInUp .6s .2s cubic-bezier(.16,1,.3,1) both;background:#fff;border:1.5px solid var(--line);border-radius:22px;padding:18px;box-shadow:0 4px 20px rgba(15,23,42,.04);}
.picker-head{display:flex;align-items:center;gap:8px;margin:0 0 14px;}
.picker-head i{color:var(--blue);font-size:15px;}
.picker-head h2{font-size:14px;font-weight:800;color:var(--navy);margin:0;letter-spacing:-.2px;}

.picker-building,.picker-floors{position:relative;margin-bottom:12px;}
.picker-building i,.picker-floors i{position:absolute;inset-inline-start:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;z-index:1;}
.picker-building select,.picker-floors select{width:100%;padding:14px 16px 14px 42px;border:1.5px solid var(--line);border-radius:14px;font-size:14px;font-weight:700;font-family:inherit;background:#fcfcfc;color:var(--navy);cursor:pointer;appearance:none;transition:.2s;
  background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'><path fill='%2364748b' d='M6 8L2 4h8z'/></svg>");background-repeat:no-repeat;background-position:calc(100% - 16px) 50%;}
.picker-building select:focus,.picker-floors select:focus{outline:none;border-color:var(--blue);background-color:#fff;box-shadow:0 0 0 3px rgba(21,101,192,.12);}

.picker-rooms{margin-top:8px;max-height:380px;overflow-y:auto;padding-inline:2px;}
.picker-rooms::-webkit-scrollbar{width:4px;}
.picker-rooms::-webkit-scrollbar-thumb{background:var(--line);border-radius:4px;}

.room-item{width:100%;background:#fff;border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px;text-align:start;cursor:pointer;transition:.2s;font-family:inherit;animation:fadeInUp .4s cubic-bezier(.16,1,.3,1) both;}
.room-item:nth-child(1){animation-delay:.04s}
.room-item:nth-child(2){animation-delay:.08s}
.room-item:nth-child(3){animation-delay:.12s}
.room-item:nth-child(4){animation-delay:.16s}
.room-item:nth-child(5){animation-delay:.20s}
.room-item:hover{border-color:var(--blue);background:linear-gradient(180deg,#f4f9ff,#fff);transform:translateY(-1px);box-shadow:0 4px 12px rgba(21,101,192,.08);}
.room-item:active{transform:scale(.98);}
.room-item .ic{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#eff6ff,#dbeafe);color:var(--blue);display:grid;place-items:center;font-size:15px;flex-shrink:0;}
.room-item .nm{font-weight:800;font-size:13.5px;color:var(--ink);line-height:1.3;flex:1;min-width:0;}
.room-item .pt{font-size:11px;color:var(--muted);margin-top:2px;font-weight:600;}
.room-item .pr{font-size:11.5px;font-weight:800;color:var(--text2);background:#f1f5f9;padding:6px 10px;border-radius:8px;flex-shrink:0;text-align:center;line-height:1.2;}
.room-item .pr b{color:var(--navy);display:block;font-size:13px;}
.room-item.done .pr{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#166534;}
.room-item.done .pr b{color:#16a34a;}

.empty-state{text-align:center;padding:30px 14px;color:var(--muted);font-size:12.5px;font-weight:600;}
.empty-state i{display:block;font-size:32px;margin-bottom:8px;color:#cbd5e1;}

@keyframes pulse{0%{opacity:.7;transform:scale(1);}100%{opacity:0;transform:scale(1.15);}}
@keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-4px);}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-6px);}75%{transform:translateX(6px);}}
.qr-manual.shake input{animation:shake .35s;border-color:var(--red);background:#fef2f2;}

.locbtn{animation:fadeInUp .5s cubic-bezier(.16,1,.3,1) both;transition:.2s;}
.locbtn:nth-child(1){animation-delay:.05s}
.locbtn:nth-child(2){animation-delay:.1s}
.locbtn:nth-child(3){animation-delay:.15s}
.locbtn:nth-child(4){animation-delay:.2s}
.locbtn:nth-child(5){animation-delay:.25s}
.locbtn:active{transform:scale(.98);}

@media(max-width:380px){
  .qr-hero{padding:18px 4px 14px}
  .qr-orb{width:72px;height:72px;font-size:30px}
  .qr-hero h1{font-size:17px}
  .qr-scan-card{padding:16px}
  .qr-scan-btn{padding:16px;font-size:14.5px}
}
</style>
</head>
<body>
<div class="topbar">
<div class="ring" id="ring"><b id="ringTxt">—</b></div>
<div>
<div class="t1"><?= e($session ? ($session['title'] . ' — ' . $session['session_code']) : ($is_ar ? 'المسح الميداني' : 'Field Scan')) ?></div>
<div class="t2" id="topSub"><?= $is_ar ? 'حدّد موقعك للبدء' : 'Select location to begin' ?></div>
<?php if (!empty($my_team_names)): ?>
<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
<?php foreach ($my_team_names as $tn): ?>
<span style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:99px;padding:1px 10px;font-size:10.5px;font-weight:800;color:#e0f2fe;"><i class="fa-solid fa-users" style="margin-left:3px;font-size:9px;"></i><?= e($tn) ?></span>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<a href="?session=<?= $session_id ?>&lang=<?= $is_ar ? 'en' : 'ar' ?>" class="lang-toggle"><i class="fa-solid fa-globe"></i> <?= $is_ar ? 'English' : 'عربي' ?></a>
<button class="backbtn" id="backBtn" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i> <?= $is_ar ? 'رجوع' : 'Back' ?></button>
</div>

<?php if ($session): ?>
<div class="screen on" id="scrLoc">
<div class="wrap">

<!-- ═══ شاشة تسجيل دخول غرفة الجرد ═══ -->
<div class="qr-hero">
  <div class="qr-orb"><i class="fa-solid fa-door-open"></i></div>
  <h1><?= $is_ar ? 'تسجيل دخول غرفة الجرد' : 'Room Check-In' ?></h1>
  <p><?= $is_ar ? 'امسح باركود الغرفة أو اختر المبنى ثم الغرفة' : 'Scan the room QR or pick a building then a room' ?></p>
</div>

<!-- بطاقة المسح -->
<div class="qr-scan-card">
  <button class="qr-scan-btn" id="rlScanBtn" type="button" onclick="rlScanRoom()">
    <div class="pulse-ring"></div>
    <i class="fa-solid fa-qrcode"></i>
    <span><?= $is_ar ? 'مسح QR الغرفة' : 'Scan Room QR' ?></span>
  </button>
  <div class="qr-divider"><span><?= $is_ar ? 'أو' : 'OR' ?></span></div>
  <form class="qr-manual" id="rlManualForm" onsubmit="return rlSubmitCode(event)">
    <input type="text" id="rlManualCode" placeholder="<?= $is_ar ? 'أدخل كود الغرفة يدوياً…' : 'Enter room code manually…' ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" dir="ltr">
    <button type="submit"><i class="fa-solid fa-arrow-left"></i></button>
  </form>
</div>

<!-- بطاقة الكاميرا (تظهر عند الطلب) -->
<div id="rlCamBox" class="qr-cam" style="display:none"><div id="rlQr"></div></div>
<div id="globalCameraBox" style="display:none"><div id="globalQrReader"></div></div>

<!-- قائمة اختيار غرفة الجرد -->
<div class="picker-section">
  <div class="picker-head">
    <i class="fa-solid fa-sitemap"></i>
    <h2><?= $is_ar ? 'اختر غرفة الجرد' : 'Pick a room' ?></h2>
  </div>
  <div class="picker-building">
    <i class="fa-solid fa-building"></i>
    <select id="bldSel" onchange="onBldChange()">
      <option value=""><?= $is_ar ? '— اختر مبنى —' : '— Pick a building —' ?></option>
    </select>
  </div>
  <div id="floorNav" class="picker-floors" style="display:none">
    <i class="fa-solid fa-layer-group"></i>
    <select id="flrSel" onchange="onFlrChange()">
      <option value=""><?= $is_ar ? '— كل الطوابق —' : '— All floors —' ?></option>
    </select>
  </div>
  <div id="roomNav" class="picker-floors" style="display:none">
<i class="fa-solid fa-door-open"></i>
<select id="roomSel" onchange="onRoomChange()">
<option value=""><?= $is_ar ? '— اختر غرفة (موثّقة) —' : '— Verified room —' ?></option>
</select>
</div>
</div>

</div>
</div>

<div class="screen" id="scrRoom">
<div class="wrap">

<!-- إحصائيات المستخدم السريعة -->
<div style="background:var(--card); border:1px solid var(--border); border-radius:16px; padding:12px; margin-bottom:12px; display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
    <div class="kpi" style="min-width:120px;">
        <div class="kpi-icon teal"><i class="fa-solid fa-user"></i></div>
        <div class="v eng" id="userScannedCount">0</div>
        <div class="l" style="font-size:11px;"><?= $is_ar ? 'الأجهزة المجردة' : 'Devices Scanned' ?></div>
    </div>
    <div class="kpi" style="min-width:120px;">
        <div class="kpi-icon amber"><i class="fa-solid fa-plus"></i></div>
        <div class="v eng" id="userNewCount">0</div>
        <div class="l" style="font-size:11px;"><?= $is_ar ? 'جديدة' : 'New' ?></div>
    </div>
    <div class="kpi" style="min-width:120px;">
        <div class="kpi-icon blue"><i class="fa-solid fa-stopwatch"></i></div>
        <div class="v eng" id="timerText">0:00</div>
        <div class="l" style="font-size:11px;"><?= $is_ar ? 'المدة' : 'Time' ?></div>
    </div>
</div>

<!-- شريط خطوات الجرد — مخفي في شاشة الغرفة (يظهر فقط في شاشة الجهاز) -->
<div class="progress-card" style="margin-bottom:12px; position: sticky; top: 10px; z-index: 99; background:var(--card); display:none;">
    <div class="label" style="direction:rtl; font-weight:800; font-size:12px;"><?= $is_ar ? 'خطوات الجرد' : 'Audit Steps' ?></div>
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px; background:#f8fafc; padding:8px; border-radius:8px;">
        <div class="step-item" id="step1" style="background:var(--border); padding:8px; border-radius:8px; text-align:center; color:var(--muted); transition:background .3s;">
            <div style="font-size:18px; margin-bottom:2px;">1</div>
            <div style="font-size:10px;"><?= $is_ar ? 'نوع الجهاز' : 'Type' ?></div>
        </div>
        <div class="step-item" id="step2" style="background:var(--border); padding:8px; border-radius:8px; text-align:center; color:var(--muted); transition:background .3s;">
            <div style="font-size:18px; margin-bottom:2px;">2</div>
            <div style="font-size:10px;"><?= $is_ar ? 'السيريال نمر' : 'Serial' ?></div>
        </div>
        <div class="step-item" id="step3" style="background:var(--border); padding:8px; border-radius:8px; text-align:center; color:var(--muted); transition:background .3s;">
            <div style="font-size:18px; margin-bottom:2px;">3</div>
            <div style="font-size:10px;"><?= $is_ar ? 'الحالة الفنية' : 'Condition' ?></div>
        </div>
        <div class="step-item" id="step4" style="background:var(--border); padding:8px; border-radius:8px; text-align:center; color:var(--muted); transition:background .3s;">
            <div style="font-size:18px; margin-bottom:2px;">4</div>
            <div style="font-size:10px;"><?= $is_ar ? 'تأكيد الموقع' : 'Location' ?></div>
        </div>
    </div>
    <div style="margin-top:6px; text-align:center; font-size:10px; color:var(--muted);" id="stepStatus"><span id="stepStatusValue">0 <?= $is_ar ? 'من 4 خطوات' : 'of 4 steps' ?></span></div>
</div>

<!-- أزرار التحكم القديمة — مخفية (النظام الحديث في شريط القفل) -->
<div style="display:none">
    <button class="btn-o" id="btnPause" onclick="triggerPause()" style="padding:8px 14px; font-size:11px;"><?= $is_ar ? 'إيقاف' : 'Pause' ?></button>
    <button class="btn-o" id="btnExit" onclick="triggerExit()" style="padding:8px 14px; font-size:11px;"><?= $is_ar ? 'خروج' : 'Exit' ?></button>
</div>

<!-- معلومات الغرفة مدمجة في شريط القفل (modernLockBar) -->
<div class="roomhead" style="display:none;">
<div style="font-weight:800;font-size:16px;color:var(--navy);" id="roomName">—</div>
<div style="font-size:12.5px;color:var(--text2);" id="roomPath">—</div>
</div>
<div class="searchrow">
<input type="text" id="searchIn" placeholder="<?= $is_ar ? '🔍 مسح التاج...' : '🔍 Scan Tag...' ?>" onkeydown="if(event.key==='Enter') doLookup(this.value)">
<button class="cambtn" onclick="toggleCamera('room')"><i class="fa-solid fa-camera"></i></button>
</div>
<div id="cameraBox" style="display:none;"><div id="qrReader"></div></div>
<div id="assetList"></div>
</div>
</div>

<div class="screen" id="scrDevice">
<div class="wrap">
<div class="card" id="idCard">
<div class="crit B" id="dCrit">—</div>
<div style="flex:1"><div id="dName" style="font-weight:800;">—</div><div id="dTag" style="font-size:12px;color:var(--text2);">—</div></div>
</div>
<h3 class="sec">⚙️ <?= $is_ar ? 'الحالة العامة' : 'General Status' ?></h3>
<div class="card">
<div style="display:flex;gap:8px;">
<button class="opsbtn o-active" data-v="active" onclick="pickOps(this,'<?= $is_ar ? 'نشط' : 'Active' ?>')"><span class="em">🟢</span><?= $is_ar ? 'نشط' : 'Active' ?></button>
<button class="opsbtn o-maint" data-v="under_maintenance" onclick="pickOps(this,'<?= $is_ar ? 'صيانة' : 'Maint.' ?>')"><span class="em">🛠️</span><?= $is_ar ? 'صيانة' : 'Maint.' ?></button>
<button class="opsbtn o-out" data-v="inactive" onclick="pickOps(this,'<?= $is_ar ? 'خارج الخدمة' : 'Inactive' ?>')"><span class="em">⚫</span><?= $is_ar ? 'خارج الخدمة' : 'Inactive' ?></button>
</div>
</div>
<h3 class="sec">🔧 <?= $is_ar ? 'الحالة الفنية' : 'Condition' ?></h3>
<div class="card">
<div class="health">
<button class="hbtn h5" data-v="100" onclick="pickH(this,'ممتاز')"><?= $is_ar ? 'ممتاز' : 'Excellent' ?></button>
<button class="hbtn h4" data-v="80" onclick="pickH(this,'جيد')"><?= $is_ar ? 'جيد' : 'Good' ?></button>
<button class="hbtn h3x" data-v="60" onclick="pickH(this,'مقبول')"><?= $is_ar ? 'مقبول' : 'Fair' ?></button>
<button class="hbtn h2x" data-v="40" onclick="pickH(this,'صيانة')"><?= $is_ar ? 'صيانة' : 'Repair' ?></button>
<button class="hbtn h1x" data-v="20" onclick="pickH(this,'ضعيف')"><?= $is_ar ? 'ضعيف' : 'Poor' ?></button>
</div>
</div>
<h3 class="sec">📋 <?= $is_ar ? 'السيريال' : 'Serial' ?></h3>
<div class="card">
<input type="text" id="serialIn" class="finp" style="direction:ltr;font-family:monospace;" placeholder="<?= $is_ar ? 'السيريال نمبر' : 'Serial Number' ?>">
<textarea id="notesIn" class="finp" placeholder="<?= $is_ar ? 'ملاحظات (اختياري)' : 'Notes (optional)' ?>"></textarea>
</div>
<h3 class="sec">📍 <?= $is_ar ? 'الموقع' : 'Location' ?></h3>
<div class="card" id="smartLoc">
<div id="dLoc">—</div>
<button class="btn btn-g" onclick="confirmLoc()" style="width:100%;margin-top:10px;"><i class="fa-solid fa-check"></i> <?= $is_ar ? 'تأكيد الموقع' : 'Confirm Location' ?></button>
</div>
<div id="devInfo"></div>
<div class="alert" id="saveErr" style="display:none"></div>
</div>
</div>

<div class="savebar" id="savebar">
<button class="next" id="nextBtn" onclick="submitConfirm()"><?= $is_ar ? 'حفظ ←' : 'Save →' ?></button>
<button class="miss" onclick="submitMiss('missing')"><?= $is_ar ? 'مفقود ✗' : 'Missing ✗' ?></button>
</div>

<div id="toast"></div>

<!-- ═══ نافذة طلب استثناء إعادة الجرد ═══ -->
<div class="modal" id="reauditModal" onclick="if(event.target===this)closeReauditModal()">
<div style="background:#fff;border-radius:18px;padding:24px;width:90%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,.15)">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
<h3 style="margin:0;font-size:16px;color:#9a3412"><i class="fa-solid fa-flag"></i> <?= $is_ar ? 'طلب استثناء إعادة الجرد' : 'Request Re-audit Exception' ?></h3>
<button onclick="closeReauditModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#94a3b8">✕</button>
</div>
<p style="font-size:12.5px;color:#64748b;margin:0 0 12px"><?= $is_ar ? 'اكتب سبب طلب إعادة جرد هذا الجهاز — يذهب للمدير للاعتماد.' : 'Describe why you need to re-audit this device — sent to admin for approval.' ?></p>
<textarea id="reauditReason" style="width:100%;min-height:80px;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-family:inherit;font-size:13px;resize:vertical" placeholder="<?= $is_ar ? 'السبب...' : 'Reason...' ?>"></textarea>
<div style="display:flex;gap:8px;margin-top:12px">
<button onclick="submitReauditRequest()" style="flex:1;background:#ea580c;color:#fff;border:none;padding:10px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit"><i class="fa-solid fa-paper-plane"></i> <?= $is_ar ? 'إرسال الطلب' : 'Submit' ?></button>
<button onclick="closeReauditModal()" style="flex:1;background:#f1f5f9;color:#64748b;border:none;padding:10px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit"><?= $is_ar ? 'إلغاء' : 'Cancel' ?></button>
</div>
</div>
</div>

<script>
window.IS_AR = <?= $is_ar ? 'true' : 'false' ?>;
window.USER_NAME='<?= e(current_user()['full_name'] ?? '') ?>';
const SID = <?= (int)$session_id ?>;
const BASE = '<?= BASE_URL ?>';
window.AUDIO    = <?= get_setting('inv_audio_cue','1') === '1' ? 'true' : 'false' ?>;
window.VIBRATE  = <?= get_setting('inv_vibration','1') === '1' ? 'true' : 'false' ?>;
window.SETTINGS = {
    warnNew:           <?= get_setting('inv_warn_new_device','1') === '1' ? 'true' : 'false' ?>,
    warnMissing:       <?= get_setting('inv_warn_missing_expected','1') === '1' ? 'true' : 'false' ?>,
    requireTag:        <?= get_setting('inv_require_tag_for_audit','0') === '1' ? 'true' : 'false' ?>,
    allowQuickReg:     <?= get_setting('inv_allow_quick_register','1') === '1' ? 'true' : 'false' ?>,
    autoSaveInterval:  <?= (int)get_setting('inv_auto_save_interval_sec','60') ?>,
    maxAssets:         <?= (int)get_setting('inv_max_assets_per_session','200') ?>
};
const TOTAL_SCOPE = <?= (int)$total_scope ?>;
let doneScope = <?= (int)$done_scope ?>;
let curRoom = null, cur = null, othersOpen = false, qrScanner = null;
let allLocs = [], roomAssets = [], ops = null, health = null, locConfirmed = false, saving = false;

const $ = id => document.getElementById(id);
const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(t){const e=$('toast');e.textContent=t;e.classList.add('show');setTimeout(()=>e.classList.remove('show'),2400);}
/* beep + vibrate — مرتبطان بـ inv_audio_cue / inv_vibration */
function beep(ok){ if(!window.AUDIO) return; try{ const ctx=new (window.AudioContext||window.webkitAudioContext)(); const o=ctx.createOscillator(); const g=ctx.createGain(); o.connect(g); g.connect(ctx.destination); o.frequency.value=ok?1200:400; o.type='sine'; g.gain.setValueAtTime(0.15,ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.15); o.start(); o.stop(ctx.currentTime+0.15); }catch(e){} }
function vibrate(ms){ if(!window.VIBRATE||!navigator.vibrate) return; navigator.vibrate(ms||50); }
function feedback(ok){ beep(ok); vibrate(ok?40:120); }
function getRoomName(rm){ return window.IS_AR ? (rm.name||rm.name_ar||rm.name_en||'') : (rm.name_en||rm.name||''); }
function getBldName(rm){ return window.IS_AR ? (rm.building||rm.building_ar||'') : (rm.building_en||rm.building||''); }
function getFlrName(rm){ return window.IS_AR ? (rm.floor||rm.floor_ar||'') : (rm.floor_en||rm.floor||''); }

/* ═══ تسجيل دخول غرفة الجرد (manual code) ═══ */
async function rlSubmitCode(e){
  e.preventDefault();
  const code = $('rlManualCode').value.trim();
  if(!code){ shakeForm(); toast(window.IS_AR?'أدخل الكود أولاً':'Enter the code first'); return false; }
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'resolve',session_id:SID,room_id:1,code:code})});
    const j=await r.json();
    if(!j.ok){
      shakeForm(); feedback(false);
      if(j.error==='room_out_of_scope') toast(window.IS_AR?'🚫 هذه الغرفة خارج نطاق فريقك':'🚫 Room is outside your team scope');
      else toast(window.IS_AR?'⚠️ رمز غرفة غير معروف':'⚠️ Unknown room code');
      return false;
    }
    feedback(true);
    openRoom(j.room_id);
  }catch(e){ shakeForm(); toast(window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Connection failed'); }
  return false;
}
function shakeForm(){
  const f=$('rlManualForm');
  if(!f) return;
  f.classList.remove('shake');
  void f.offsetWidth;
  f.classList.add('shake');
  setTimeout(()=>f.classList.remove('shake'), 500);
}

function paintRing(){ const pct = TOTAL_SCOPE ? Math.min(100, Math.round(doneScope/TOTAL_SCOPE*100)) : 0; $('ringTxt').textContent = pct+'%'; $('ring').style.background = `conic-gradient(var(--teal-br) 0 ${pct}%, rgba(255,255,255,.15) ${pct}% 100%)`; }

function show(s){ ['scrLoc','scrRoom','scrDevice'].forEach(x=>$(x)&&$(x).classList.remove('on')); $(s==='loc'?'scrLoc':s==='room'?'scrRoom':'scrDevice').classList.add('on'); if (s==='device' && cur && !cur.done) $('savebar').classList.add('show'); else $('savebar').classList.remove('show'); stopCamera(); }

function goBack(){ if (curRoom) { show('room'); openRoom(curRoom.id); } else { location.href = `${BASE}/inventory/session.php?id=${SID}`; } }
/* ═══ تحميل المواقع: الغرف الموثّقة فقط ═══ */
async function loadLocations(){
 try{
  const r = await fetch(`${BASE}/inventory/api/verified_rooms.php?session_id=${SID}`);
  const j = await r.json();
  allLocs = j.ok ? (j.rooms||[]) : [];
 }catch(e){ allLocs = []; }
 renderBuildingPicker();
}
function renderBuildingPicker(){
 const sel=$('bldSel'); if(!sel) return;
 const b={};
 allLocs.forEach(r=>{ const id=r.building_id||0; if(!id) return; if(!b[id]) b[id]={id,name:r.building,name_en:r.building_en}; });
 const list=Object.values(b).sort((x,y)=>(x.name||'').localeCompare(y.name||'','ar'));
 sel.innerHTML=`<option value="">${window.IS_AR?'— اختر مبنى —':'— Pick a building —'}</option>`+list.map(x=>`<option value="${x.id}">${esc(window.IS_AR?(x.name||x.name_en||'#'+x.id):(x.name_en||x.name||'#'+x.id))}</option>`).join('');
 $('floorNav').style.display='none'; $('roomNav').style.display='none';
}
function onBldChange(){
 const bid=+$('bldSel').value||0;
 const f={};
 allLocs.forEach(r=>{ if((r.building_id||0)!==bid) return; const id=r.floor_id||0; if(!id) return; if(!f[id]) f[id]={id,name:r.floor,name_en:r.floor_en}; });
 const list=Object.values(f).sort((x,y)=>(x.name||'').localeCompare(y.name||'','ar'));
 $('flrSel').innerHTML=`<option value="">${window.IS_AR?'— اختر طابق —':'— Floor —'}</option>`+list.map(x=>`<option value="${x.id}">${esc(window.IS_AR?(x.name||x.name_en||'#'+x.id):(x.name_en||x.name||'#'+x.id))}</option>`).join('');
 $('floorNav').style.display=list.length?'flex':'none';
 $('roomNav').style.display='none';
}
function onFlrChange(){
 const bid=+$('bldSel').value||0, fid=+$('flrSel').value||0;
 const list=allLocs.filter(r=>(r.building_id||0)===bid&&(r.floor_id||0)===fid).sort((x,y)=>(x.name||'').localeCompare(y.name||'','ar'));
 $('roomSel').innerHTML=`<option value="">${window.IS_AR?'— اختر غرفة —':'— Room —'}</option>`+list.map(r=>`<option value="${r.room_id}">${esc(window.IS_AR?(r.name||r.name_en||'#'+r.room_id):(r.name_en||r.name||'#'+r.room_id))} (${r.done||0}/${r.total||0})</option>`).join('');
 $('roomNav').style.display=list.length?'flex':'none';
}
function onRoomChange(){ const v=+$('roomSel').value||0; if(v) openRoom(v); }

async function openRoom(roomId){
  try{
    show('room');
    $('assetList').innerHTML = `<div class="loading"><i class="fa-solid fa-circle-notch"></i> ${window.IS_AR?'جاري تحميل الأجهزة...':'Loading...'}</div>`;
    const r = await fetch(`${BASE}/inventory/api/room_assets.php?session_id=${SID}&room_id=${roomId}`);
    const j = await r.json();
    if(!j.ok){ $('assetList').innerHTML = `<div class="card" style="color:var(--red)">${esc(j.error||'Error')}</div>`; return; }
    curRoom = j.room; roomAssets = j.assets||[];
    renderRoom();
  }catch(e){ console.error('openRoom failed:',e); $('assetList').innerHTML = `<div class="card" style="color:var(--red)">⚠️ Connection failed</div>`; }
}

function bi(ar,en){ar=ar||'';en=en||'';if(window.IS_AR) return esc(ar||en||'—'); return esc(en||ar||'—');}
async function renderRoom(){
 $('roomName').innerHTML = bi(curRoom.name_ar||curRoom.name, curRoom.name_en);
 const done = roomAssets.filter(a=>a.done).length;
 $('roomPath').innerHTML = bi(curRoom.building_ar||curRoom.building,curRoom.building_en)+' / '+bi(curRoom.floor_ar||curRoom.floor,curRoom.floor_en)+' — <b style="color:var(--green)">'+done+'/'+roomAssets.length+'</b>';
 await rlShowLockBar();
 if(window.roomShared){
  $('assetList').innerHTML =
   `<div class="card" style="text-align:center;color:var(--text2);padding:26px 16px">
    <i class="fa-solid fa-users" style="font-size:34px;margin-bottom:10px;display:block;color:#0284c7"></i>
    <b style="font-size:14px">${window.IS_AR?'غرفة جرد مشتركة':'Shared audit room'}</b><br>
    <span style="font-size:12px">${window.IS_AR?'عدة موظفين يجردون هذه الغرفة الآن — سجّل الأجهزة مباشرة بالمسح':'Multiple staff auditing this room — scan devices directly'}</span>
   </div>
   <button class="btn btn-g" style="width:100%;margin-top:10px" onclick="focusScan()"><i class="fa-solid fa-qrcode"></i> ${window.IS_AR?'مسح جهاز موجود فعلياً':'Scan a device here'}</button>`;
  return;
 }
 $('assetList').innerHTML =
   `<button class="btn btn-g" style="width:100%;margin-bottom:10px" onclick="focusScan()"><i class="fa-solid fa-qrcode"></i> ${window.IS_AR?'مسح جهاز موجود فعلياً في الغرفة':'Scan a device physically here'}</button>
   <details style="margin-bottom:10px"><summary style="cursor:pointer;font-size:12px;font-weight:800;color:var(--text2)">${window.IS_AR?'📋 المسجلون بالغرفة':'Registered in this room'}</summary>
   ${roomAssets.map(a=>`
    <div class="assetcard ${a.done?'done':(a.last_action==='missing'?'miss':'')}" onclick="openDevice(${a.id})">
     <div class="crit ${a.crit||'none'}">${a.crit||'—'}</div>
     <div style="flex:1;min-width:0">
      <div style="font-weight:800;font-size:13px;color:var(--ink)">${esc(window.IS_AR?(a.name_ar||a.name):(a.name||a.name_ar))}</div>
      <div style="font-size:11px;color:var(--muted)">${esc(a.tag||(window.IS_AR?'بدون تاج':'No Tag'))}${a.serial?' • SN '+esc(a.serial):''}</div>
     </div>
     <div class="stx">${a.done?(a.last_action==='missing'?'✗':'✓'):''}</div>
    </div>`).join('')}
   </details>`;
}

function openDevice(id){
    const a = roomAssets.find(x=>x.id===id); if(!a) return;
    cur=a; ops=null; health=null; locConfirmed=false; saving=false;
    $('dName').textContent = window.IS_AR ? (a.name_ar || a.name) : (a.name || a.name_ar);
    $('dTag').textContent = (a.tag||(window.IS_AR?'بدون تاج':'No Tag')) + (a.serial?' • SN '+a.serial:'');
    $('dCrit').textContent = a.crit; $('dCrit').className='crit '+a.crit;
    $('serialIn').value = a.serial || '';
    $('notesIn').value = '';
    /* ═══ عرض الموقع — يتغير حسب هل الأصل في غرفة أخرى ═══ */
    if (a.crossRoom) {
        $('dLoc').innerHTML =
            `<div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:8px 10px;margin-bottom:8px">
                <div style="font-weight:800;color:#991b1b;font-size:12px"><i class="fa-solid fa-triangle-exclamation"></i> ${window.IS_AR?'الجهاز في غرفة أخرى':'Device in another room'}</div>
                <div style="margin-top:4px;font-size:12px;color:#7f1d1d">
                    <b>${window.IS_AR?'الحالي':'Current'}:</b> <span style="background:#fff;padding:2px 6px;border-radius:4px">${esc(a.crossRoomName||'—')}</span>
                </div>
                <div style="margin-top:4px;font-size:12px;color:#166534">
                    <i class="fa-solid fa-arrow-right"></i> <b>${window.IS_AR?'سيُنقل إلى':'Will be moved to'}:</b> <span style="background:#dcfce7;padding:2px 6px;border-radius:4px;font-weight:800">${esc(getRoomName(curRoom))}</span>
                </div>
            </div>
            <div style="font-size:11.5px;color:#64748b">${window.IS_AR?'سيتم تسجيله كـ: <b>انتقال موقع</b>':'Will be logged as: <b>location_changed</b>'}</div>`;
    } else {
        $('dLoc').innerHTML = `<b>${window.IS_AR?'الموقع الحالي':'Current'}:</b> ${esc(getRoomName(curRoom))}<br><b>${window.IS_AR?'المسجل':'Registered'}:</b> ${esc(a.loc_text||(window.IS_AR?'غير معروف':'Unknown'))}`;
    }
    document.querySelectorAll('.opsbtn,.hbtn').forEach(b=>b.classList.remove('sel'));
    /* إذا الأصل في غرفة أخرى → نفعّل الموقع تلقائياً (يعرف من يدخله) */
    if (a.crossRoom) locConfirmed = true;
    if(a.done && !a.crossRoom){ toast(window.IS_AR?'⛔ هذا الجهاز مجرود مسبقاً في هذه الجلسة':'⛔ Already audited in this session'); show('room'); return; }
    show('device');
}

function pickOps(btn,label){ document.querySelectorAll('.opsbtn').forEach(b=>b.classList.remove('sel')); btn.classList.add('sel'); ops=btn.dataset.v; }
function pickH(btn,label){ document.querySelectorAll('.hbtn').forEach(b=>b.classList.remove('sel')); btn.classList.add('sel'); health=+btn.dataset.v; }
function confirmLoc(){ locConfirmed=true; toast(window.IS_AR?'✓ تم تأكيد الموقع':'✓ Location confirmed'); }

async function submitConfirm(){
    if(saving) return;
    if(window.SETTINGS.requireTag && !(cur.tag||'').trim() && !$('serialIn').value.trim()){
        feedback(false);
        toast(window.IS_AR?'⚠️ التاج أو السيريال إلزامي لهذا الجهاز':'⚠️ Tag/Serial is required');
        return;
    }
    if(!ops || !health || !locConfirmed){ toast(window.IS_AR?'أكمل كل الحقول':'Complete all fields'); return; }
    saving=true; $('nextBtn').disabled=true;
    /* ═══ تحديد الإجراء: location_changed له الأولوية على condition_damaged ═══ */
    let action = (ops==='under_maintenance' || health<=40) ? 'condition_damaged' : 'confirmed';
    if (cur.crossRoom) action = 'location_changed';
    try{
        const r = await fetch(`${BASE}/inventory/api/submit.php`,{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
            session_id: SID, asset_id: cur.id, scanned_tag: cur.tag||'', scanned_serial: cur.serial||'',
            scan_method: 'manual', match_method: 'manual_search',
            action: action,
            from_location_id: cur.crossRoomId || null,         /* أين كان الأصل */
            new_location_id: curRoom.id,                       /* الغرفة الجديدة = الغرفة المقفولة */
            location_confirmed: true, health_confirmed: true,
            new_serial: $('serialIn').value.trim(), new_health_score: health, new_status: ops,
            condition_notes: $('notesIn').value.trim()
        })});
        const j = await r.json();
        if(!j.ok){ $('saveErr').textContent='⚠️ '+(j.message||j.error); $('saveErr').style.display='block'; saving=false; $('nextBtn').disabled=false; return; }
        if(!cur.done) doneScope++;
        cur.done = true; cur.last_action = action;
        paintRing();
        if (action === 'location_changed')
            toast(window.IS_AR?'✅ تم نقل الجهاز إلى هذه الغرفة':'✅ Device moved to this room');
        else
            toast(window.IS_AR?'✅ تم الحفظ':'✅ Saved');
        if(window.SETTINGS.maxAssets > 0 && doneScope >= window.SETTINGS.maxAssets)
            toast(window.IS_AR?'⚠️ تجاوزت الحد الأقصى لأصول الجلسة ('+window.SETTINGS.maxAssets+')':'⚠️ Session asset limit reached ('+window.SETTINGS.maxAssets+')');
        saving=false; $('nextBtn').disabled=false;
        show('room'); openRoom(curRoom.id);
    }catch(e){ saving=false; $('nextBtn').disabled=false; toast(window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Failed'); }
}

async function submitMiss(action){
    if(!cur || saving) return;
    if(window.SETTINGS.warnMissing){
        feedback(false);
        if(!confirm(window.IS_AR?'⚠️ هذا الجهاز مسجّل بالغرفة ومتوقع وجوده — هل هو مفقود فعلياً؟':'⚠️ This device is registered & expected in this room — confirm it is truly missing?')) return;
    }
    saving=true;
    try{
        const r = await fetch(`${BASE}/inventory/api/submit.php`,{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({session_id:SID, asset_id:cur.id, scanned_tag:cur.tag||'', scan_method:'manual', match_method:'manual_search', action:action, condition_notes:$('notesIn').value.trim()})});
        const j = await r.json();
        if(!j.ok){ toast('⚠️ '+(j.message||j.error)); saving=false; return; }
        if(!cur.done) doneScope++;
        cur.done = true; cur.last_action = action;
        paintRing(); toast(window.IS_AR?'✗ سُجِّل كمفقود':'✗ Marked Missing');
        saving=false; show('room'); openRoom(curRoom.id);
    }catch(e){ saving=false; toast('⚠️ Connection failed'); }
}

async function doLookup(q){
    if(!q) return;
    try{
        const r = await fetch(`${BASE}/inventory/api/lookup.php?session=${SID}&tag=${encodeURIComponent(q)}`);
        const j = await r.json();
        if(j.found && j.asset){
            /* قاعدة صلبة: الجهاز المجرود مسبقاً في نفس الجلسة — تنبيه صريح + زر طلب استثناء */
            if(j.had_audit){
                feedback(false);
                const dev = j.asset;
                toast(window.IS_AR?'⛔ هذا الجهاز سبق جرده — لا يُجرد مرتين':'⛔ Already audited — re-audit blocked');
                // عرض بطاقة طلب الاستثناء
                const info = $('devInfo');
                info.innerHTML = '<div style="background:#fff7ed;border:1.5px solid #fdba74;border-radius:12px;padding:14px;margin-top:10px;text-align:center">'
                    +'<div style="font-weight:800;font-size:14px;color:#9a3412;margin-bottom:6px">'+(window.IS_AR?'⛔ هذا الجهاز مجرود مسبقاً في هذه الجلسة':'⛔ Already audited in this session')+'</div>'
                    +'<div style="font-size:12px;color:#78350f;margin-bottom:10px">'+esc(dev.description||'')+' — <span class="eng">'+esc(dev.tag_number||'')+'</span></div>'
                    +'<button onclick="openReauditModal()" style="background:#ea580c;color:#fff;border:none;padding:10px 20px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit">'
                    +'<i class="fa-solid fa-flag"></i> '+(window.IS_AR?'اطلب استثناء من مدير الأصول':'Request Exception')+'</button></div>';
                show('dev');
                return;
            }
            const local = roomAssets && roomAssets.find(a=>a.id===j.asset.id);
            if (local) {
                openDevice(local.id);
            } else {
                /* ═══ الأصل في غرفة أخرى — اكتشفنا انتقال! ═══
                   نضيفه مؤقتاً لـ roomAssets مع crossRoom flag
                   ونفتحه بحيث يقدر الموظف يسجّله كـ location_changed */
                const a = Object.assign({}, j.asset);
                a.crossRoom = true;
                a.crossRoomId = a.location_id || 0;
                a.crossRoomName = a.loc_name || a.loc_building || (window.IS_AR?'غرفة أخرى':'another room');
                roomAssets.push(a);
                feedback(true);
                toast(window.IS_AR?'⚠️ الجهاز في غرفة أخرى — سيتم نقله':'⚠️ Device in another room — will be moved');
                openDevice(a.id);
            }
        } else {
            feedback(false);
            if(window.SETTINGS.warnNew && window.SETTINGS.allowQuickReg)
                toast(window.IS_AR?'⚠️ جهاز جديد غير مسجّل — يمكن تسجيله كجهاز جديد':'⚠️ New unregistered device — quick register available');
            else if(window.SETTINGS.warnNew)
                toast(window.IS_AR?'⚠️ جهاز جديد غير مسجّل — أبلغ مدير الأصول':'⚠️ New unregistered device — notify asset admin');
            else
                toast(window.IS_AR?'غير مسجّل':'Not found');
        }
    }catch(e){}
}

async function toggleCamera(mode){
    const box = mode==='global' ? $('globalCameraBox') : $('cameraBox');
    const readerId = mode==='global' ? 'globalQrReader' : 'qrReader';
    if (box.style.display === 'block'){ box.style.display='none'; if(qrScanner){ try{await qrScanner.stop();qrScanner.clear();}catch(e){} qrScanner=null; } return; }
    box.style.display='block';
    qrScanner = new Html5Qrcode(readerId);
    qrScanner.start({facingMode:'environment'}, {fps:10, qrbox:{width:230,height:140}},
        txt=>{ stopCamera(); if(mode==='global'){$('globalSearchIn').value=txt; doLookup(txt);} else {$('searchIn').value=txt; doLookup(txt);} }, ()=>{}).catch(()=>{});
}
function stopCamera(){
  if(qrScanner){ try{qrScanner.stop();qrScanner.clear();}catch(e){} qrScanner=null; }
  const gc=$('globalCameraBox'), cb=$('cameraBox');
  if(gc) gc.style.display='none';
  if(cb) cb.style.display='none';
}
function focusScan(){ if(curRoom) toggleCamera('room'); else toast(window.IS_AR?'ادخل إلى غرفة أولاً':'Enter a room first'); }

/* ═══ حالة الجرد الحالية ═══ */
let auditState = {
    step: 1, // 1=Type, 2=Serial, 3=Condition, 4=Location
    deviceType: null,
    serialNumber: null,
    healthScore: null,
    locationConfirmed: false,
    startedAt: null,
    pausedAt: null,
    totalScanned: 0,
    newDevices: 0,
    pauseTime: 0
};

/* ═══ المؤقت الزمني ═══ */
function startTimer(){
    auditState.startedAt = new Date();
    auditState.pauseTime = 0;
    setInterval(() => {
        if(!auditState.startedAt) return;
        const now = new Date();
        const elapsed = Math.floor((now - auditState.startedAt - auditState.pauseTime) / 1000);
        const mins = Math.floor(elapsed / 60);
        const secs = elapsed % 60;
        $('timerText').textContent = mins+':'+(secs < 10 ? '0' : '')+secs;
    }, 1000);
}

function pauseTimer(){
    if(auditState.startedAt) {
        const now = new Date();
        auditState.pauseTime += now - (auditState.pausedAt || now);
    }
    auditState.pausedAt = new Date();
}

function resumeTimer(){
    auditState.startedAt = new Date();
}

/* ═══ تحديث شステップ التقدم ═══ */
function updateStep(newStep, deviceType=null, serial=null, condition=null, locationConfirmed=false){
    auditState.step = newStep;
    auditState.deviceType = deviceType;
    auditState.serialNumber = serial;
    auditState.healthScore = condition ? parseInt(condition) : null;
    auditState.locationConfirmed = locationConfirmed;
    
    // تحديث العرض
    ['step1','step2','step3','step4'].forEach((id, idx) => {
        const item = $(id);
        if(idx + 1 < newStep) {
            item.style.background = '#10b981';
            item.style.color = 'white';
        } else if(idx + 1 === newStep) {
            item.style.background = '#f59e0b';
            item.style.color = 'white';
        } else {
            item.style.background = 'var(--border)';
            item.style.color = 'var(--muted)';
        }
    });
    
    $('stepStatus').textContent = (window.IS_AR ? 'خطوة ' : 'Step ')+newStep+(window.IS_AR ? ' من 4' : ' of 4');
    $('stepStatusValue').textContent = window.IS_AR ? newStep+' من 4' : newStep+' of 4';
}

/* ═══ أزرار التحكم ═══ */
function triggerPause(){
    if(auditState.step === 1){ toast(window.IS_AR?'ابدأ الجرد أولاً':'Start audit first'); return; }
    if(auditState.step > 1 && !confirm(window.IS_AR?'هل تؤكد توقف الجرد؟':'Are you sure you want to pause the audit?')) return;
    
    pauseTimer();
    auditState.pausedAt = new Date();
    $('btnPause').textContent = window.IS_AR ? 'استئناف' : 'Resume';
    $('btnPause').onclick = triggerResume;
    toast(window.IS_AR?'جمد الجرد مؤقتاً':'Audit paused');
}

function triggerResume(){
    resumeTimer();
    $('btnPause').textContent = window.IS_AR ? 'إيقاف' : 'Pause';
    $('btnPause').onclick = triggerPause;
    toast(window.IS_AR?'استؤنف الجرد':'Audit resumed');
}

function triggerExit(){
    if(auditState.step === 1){ 
        // لا يسمح بالخروج قبل البدء
        if(!confirm(window.IS_AR?'هل تريد الخروج دون بدء الجرد؟':'Do you want to exit without starting the audit?')) return;
    } else {
        if(!confirm(window.IS_AR?'هل تريد الخروج وإنهاء الجرد الحالي؟':'Do you want to exit and finish the current audit?')) return;
    }
    
    // Save current progress before exit
    saving=true;
    // Reset state for next time
    auditState = {
        step: 1,
        deviceType: null,
        serialNumber: null,
        healthScore: null,
        locationConfirmed: false,
        startedAt: null,
        pausedAt: null,
        totalScanned: 0,
        newDevices: 0,
        pauseTime: 0
    };
    
    // Reset UI
    ['step1','step2','step3','step4'].forEach((id) => {
        $(id).style.background = 'var(--border)';
        $(id).style.color = 'var(--muted)';
    });
    $('stepStatus').textContent = '0 من 4';
    $('stepStatusValue').textContent = '0 of 4';
    $('userScannedCount').textContent = '0';
    $('userNewCount').textContent = '0';
    $('timerText').textContent = '0:00';
    
    saving=false;
    // Return to session list
    window.location.href = BASE+'/inventory/session.php?id='+SID;
}

/* ═══ تحديثات عند كل حفظ ═══ */
function updateAuditStats(action){
    // تحديث العدادات
    if(action === 'confirmed' || action === 'condition_damaged' || action === 'missing') {
        let current = parseInt($('userScannedCount').textContent);
        $('userScannedCount').textContent = current + 1;
        
        if(action === 'missing') {
            let newCurr = parseInt($('userNewCount').textContent);
            $('userNewCount').textContent = newCurr + 1;
        }
    }
    
    // تحديث شريط الخطوات
    const stepMap = {1:1, 2:2, 3:3, 4:4};
    updateStep(stepMap[auditState.step] || 1, auditState.deviceType, auditState.serialNumber, auditState.healthScore, auditState.locationConfirmed);
}

/* ═══ الحفظ التلقائي للتقدم — حسب إعداد inv_auto_save_interval_sec (0 = يدوي فقط) ═══ */
if(window.SETTINGS.autoSaveInterval > 0){
    setInterval(() => {
        if(!curRoom) return;
        try{
            localStorage.setItem('pmsh_audit_progress_'+SID, JSON.stringify({
                room_id: curRoom.id,
                done: doneScope,
                assets: roomAssets.map(a=>({id:a.id, done:!!a.done, last_action:a.last_action||null})),
                at: Date.now()
            }));
        }catch(e){}
    }, window.SETTINGS.autoSaveInterval * 1000);
}

/* ═══ نافذة طلب استثناء إعادة الجرد ═══ */
function openReauditModal(){ $('reauditModal').classList.add('show'); }
function closeReauditModal(){ $('reauditModal').classList.remove('show'); }
async function submitReauditRequest(){
    const reason = $('reauditReason').value.trim();
    if(!reason){ toast(window.IS_AR?'⚠️ اكتب السبب':'⚠️ Enter reason'); return; }
    try{
        const r = await fetch(`${BASE}/inventory/api/reaudit_request.php`,{
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({session_id:SID, asset_id:cur?cur.id:0, request_type:'re_audit_device', reason:reason})
        });
        const j = await r.json();
        closeReauditModal();
        if(j.ok)
            toast(window.IS_AR?'✅ تم إرسال الطلب — بانتظار اعتماد مدير الأصول':'✅ Request sent — awaiting admin approval');
        else
            toast('⚠️ '+(j.error||'Failed'));
    }catch(e){ toast(window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Connection failed'); }
}

/* ═══ تفعيل المؤقت وتحميل الغرف الموثّقة فور فتح الصفحة ═══ */
document.addEventListener('DOMContentLoaded', () => { startTimer(); loadLocations(); });
</script>

<?php if (file_exists(BASE_PATH . '/inventory/roomlock_ui.php')) include BASE_PATH . '/inventory/roomlock_ui.php'; ?>
<?php endif; ?>
</body>
</html>