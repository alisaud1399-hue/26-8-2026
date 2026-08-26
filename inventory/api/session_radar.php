<?php
/**
 * inventory/api/session_radar.php — رادار مراقبة الجلسة (Admin)
 * GET ?session_id= → لقطة حية | POST {action} → أوامر تحكم
 */
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/includes/session_controls.php';
header('Content-Type: application/json; charset=utf-8');
if (!(is_admin() || (function_exists('can') && can('inventory.create','manage')))) json_response(['ok'=>false,'error'=>'forbidden'],403);
$me=(int)(current_user()['id']??0);
smc_schema($pdo);
$input=json_decode(file_get_contents('php://input'),true)?:$_POST;
$sid=(int)($_REQUEST['session_id']??$input['session_id']??0);
if(!$sid) json_response(['ok'=>false,'error'=>'missing'],400);
$action=$input['action']??'snapshot';

function smc_notify(PDO $pdo,int $uid,string $type,string $title,string $body,string $link,int $sid){
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES(?,?,?,?,?,'session',?)")
        ->execute([$uid,$type,$title,$body,$link,$sid]);
}

if($action!=='snapshot'){
    $uid=(int)($input['user_id']??0); if(!$uid) json_response(['ok'=>false,'error'=>'no_user'],400);
    $link=BASE_URL."/inventory/scan.php?session=$sid";
    try{
    switch($action){
        case 'kick':
            $reason=trim($input['reason']??'');
            if(!$reason) json_response(['ok'=>false,'error'=>'reason_required','msg'=>'السبب إلزامي للإخراج الإجباري'],422);
            $room_id=(int)($input['room_id']??0); $block=(int)($input['block']??0);
            $n=smc_force_release_locks($pdo,$sid,$uid,$me,'إخراج إجباري: '.$reason);
            if($block&&$room_id){ $c=smc_get($pdo,$sid,$uid); $c['blocked_rooms'][]=$room_id; smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_unique($c['blocked_rooms']))],$me); }
            smc_notify($pdo,$uid,'warning','🚪 أُخرجت من الغرفة','تم إخراجك من غرفة الجرد بواسطة مدير الأصول.\nالسبب: '.$reason,$link,$sid);
            log_activity('inventory.radar.kick', "session:$sid user:$uid", $reason);
            json_response(['ok'=>true,'released'=>$n]);
        case 'suspend':
            $reason=trim($input['reason']??'');
            if(!$reason) json_response(['ok'=>false,'error'=>'reason_required','msg'=>'السبب إلزامي للتعليق'],422);
            smc_save($pdo,$sid,$uid,['suspended'=>1,'note'=>$reason],$me);
            smc_force_release_locks($pdo,$sid,$uid,$me,'تعليق العضو: '.$reason);
            smc_notify($pdo,$uid,'error','⛔ عُلّقت مشاركتك','قام مدير الأصول بتعليق مشاركتك في جلسة الجرد.\nالسبب: '.$reason,$link,$sid);
            log_activity('inventory.radar.suspend', "session:$sid user:$uid", $reason);
            json_response(['ok'=>true]);
        case 'unsuspend':
            smc_save($pdo,$sid,$uid,['suspended'=>0],$me);
            smc_notify($pdo,$uid,'success','✅ فُكّ تعليقك','يمكنك الآن متابعة الجرد.',$link,$sid);
            log_activity('inventory.radar.unsuspend', "session:$sid user:$uid", '');
            json_response(['ok'=>true]);
        case 'block_room':
            $room_id=(int)($input['room_id']??0); if(!$room_id) json_response(['ok'=>false],400);
            $reason=trim($input['reason']??'');
            if(!$reason) json_response(['ok'=>false,'error'=>'reason_required','msg'=>'السبب إلزامي لحجب الغرفة'],422);
            $c=smc_get($pdo,$sid,$uid); $c['blocked_rooms'][]=$room_id;
            smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_unique($c['blocked_rooms']))],$me);
            log_activity('inventory.radar.block_room', "session:$sid user:$uid room:$room_id", $reason);
            json_response(['ok'=>true]);
        case 'unblock_room':
            $room_id=(int)($input['room_id']??0); $c=smc_get($pdo,$sid,$uid);
            smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_filter($c['blocked_rooms'],fn($r)=>(int)$r!==$room_id))],$me);
            log_activity('inventory.radar.unblock_room', "session:$sid user:$uid room:$room_id", '');
            json_response(['ok'=>true]);
        case 'extend':
            $minutes=(int)($input['minutes']??30);
            $n=smc_extend_lock($pdo,$sid,$uid,$minutes);
            log_activity('inventory.radar.extend', "session:$sid user:$uid", "من $minutes دقيقة");
            json_response(['ok'=>true,'extended'=>$n]);
        case 'reset_suspends':
            $st = $pdo->prepare("DELETE e FROM room_lock_events e JOIN room_inventory_locks l ON l.id=e.lock_id WHERE l.session_id=? AND l.locked_by=? AND e.event_type='suspended'");
            $st->execute([$sid,$uid]);
            $n = $st->rowCount();
            smc_notify($pdo,$uid,'success','🔄 تم إعادة تعيين التعليق','يمكنك الآن التعليق مرة أخرى.',$link,$sid);
            log_activity('inventory.radar.reset_suspends', "session:$sid user:$uid", "$n تعليقات");
            json_response(['ok'=>true,'reset'=>$n]);
        default: json_response(['ok'=>false,'error'=>'bad_action'],400);
    }
    }catch(Throwable $e){
        json_response(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()],500);
    }
}

