<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { echo json_encode([]); exit; }

$_af_u    = current_user();
$_af_all  = can_see_all();
$_af_dept = (int)($_af_u['department_id'] ?? 0);
$_af_uid  = (int)($_af_u['id'] ?? 0);
$_af_out  = [];

$_af_push = function ($icon, $color, $title, $desc, $url, $ts) use (&$_af_out) {
    $_af_out[] = ['icon'=>$icon,'color'=>$color,'title'=>$title,'desc'=>$desc,'url'=>$url,'ts'=>strtotime((string)$ts) ?: time()];
};
$_af_ago = function ($ts) {
    $d = time() - (int)$ts;
    if ($d < 60)    return 'الآن';
    if ($d < 3600)  return floor($d/60) . ' د';
    if ($d < 86400) return floor($d/3600) . ' س';
    return floor($d/86400) . ' يوم';
};

/* 1) البلاغات — 3 مستويات:
   شامل → الكل | مهندس → كل النشطة | موظف → قسمه فقط */
if (can('complaints.index','view')) { try {
    $w = ''; $p = [];
    if (!$_af_all) {
        if (can('complaints.index','manage')) {
            $w = " WHERE status IN ('open','acknowledged','in_progress','escalated')";
        } else {
            $w = ' WHERE dept_id = :af_d'; $p['af_d'] = $_af_dept;
        }
    }
    $st = $pdo->prepare("SELECT id, request_number, priority, status, created_at FROM complaints$w ORDER BY created_at DESC LIMIT 5");
    $st->execute($p);
    foreach ($st as $r) $_af_push('fa-bell', ($r['priority']??'')==='critical'?'#ef4444':'#f59e0b',
        'بلاغ #'.($r['request_number'] ?? $r['id']),
        'الأولوية: '.($r['priority'] ?? '-').' · الحالة: '.($r['status'] ?? '-'),
        BASE_URL.'/complaints/view.php?id='.(int)$r['id'], $r['created_at'] ?? '');
} catch(Throwable $e){} }

/* 2) أوامر العمل — شامل / مهندس / شركة صيانة (أوامرها فقط) */
try {
    $_af_wo_show = can('work_orders.index','view') && ($_af_all || can('work_orders.index','manage'));
    $w = ''; $p = [];
    if (!$_af_wo_show) { try {
        $cid = (int)$pdo->query("SELECT contractor_id FROM users WHERE id = $_af_uid")->fetchColumn();
        if ($cid > 0) { $_af_wo_show = true; $w = ' WHERE contractor_id = :af_cid'; $p['af_cid'] = $cid; }
    } catch(Throwable $e2){} }
    if ($_af_wo_show) {
        $st = $pdo->prepare("SELECT id, status, created_at FROM complaint_work_orders$w ORDER BY created_at DESC LIMIT 4");
        $st->execute($p);
        foreach ($st as $r) $_af_push('fa-clipboard-list','#e11d48','أمر عمل','الحالة: '.($r['status'] ?? '-'),
            BASE_URL.'/complaints/wo_list.php', $r['created_at'] ?? '');
    }
} catch(Throwable $e){}

/* 3) أصول جديدة */
if (can('assets.index','view')) { try {
    $w=''; $p=[];
    if (!$_af_all) { $w=' WHERE department_id=:af_d OR custodian_dept_id=:af_d'; $p['af_d']=$_af_dept; }
    $st=$pdo->prepare("SELECT id, tag_number, created_at FROM assets$w ORDER BY id DESC LIMIT 3");
    $st->execute($p);
    foreach ($st as $r) $_af_push('fa-box','#7c3aed','أصل '.($r['tag_number'] ?? ''),'سُجّل في النظام',
        BASE_URL.'/assets/device_dossier.php?id='.(int)$r['id'], $r['created_at'] ?? date('Y-m-d H:i:s'));
} catch(Throwable $e){} }

/* 4) جلسات الجرد */
if (can('inventory.index','view')) { try {
    $st=$pdo->query("SELECT id, status, created_at FROM inventory_sessions ORDER BY id DESC LIMIT 3");
    foreach ($st as $r) $_af_push('fa-clipboard-check','#6366f1','جلسة جرد','الحالة: '.($r['status'] ?? '-'),
        BASE_URL.'/inventory/index.php', $r['created_at'] ?? date('Y-m-d H:i:s'));
} catch(Throwable $e){} }

/* ═══ فرز + حد أقصى + وقت نسبي ═══ */
usort($_af_out, fn($a,$b) => $b['ts'] <=> $a['ts']);
$_af_out = array_slice($_af_out, 0, 12);
foreach ($_af_out as &$_af_e) { $_af_e['ago'] = $_af_ago($_af_e['ts']); unset($_af_e['ts']); }
unset($_af_e);
echo json_encode(array_values($_af_out), JSON_UNESCAPED_UNICODE);