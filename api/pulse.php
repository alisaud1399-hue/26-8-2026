<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { echo json_encode(null); exit; }
$o = [];
$q = function($sql) use ($pdo){ try{ return $pdo->query($sql)->fetchColumn(); }catch(Throwable $e){ return 0; } };

$o['wo']     = (int)$q("SELECT COUNT(*) FROM complaint_work_orders WHERE status IN ('sent_to_contractor','in_progress','pending_manager_approval')");
$o['crit']   = (int)$q("SELECT COUNT(*) FROM complaints WHERE priority='critical' AND status NOT IN ('closed','cancelled','rejected','resolved')");
$o['sla']    = (int)$q("SELECT COUNT(*) FROM complaints WHERE sla_breach_detected_at IS NOT NULL AND status NOT IN ('closed','cancelled','rejected','resolved')");
$o['pm']     = (int)$q("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due < CURDATE()");
$o['no_emp'] = (int)$q("SELECT COUNT(*) FROM assets WHERE (custodian_name IS NULL OR custodian_name='') AND status NOT IN ('disposed','returned_to_supplier')");
$o['inv']    = (int)$q("SELECT COUNT(*) FROM inventory_sessions WHERE status IN ('planning','active','review')");

/* اتجاه: اليوم مقابل أمس */
$o['today'] = (int)$q("SELECT COUNT(*) FROM complaints WHERE DATE(created_at)=CURDATE()");
$o['yest']  = (int)$q("SELECT COUNT(*) FROM complaints WHERE DATE(created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");

/* Sparkline 7 أيام */
$spark = array_fill(0,7,0);
try{
  $st=$pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM complaints WHERE created_at >= DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY d");
  $map=[]; foreach($st as $r)$map[$r['d']]=(int)$r['c'];
  foreach($map as $d=>$c){ $diff=(int)round((strtotime($d)-strtotime(date('Y-m-d')))/86400); $spark[6+$diff]=$c; }
}catch(Throwable $e){}
$o['spark']=$spark;

/* قائمة تنبيهات سريعة */
$alerts=[];
try{
  foreach($pdo->query("SELECT id, request_number FROM complaints WHERE priority='critical' AND status NOT IN ('closed','cancelled','rejected','resolved') ORDER BY created_at DESC LIMIT 3") as $r)
    $alerts[]=['ic'=>'fa-bolt','c'=>'#ef4444','t'=>'بلاغ حرج','d'=>'بلاغ #'.$r['request_number'],'u'=>BASE_URL.'/complaints/view.php?id='.$r['id']];
}catch(Throwable $e){}
try{
  foreach($pdo->query("SELECT s.next_due, a.tag_number FROM pm_schedules s LEFT JOIN assets a ON a.id=s.asset_id WHERE s.is_active=1 AND s.next_due<CURDATE() ORDER BY s.next_due LIMIT 2") as $r)
    $alerts[]=['ic'=>'fa-calendar-xmark','c'=>'#f59e0b','t'=>'صيانة متأخرة','d'=>($r['tag_number']?:'أصل').' — '.$r['next_due'],'u'=>BASE_URL.'/reports/maintenance/overview.php'];
}catch(Throwable $e){}
$o['alerts']=$alerts;

echo json_encode($o);