/* ── لقطة حية ── */
$members=$pdo->prepare("SELECT m.user_id, u.full_name FROM inventory_session_members m JOIN users u ON u.id=m.user_id WHERE m.session_id=?");
$members->execute([$sid]); $members=$members->fetchAll(PDO::FETCH_ASSOC);

$locks=$pdo->prepare("SELECT l.*, r.name rname, r.name_en rname_en FROM room_inventory_locks l LEFT JOIN item_locations r ON r.id=l.room_id WHERE l.session_id=? AND l.status='active'");
$locks->execute([$sid]); $lockByUser=[]; foreach($locks->fetchAll(PDO::FETCH_ASSOC) as $L) $lockByUser[(int)$L['locked_by']]=$L;

$done=$pdo->prepare("SELECT locked_by, COUNT(*) c FROM room_inventory_locks WHERE session_id=? AND status='completed' GROUP BY locked_by");
$done->execute([$sid]); $doneByUser=[]; foreach($done->fetchAll(PDO::FETCH_ASSOC) as $r) $doneByUser[(int)$r['locked_by']]=(int)$r['c'];

$aud=$pdo->prepare("SELECT audited_by, action, COUNT(*) c, MAX(audited_at) last_at FROM inventory_audits WHERE session_id=? GROUP BY audited_by, action");
$aud->execute([$sid]); $stats=[];
foreach($aud->fetchAll(PDO::FETCH_ASSOC) as $r){
    $u=(int)$r['audited_by'];
    $stats[$u]['total']=($stats[$u]['total']??0)+(int)$r['c'];
    $stats[$u][$r['action']]=($stats[$u][$r['action']]??0)+(int)$r['c'];
    $stats[$u]['last_at']=max($stats[$u]['last_at']??'',$r['last_at']);
}

$events=$pdo->prepare("SELECT e.event_type, e.note, e.created_at, u.full_name, r.name rname, r.name_en rname_en FROM room_lock_events e LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN item_locations r ON r.id=e.room_id WHERE e.session_id=? ORDER BY e.id DESC LIMIT 20");
$events->execute([$sid]); $events=$events->fetchAll(PDO::FETCH_ASSOC);

/* حساب الوقت التراكمي لكل مستخدم عبر كل الغرف */
function radar_cumulative(PDO $pdo, int $sid, int $uid): int {
    $total = 0;
    $locks = $pdo->prepare("SELECT id, status FROM room_inventory_locks WHERE session_id=? AND locked_by=? ORDER BY id");
    $locks->execute([$sid, $uid]);
    foreach ($locks->fetchAll(PDO::FETCH_ASSOC) as $lk) {
        $evts = $pdo->prepare("SELECT event_type, created_at FROM room_lock_events WHERE lock_id=? ORDER BY id");
        $evts->execute([(int)$lk['id']]);
        $events = $evts->fetchAll(PDO::FETCH_ASSOC);
        $started_at = null;
        foreach ($events as $ev) {
            $time = strtotime($ev['created_at']);
            if ($ev['event_type'] === 'opened' || $ev['event_type'] === 'resumed') {
                $started_at = $time;
            } elseif (in_array($ev['event_type'], ['suspended','completed','taken_over','expired']) && $started_at !== null) {
                $total += max(0, $time - $started_at);
                $started_at = null;
            }
        }
        if ($started_at !== null && $lk['status'] === 'active') {
            $total += max(0, time() - $started_at);
        }
    }
    return $total;
}

