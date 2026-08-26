<?php
/**
 * inventory/api/session_extend.php — تمديد تاريخ نهاية الجلسة
 * POST { session_id, new_end_date, reason }
 */
require_once dirname(__DIR__,2).'/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_admin() && !(function_exists('can') && can('inventory.create','manage')))
    json_response(['ok'=>false,'error'=>'forbidden'],403);

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$sid  = (int)($input['session_id'] ?? 0);
$new  = trim($input['new_end_date'] ?? '');
$reason = trim($input['reason'] ?? '');

if (!$sid)  json_response(['ok'=>false,'error'=>'missing_session'],400);
if (!$new)  json_response(['ok'=>false,'error'=>'missing_date'],400);
if (!$reason) json_response(['ok'=>false,'error'=>'reason_required','msg'=>'السبب إلزامي'],422);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$new)) json_response(['ok'=>false,'error'=>'bad_date'],400);

$s = $pdo->prepare("SELECT id, end_date, status, start_date FROM inventory_sessions WHERE id=?");
$s->execute([$sid]);
$session = $s->fetch(PDO::FETCH_ASSOC);
if (!$session) json_response(['ok'=>false,'error'=>'not_found'],404);

if (!in_array($session['status'], ['active','planning','review']))
    json_response(['ok'=>false,'error'=>'wrong_status','msg'=>'لا يمكن التمديد في حالة '.$session['status']],422);

$old = $session['end_date'] ?: 'مفتوحة';
if ($session['end_date'] && $new <= $session['end_date'])
    json_response(['ok'=>false,'error'=>'not_extended','msg'=>'التاريخ الجديد يجب أن يتجاوز التاريخ الحالي ('.$session['end_date'].')'],422);

$pdo->prepare("UPDATE inventory_sessions SET end_date=? WHERE id=?")->execute([$new,$sid]);

log_activity('inventory.session.extend', "session:$sid", "من $old إلى $new — $reason");

json_response(['ok'=>true,'old'=>$old,'new'=>$new]);
