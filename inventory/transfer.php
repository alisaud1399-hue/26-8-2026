<?php
/**
 * inventory/transfer.php — شاشة نقل الأجهزة بين الغرف (داخل القسم الواحد)
 * الفريق المنفذ يُعرض تلقائياً من asset_type — لا يمكن تغييره يدوياً
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/transfer_engine.php';
page_guard('inventory.index');
$rtl = is_rtl(); $uid = user_id();
$scope = transfer_user_depts($uid);
$canExec = transfer_can('execute');
$oversight = is_admin() || transfer_can('view_all');
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'queue';

/* ═══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? ''; $rid = (int)($_POST['rid'] ?? 0);
    $text = trim($_POST['reason'] ?? ''); $back = $_POST['tab'] ?? 'queue';
    if ($action === 'create') $res = transfer_create(['asset_id'=>$_POST['asset_id']??0,'to_location_id'=>$_POST['to_location_id']??0,'reason'=>$text]);
    elseif ($action === 'exec') $res = transfer_exec_execute($rid);
    elseif ($action === 'reject') $res = transfer_exec_reject($rid, $text);
    elseif ($action === 'confirm') $res = transfer_req_confirm($rid);
    elseif ($action === 'accept_reject') $res = transfer_req_accept_reject($rid);
    elseif ($action === 'object') $res = transfer_req_object($rid, $text);
    elseif ($action === 'note') $res = transfer_add_note($rid, $text);
    else $res = ['ok'=>false,'msg'=>'إجراء غير معروف'];
    flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'تم التنفيذ بنجاح ✅' : ($res['msg'] ?? 'خطأ'));
    header('Location: ' . BASE_URL . '/inventory/transfer.php?tab=' . urlencode($back)); exit;
}

/* ═══ القوائم ═══ */
$base = '1=1';
if ($scope !== null && !$oversight) { $in = implode(',', $scope ?: [0]); $base = "(r.dept_id IN ($in) OR r.requested_by=$uid)"; }
$list_sql = "SELECT r.*,a.tag_number,a.description,a.description_ar,a.asset_type,u.full_name req_name,e.full_name exec_name,sf.name from_name,st2.name to_name,d.name dept_name
    FROM asset_transfer_requests r JOIN assets a ON a.id=r.asset_id
    LEFT JOIN users u ON u.id=r.requested_by LEFT JOIN users e ON e.id=r.executed_by
    LEFT JOIN item_locations sf ON sf.id=r.from_location_id LEFT JOIN item_locations st2 ON st2.id=r.to_location_id
    LEFT JOIN departments d ON d.id=r.dept_id WHERE %s ORDER BY r.id DESC LIMIT 200";
$q = function($w) use ($pdo,$list_sql){ $st=$pdo->prepare(sprintf($list_sql,$w)); $st->execute(); return $st->fetchAll(PDO::FETCH_ASSOC); };
$L_queue=$q("$base AND r.status='pending_exec'");
$L_conf =$q("r.status='pending_confirm' AND r.requested_by=$uid");
$L_rev  =$q("r.status='rejected_review' AND (r.requested_by=$uid OR ".($oversight?'1=1':'1=0').")");
$L_log  =$q("$base");
$n_queue=count($L_queue); $n_conf=count($L_conf); $n_rev=count($L_rev);

/* ═══ بيانات النموذج ═══ */
$form_assets = transfer_scoped_assets($scope);
$form_rooms  = transfer_scoped_rooms($scope);
$amap = []; foreach ($form_assets as $fa) $amap[$fa['id']] = ['room_id'=>(int)$fa['room_id'],'room_name'=>$fa['room_name'],'type'=>$fa['asset_type']];
$rjson = []; foreach ($form_rooms as $fr) $rjson[] = ['id'=>(int)$fr['id'],'name'=>$fr['name'],'dept'=>$fr['dept_name']];