$now=time(); $out=[];
foreach($members as $m){
    $u=(int)$m['user_id']; $ctl=smc_get($pdo,$sid,$u); $L=$lockByUser[$u]??null; $st=$stats[$u]??[];
    $last=$st['last_at']??null; $idle=$last?round(($now-strtotime($last))/60):null;
    $alerts=[];
    if($ctl['suspended']) $alerts[]='suspended';
    if($L && !empty($L['expires_at']) && (strtotime($L['expires_at'])-$now)<300) $alerts[]='expiring';
    if($L && $idle!==null && $idle>10) $alerts[]='idle';
    $out[]=[
        'user_id'=>$u,'name'=>$m['full_name'],'suspended'=>(int)$ctl['suspended'],'blocked_rooms'=>$ctl['blocked_rooms'],
        'lock'=>$L?['room_id'=>(int)$L['room_id'],'room'=>$L['rname_en']?:$L['rname'],'since'=>$L['resumed_at']?:$L['locked_at'],'expires_at'=>$L['expires_at']??null]:null,
        'completed_rooms'=>$doneByUser[$u]??0,
        'stats'=>['total'=>$st['total']??0,'confirmed'=>$st['confirmed']??0,'missing'=>$st['missing']??0,'location_changed'=>$st['location_changed']??0,'custody_changed'=>$st['custody_changed']??0,'condition_damaged'=>$st['condition_damaged']??0],
        'last_at'=>$last,'idle_min'=>$idle,'alerts'=>$alerts,
        'cumulative_sec'=>radar_cumulative($pdo,$sid,$u),
    ];
}