/* ═══ بطاقة طلب ═══ */
$card = function($r) use ($uid,$canExec,$tab) {
    $S = ['pending_exec'=>['بانتظار التنفيذ','#f59e0b',1],'pending_confirm'=>['بانتظار الاعتماد','#6366f1',1],
          'rejected_review'=>['تعذّر — بانتظار الرد','#ef4444',1],'done'=>['مكتمل','#10b981',0],'rejected'=>['مرفوض (مغلق)','#94a3b8',0]][$r['status']] ?? [$r['status'],'#94a3b8',0];
    $T = ['biomedical'=>['fa-heart-pulse','#dc2626'],'it'=>['fa-laptop','#0284c7'],'general'=>['fa-screwdriver-wrench','#16a34a']][$r['exec_team']] ?? ['fa-wrench','#64748b'];
    $notes = transfer_get_notes((int)$r['id']); $open = !in_array($r['status'], ['done','rejected'], true);
    ob_start(); ?>
<div class="tcard">
  <div class="ttop">
    <span class="sbadge" style="background:<?= $S[1] ?>1a;color:<?= $S[1] ?>"><span class="dot <?= $S[2]?'pulse':'' ?>" style="background:<?= $S[1] ?>"></span><?= e(transfer_status_label($r['status'])) ?></span>
    <span class="tteam" style="color:<?= $T[1] ?>"><i class="fa-solid <?= $T[0] ?>"></i> <?= e(transfer_team_label($r['exec_team'])) ?></span>
    <span class="rid">#<?= $r['id'] ?></span>
  </div>
  <div class="tasset"><b class="mono"><?= e($r['tag_number']) ?></b><span><?= e($r['description_ar'] ?: $r['description']) ?></span></div>
  <div class="troute"><span class="rfrom"><i class="fa-solid fa-door-open"></i> <?= e($r['from_name']) ?></span><i class="fa-solid fa-arrow-left-long rarr"></i><span class="rto"><i class="fa-solid fa-location-dot"></i> <?= e($r['to_name']) ?></span></div>
  <div class="tmeta"><span><i class="fa-solid fa-user-pen"></i> <?= e($r['req_name']) ?></span><span><i class="fa-regular fa-clock"></i> <?= e(substr($r['requested_at'],0,16)) ?></span><?php if($r['exec_name']): ?><span><i class="fa-solid fa-user-gear"></i> <?= e($r['exec_name']) ?></span><?php endif; ?><span><i class="fa-solid fa-building"></i> <?= e($r['dept_name']) ?></span></div>
  <?php if ($r['exec_note']): ?><div class="tnote"><i class="fa-solid fa-comment-dots"></i> <?= e($r['exec_note']) ?></div><?php endif; ?>
  <div class="tact">
    <?php if ($r['status']==='pending_exec' && $canExec): ?>
      <form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="action" value="exec"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="queue"><button class="btn btn-ok">✅ تم التنفيذ والتشغيل</button></form>
      <button class="btn btn-bad" onclick="openModal('reject',<?= $r['id'] ?>,'تعذّر التنفيذ — اذكر السبب','مثال: لا يتوفر مصدر كهرباء بالغرفة الوجهة...','queue')">⚠ تعذّر</button>
    <?php endif; ?>
    <?php if ($r['status']==='pending_confirm' && (int)$r['requested_by']===$uid): ?>
      <form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="confirm"><button class="btn btn-grad">✅ اعتماد نهائي وإغلاق</button></form>
    <?php endif; ?>
    <?php if ($r['status']==='rejected_review' && ((int)$r['requested_by']===$uid || is_admin())): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('قبول التعذّر وإغلاق الطلب؟')"><?= csrf_input() ?><input type="hidden" name="action" value="accept_reject"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="review"><button class="btn btn-mut">قبول التعذّر (إغلاق)</button></form>
      <button class="btn btn-warn" onclick="openModal('object',<?= $r['id'] ?>,'اعتراض على التعذّر — اذكر الأسباب','اكتب أسباب اعتراضك...','review')">🔄 اعتراض</button>
    <?php endif; ?>
  </div>
  <?php if ($notes || $open): ?>
  <details class="tdet"><summary><i class="fa-solid fa-comments"></i> الملاحظات (<?= count($notes) ?>)</summary>
    <div class="tnotes">
      <?php foreach ($notes as $n): ?><div class="nrow <?= $n['note_kind']==='objection'?'obj':'' ?>"><b><?= e($n['full_name']) ?>:</b> <?= e($n['note']) ?> <small><?= e(substr($n['created_at'],0,16)) ?></small></div><?php endforeach; ?>
      <?php if ($open): ?><form method="POST" class="nform"><?= csrf_input() ?><input type="hidden" name="action" value="note"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input name="reason" placeholder="أضف ملاحظة..." required><button class="btn btn-mut">إرسال</button></form><?php endif; ?>
    </div>
  </details>
  <?php endif; ?>
</div>
<?php return ob_get_clean(); };
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>نقل الأجهزة — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.wrap{max-width:1200px;margin:0 auto;padding:20px 20px 60px}
.hero{background:linear-gradient(135deg,#4f46e5,#7c3aed 55%,#a21caf);border-radius:22px;color:#fff;padding:26px 28px;position:relative;overflow:hidden;box-shadow:0 16px 40px rgba(99,102,241,.35)}
.hero::before{content:'';position:absolute;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.12);top:-120px;inset-inline-end:-60px}
.hero h1{margin:0 0 6px;font-size:22px;font-weight:900;display:flex;gap:10px;align-items:center}
.hero p{margin:0;opacity:.85;font-size:13px}
.steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.step{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:99px;padding:6px 14px;font-size:12px;font-weight:700;display:flex;gap:7px;align-items:center}
.kpis{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.kpi{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:14px;padding:8px 16px;text-align:center;min-width:80px}
.kpi b{display:block;font-size:20px;font-weight:900}.kpi span{font-size:10.5px;opacity:.85;font-weight:700}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}
.tab{padding:10px 18px;border-radius:99px;background:#fff;border:1.5px solid #e6eaf2;font-weight:800;font-size:13px;color:#64748b;text-decoration:none;display:flex;gap:8px;align-items:center;transition:.2s}
.tab.on{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-color:transparent;box-shadow:0 8px 20px rgba(99,102,241,.35)}
.tab .n{border-radius:99px;padding:1px 9px;font-size:11px;background:#eef1f7;color:#64748b}.tab.on .n{background:rgba(255,255,255,.25);color:#fff}
.tcard{background:#fff;border:1.5px solid #e6eaf2;border-radius:18px;padding:16px 18px;margin-bottom:14px;animation:fadeUp .35s ease;transition:.2s}
.tcard:hover{box-shadow:0 12px 30px rgba(15,23,42,.08);transform:translateY(-2px)}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.ttop{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.sbadge{display:inline-flex;gap:7px;align-items:center;padding:5px 12px;border-radius:99px;font-size:11.5px;font-weight:800}
.dot{width:8px;height:8px;border-radius:50%}.pulse{animation:pulse 1.4s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5)}50%{box-shadow:0 0 0 6px rgba(245,158,11,0)}}
.tteam{font-size:12px;font-weight:800;display:flex;gap:6px;align-items:center}
.rid{margin-inline-start:auto;color:#94a3b8;font-family:monospace;font-size:12px}
.tasset{font-size:13.5px;margin-bottom:10px}.tasset b{background:#eef2ff;color:#3730a3;padding:2px 8px;border-radius:6px;margin-inline-end:8px}
.troute{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#f8fafc;border:1px dashed #e2e8f0;border-radius:12px;padding:10px 14px;font-size:12.5px;font-weight:700;margin-bottom:10px}
.rfrom{color:#64748b}.rto{color:#4f46e5}.rarr{color:#a5b4fc;font-size:15px}
.tmeta{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:#64748b;margin-bottom:10px}.tmeta i{margin-inline-end:4px;color:#94a3b8}
.tnote{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:8px 12px;font-size:12px;margin-bottom:10px}
.tact{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:none;border-radius:10px;padding:8px 14px;font-family:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:.15s}
.btn-grad{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;box-shadow:0 6px 16px rgba(99,102,241,.35)}
.btn-ok{background:#10b981;color:#fff}.btn-bad{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca}
.btn-warn{background:#fffbeb;color:#b45309;border:1.5px solid #fde68a}.btn-mut{background:#f1f5f9;color:#475569}
.tdet{margin-top:10px;border-top:1px solid #f1f5f9;padding-top:8px}.tdet summary{cursor:pointer;font-size:12px;font-weight:800;color:#64748b}
.tnotes{padding:8px 4px}.nrow{background:#f8fafc;border-radius:8px;padding:7px 10px;font-size:12px;margin-bottom:6px}.nrow.obj{background:#fff7ed;border:1px solid #fed7aa}
.nform{display:flex;gap:8px;margin-top:8px}.nform input{flex:1;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit}
.req-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:14px}@media(max-width:900px){.req-grid{grid-template-columns:1fr}}
.pcard{background:#fff;border:1.5px solid #e6eaf2;border-radius:18px;padding:16px;animation:fadeUp .35s}
.pcard h3{margin:0 0 12px;font-size:14px;font-weight:900;display:flex;gap:8px;align-items:center}
.pnum{width:26px;height:26px;border-radius:9px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:900}
.asearch{width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:inherit;margin-bottom:10px}
.alist{max-height:340px;overflow:auto;display:flex;flex-direction:column;gap:6px}
.arow{display:flex;gap:10px;align-items:center;background:#f8fafc;border:1.5px solid #eef1f7;border-radius:12px;padding:9px 12px;cursor:pointer;transition:.15s}
.arow:hover{border-color:#a5b4fc;background:#eef2ff}.arow input{accent-color:#6366f1}
.aic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px}
.aic.medical{background:#fee2e2;color:#dc2626}.aic.it{background:#e0f2fe;color:#0284c7}.aic.infrastructure,.aic.hvac{background:#dcfce7;color:#16a34a}
.ainfo{flex:1;min-width:0}.ainfo b{font-size:12px}.ainfo small{display:block;color:#64748b;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aroom{font-size:11px;color:#64748b;font-weight:700}
.sel,.tin{width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:inherit;background:#fff}
.curroom{background:#eef2ff;border:1px dashed #c7d2fe;color:#3730a3;border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:800;margin-bottom:10px}
.f label{display:block;font-size:12px;font-weight:800;color:#475569;margin:10px 0 5px}
.team-auto{display:flex;gap:12px;align-items:center;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:14px;padding:12px 14px;font-weight:800;font-size:13.5px;color:#64748b;transition:.3s}
.team-auto .ta-ic{width:38px;height:38px;border-radius:11px;background:#94a3b8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;transition:.3s}
.ta-note{display:block;margin-top:8px;font-size:11px;color:#94a3b8;font-weight:700}.ta-note i{color:#10b981}
.tmodal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:950;align-items:center;justify-content:center}
.tmodal.on{display:flex}
.tbox{background:#fff;border-radius:18px;width:min(460px,92%);padding:22px;animation:fadeUp .25s}
.tbox h3{margin:0 0 12px;font-size:15px;font-weight:900}
.tbox textarea{width:100%;min-height:90px;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:inherit}
.empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty i{font-size:40px;display:block;margin-bottom:12px;opacity:.35}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="wrap">
<div class="hero">
  <h1><i class="fa-solid fa-right-left"></i> نقل الأجهزة بين الغرف</h1>
  <p>مناقلة داخل القسم الواحد — بتوجيه ذكي للفريق المنفذ وسجل حركات كامل</p>
  <div class="steps">
    <span class="step"><i class="fa-solid fa-user-pen"></i> طلب رئيس القسم</span>
    <span class="step"><i class="fa-solid fa-user-gear"></i> تنفيذ الفريق المختص</span>
    <span class="step"><i class="fa-solid fa-signature"></i> اعتماد صاحب الطلب</span>
    <span class="step"><i class="fa-solid fa-lock"></i> إغلاق وتحديث تلقائي</span>
  </div>
  <div class="kpis">
    <div class="kpi"><b><?= $n_queue ?></b><span>بانتظار التنفيذ</span></div>
    <div class="kpi"><b><?= $n_conf ?></b><span>بانتظار الاعتماد</span></div>
    <div class="kpi"><b><?= $n_rev ?></b><span>تعذّرات معلّقة</span></div>
    <div class="kpi"><b><?= count($L_log) ?></b><span>إجمالي السجل</span></div>
  </div>
</div>
<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin:14px 0 0"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<div class="tabs">
  <a class="tab <?= $tab==='new'?'on':'' ?>" href="?tab=new"><i class="fa-solid fa-plus"></i> طلب جديد</a>
  <a class="tab <?= $tab==='queue'?'on':'' ?>" href="?tab=queue"><i class="fa-solid fa-hourglass-half"></i> طابور التنفيذ <span class="n"><?= $n_queue ?></span></a>
  <a class="tab <?= $tab==='confirm'?'on':'' ?>" href="?tab=confirm"><i class="fa-solid fa-signature"></i> بانتظار اعتمادي <span class="n"><?= $n_conf ?></span></a>
  <a class="tab <?= $tab==='review'?'on':'' ?>" href="?tab=review"><i class="fa-solid fa-triangle-exclamation"></i> تعذّرات <span class="n"><?= $n_rev ?></span></a>
  <a class="tab <?= $tab==='log'?'on':'' ?>" href="?tab=log"><i class="fa-solid fa-clock-rotate-left"></i> السجل</a>
</div>

<?php if ($tab === 'new'): ?>
<form method="POST">
<?= csrf_input() ?><input type="hidden" name="action" value="create"><input type="hidden" name="tab" value="new">
<div class="req-grid">
  <div class="pcard">
    <h3><span class="pnum">1</span> اختر الأصل (نشط + مجرود + ضمن أقسامك)</h3>
    <input class="asearch" placeholder="🔍 بحث بالتاج / الوصف..." oninput="filterAssets(this.value)">
    <div class="alist">
      <?php if (!$form_assets): ?><div class="empty"><i class="fa-solid fa-box-open"></i>لا توجد أصول مؤهلة ضمن نطاقك</div><?php endif; ?>
      <?php foreach ($form_assets as $fa): ?>
      <label class="arow" data-search="<?= e(mb_strtolower($fa['tag_number'].' '.($fa['description_ar']?:$fa['description']).' '.$fa['room_name'])) ?>">
        <input type="radio" name="asset_id" value="<?= $fa['id'] ?>" onchange="pickAsset(<?= $fa['id'] ?>)" required>
        <span class="aic <?= e($fa['asset_type']) ?>"><i class="fa-solid <?= $fa['asset_type']==='medical'?'fa-heart-pulse':($fa['asset_type']==='it'?'fa-laptop':'fa-screwdriver-wrench') ?>"></i></span>
        <span class="ainfo"><b class="mono"><?= e($fa['tag_number']) ?></b><small><?= e($fa['description_ar'] ?: $fa['description']) ?></small></span>
        <span class="aroom"><i class="fa-solid fa-door-open"></i> <?= e($fa['room_name']) ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div>
    <div class="pcard">
      <h3><span class="pnum">2</span> الوجهة (نفس القسم، موثقة)</h3>
      <div class="curroom" id="curRoom"><i class="fa-solid fa-location-dot"></i> الموقع الحالي: —</div>
      <label class="f" style="margin-top:0">الغرفة الوجهة</label>
      <select class="sel" name="to_location_id" id="destSel" required><option value="">— اختر الأصل أولاً —</option></select>
    </div>
    <div class="pcard" style="margin-top:14px">
      <h3><span class="pnum">3</span> الفريق المنفذ والسبب</h3>
      <label class="f" style="margin-top:0">الفريق المنفذ (يُقرأ تلقائياً)</label>
      <div class="team-auto" id="teamBox"><span class="ta-ic" id="teamIc"><i class="fa-solid fa-wrench"></i></span><span id="teamLbl">— اختر أصلاً ليُحدَّد الفريق تلقائياً —</span></div>
      <small class="ta-note"><i class="fa-solid fa-lock"></i> يُحدَّد الفريق من حقل asset_type تلقائياً ولا يمكن تغييره يدوياً — تماماً كنظام البلاغات</small>
      <label class="f">سبب النقل (اختياري)</label>
      <input class="tin" name="reason" placeholder="مثال: حاجة الغرفة لجهاز احتياطي...">
      <button class="btn btn-grad" style="width:100%;margin-top:16px;padding:12px"><i class="fa-solid fa-paper-plane"></i> إرسال طلب النقل</button>
    </div>
  </div>
</div>
</form>
<?php elseif ($tab === 'queue'): ?>
<?php if (!$L_queue): ?><div class="empty"><i class="fa-solid fa-inbox"></i>لا توجد طلبات بانتظار التنفيذ</div><?php endif; ?>
<?php foreach ($L_queue as $r) echo $card($r); ?>
<?php elseif ($tab === 'confirm'): ?>
<?php if (!$L_conf): ?><div class="empty"><i class="fa-solid fa-signature"></i>لا يوجد ما ينتظر اعتمادك</div><?php endif; ?>
<?php foreach ($L_conf as $r) echo $card($r); ?>
<?php elseif ($tab === 'review'): ?>
<?php if (!$L_rev): ?><div class="empty"><i class="fa-solid fa-circle-check"></i>لا توجد تعذّرات معلّقة</div><?php endif; ?>
<?php foreach ($L_rev as $r) echo $card($r); ?>
<?php else: ?>
<?php if (!$L_log): ?><div class="empty"><i class="fa-solid fa-clock-rotate-left"></i>السجل فارغ</div><?php endif; ?>
<?php foreach ($L_log as $r) echo $card($r); ?>
<?php endif; ?>
</div>
</main>
</div>

<div class="tmodal" id="tModal"><div class="tbox">
<h3 id="tmTitle"></h3>
<form method="POST"><?= csrf_input() ?>
<input type="hidden" name="action" id="tmAction"><input type="hidden" name="rid" id="tmRid"><input type="hidden" name="tab" id="tmTab">
<textarea name="reason" id="tmText" required></textarea>
<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
<button type="button" class="btn btn-mut" onclick="closeModal()">إلغاء</button>
<button class="btn btn-grad">إرسال</button>
</div></form>
</div></div>

<script>
var AMAP=<?= json_encode($amap, JSON_UNESCAPED_UNICODE) ?>;
var ROOMS=<?= json_encode($rjson, JSON_UNESCAPED_UNICODE) ?>;
var TEAM_META={biomedical:{ic:'fa-heart-pulse',c:'#dc2626',bg:'#fee2e2',lb:'الصيانة الطبية'},it:{ic:'fa-laptop',c:'#0284c7',bg:'#e0f2fe',lb:'تقنية المعلومات'},general:{ic:'fa-screwdriver-wrench',c:'#16a34a',bg:'#dcfce7',lb:'الصيانة العامة'}};
function teamFor(t){return t==='medical'?'biomedical':(t==='it'?'it':'general');}
function setTeam(t){var m=TEAM_META[t]||TEAM_META.general;var b=document.getElementById('teamBox');b.style.background=m.bg;b.style.borderColor=m.c;b.style.color=m.c;var ic=document.getElementById('teamIc');ic.style.background=m.c;ic.innerHTML='<i class="fa-solid '+m.ic+'"></i>';document.getElementById('teamLbl').textContent=m.lb;}
function pickAsset(id){
  var a=AMAP[id]; if(!a)return;
  document.getElementById('curRoom').innerHTML='<i class="fa-solid fa-location-dot"></i> الموقع الحالي: '+(a.room_name||'—');
  var sel=document.getElementById('destSel'); sel.innerHTML='<option value="">— اختر الغرفة الوجهة —</option>';
  ROOMS.forEach(function(r){ if(r.id!==a.room_id){ var o=document.createElement('option'); o.value=r.id; o.textContent=r.name+' · '+r.dept; sel.appendChild(o);} });
  setTeam(teamFor(a.type));
}
function filterAssets(q){ q=q.toLowerCase(); document.querySelectorAll('.arow').forEach(function(r){ r.style.display=(!q||r.dataset.search.includes(q))?'':'none'; }); }
function openModal(action,rid,title,ph,tab){
  document.getElementById('tmAction').value=action; document.getElementById('tmRid').value=rid;
  document.getElementById('tmTab').value=tab; document.getElementById('tmTitle').textContent=title;
  document.getElementById('tmText').placeholder=ph||'اكتب السبب...';
  document.getElementById('tModal').classList.add('on');
}
function closeModal(){document.getElementById('tModal').classList.remove('on');}
</script>
</body>
</html>