/* ═══ بيانات الغرف ═══ */
$rooms=$pdo->prepare("SELECT l.id,l.room_id,l.locked_by,l.status,l.locked_at,l.completed_at,l.expires_at,
    il.name AS rname, il.name_en AS rname_en,
    u.full_name AS locker_name
    FROM room_inventory_locks l
    LEFT JOIN item_locations il ON il.id=l.room_id
    LEFT JOIN users u ON u.id=l.locked_by
    WHERE l.session_id=? ORDER BY l.id DESC");
$rooms->execute([$sid]); $roomsData=$rooms->fetchAll(PDO::FETCH_ASSOC);

$roomStats=$pdo->prepare("SELECT new_location_id AS room_id, action, COUNT(*) c FROM inventory_audits WHERE session_id=? AND new_location_id IS NOT NULL GROUP BY new_location_id, action");
$roomStats->execute([$sid]); $rs=[];
foreach($roomStats->fetchAll(PDO::FETCH_ASSOC) as $r){
    $rid=(int)$r['room_id'];
    $rs[$rid]['total']=($rs[$rid]['total']??0)+(int)$r['c'];
    $rs[$rid][$r['action']]=($rs[$rid][$r['action']]??0)+(int)$r['c'];
}

$roomAssets=$pdo->prepare("SELECT il.id, il.name, il.name_en, COUNT(a.id) asset_count
    FROM item_locations il
    LEFT JOIN assets a ON a.location_id=il.id
    WHERE il.id IN (SELECT DISTINCT room_id FROM room_inventory_locks WHERE session_id=?)
    GROUP BY il.id");
$roomAssets->execute([$sid]); $ra=[];
foreach($roomAssets->fetchAll(PDO::FETCH_ASSOC) as $r) $ra[(int)$r['id']]=['name'=>$r['name'],'name_en'=>$r['name_en'],'count'=>(int)$r['asset_count']];

$roomsOut=[];
foreach($roomsData as $rl){
    $rid=(int)$rl['room_id'];
    $raInfo=$ra[$rid]??['name'=>$rl['rname'],'name_en'=>$rl['rname_en'],'count'=>0];
    $st=$rs[$rid]??[];
    $roomsOut[]=[
        'lock_id'=>(int)$rl['id'],'room_id'=>$rid,
        'name'=>$raInfo['name'],'name_en'=>$raInfo['name_en'],
        'total_assets'=>$raInfo['count'],'audited'=>$st['total']??0,
        'confirmed'=>$st['confirmed']??0,'missing'=>$st['missing']??0,
        'location_changed'=>$st['location_changed']??0,'custody_changed'=>$st['custody_changed']??0,
        'condition_damaged'=>$st['condition_damaged']??0,'surplus'=>$st['surplus']??0,
        'status'=>$rl['status'],'locker_name'=>$rl['locker_name'],'locked_by'=>(int)$rl['locked_by'],
        'locked_at'=>$rl['locked_at'],'completed_at'=>$rl['completed_at'],'expires_at'=>$rl['expires_at'],
    ];
}

/* ═══ التنبيهات المُجمَّعة ═══ */
$alerts=[];
foreach($out as $m){
    if(in_array('suspended',$m['alerts'])) $alerts[]=['type'=>'suspended','level'=>'critical','user_id'=>$m['user_id'],'name'=>$m['name'],'msg'=>($rtl?'عُلّق من الجلسة':'Suspended from session')];
    if(in_array('expiring',$m['alerts'])) $alerts[]=['type'=>'expiring','level'=>'warning','user_id'=>$m['user_id'],'name'=>$m['name'],'msg'=>($rtl?'القفل ينتهي قريباً':'Lock expiring soon')];
    if(in_array('idle',$m['alerts'])) $alerts[]=['type'=>'idle','level'=>'warning','user_id'=>$m['user_id'],'name'=>$m['name'],'msg'=>($rtl?'خامل '.$m['idle_min'].' دقائق':'Idle '.$m['idle_min'].' min')];
}
foreach($roomsOut as $rm){
    if($rm['status']==='active' && !empty($rm['expires_at']) && strtotime($rm['expires_at'])-$now<300 && strtotime($rm['expires_at'])>$now)
        $alerts[]=['type'=>'room_expiring','level'=>'warning','room_id'=>$rm['room_id'],'name'=>$rm['name_en']?:$rm['name'],'msg'=>($rtl?'قفل الغرفة ينتهي قريباً':'Room lock expiring')];
    if($rm['status']==='active' && $rm['locked_at'] && (strtotime($now?$now:date('Y-m-d H:i:s'))-strtotime($rm['locked_at']))>28800)
        $alerts[]=['type'=>'room_stuck','level'=>'critical','room_id'=>$rm['room_id'],'name'=>$rm['name_en']?:$rm['name'],'msg'=>($rtl?'غرفة مفتوحة لأكثر من 8 ساعات':'Room open >8h')];
    if($rm['status']==='active' && $rm['audited']>0 && $rm['missing']>0)
        $alerts[]=['type'=>'room_missing','level'=>'warning','room_id'=>$rm['room_id'],'name'=>$rm['name_en']?:$rm['name'],'msg'=>($rtl?$rm['missing'].' أصول مفقودة في هذه الغرفة':$rm['missing'].' missing assets')];
}
usort($alerts,function($a,$b){$p=['critical'=>0,'warning'=>1,'info'=>2];return($p[$a['level']]??2)-($p[$b['level']]??2);});
if(count($alerts)>50) $alerts=array_slice($alerts,0,50);

/* ═══ سجل الإجراءات الإدارية للجلسة ═══ */
$alog=$pdo->prepare("SELECT al.action, al.target, al.details, al.ip_address, al.created_at, u.full_name
    FROM activity_log al LEFT JOIN users u ON u.id=al.user_id
    WHERE al.target LIKE ? OR al.target LIKE ?
    ORDER BY al.id DESC LIMIT 50");
$alog->execute(["session:$sid", "session:$sid %"]);
$action_log=$alog->fetchAll(PDO::FETCH_ASSOC);

json_response(['ok'=>true,'members'=>$out,'rooms'=>$roomsOut,'events'=>$events,'alerts'=>$alerts,'action_log'=>$action_log,'now'=>date('Y-m-d H:i:s')]);