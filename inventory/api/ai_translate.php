<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/includes/_utils.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error'=>'method'],405);
if (!is_admin() && !(function_exists('can') && can('inventory.locations','manage'))) json_response(['error'=>'forbidden'],403);
if (!ai_is_ready()) json_response(['error'=>'ai_not_ready'],503);
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$text = trim($input['text'] ?? '');
$to   = ($input['to'] ?? 'ar') === 'en' ? 'en' : 'ar';
if ($text === '') json_response(['error'=>'empty'],400);
$s = ai_settings();
$prompt = $to === 'ar'
  ? "Translate this hospital location name to Arabic. Output ONLY the translated text.\nText: $text"
  : "Translate this hospital location name to English. Output ONLY the translated text.\nText: $text";
$ch = curl_init($s['base_url'] . '/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode(['model'=>$s['model'],'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.3,'max_tokens'=>100]),
  CURLOPT_TIMEOUT => 10,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$s['api_key']],
]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200 || !$res) json_response(['error'=>'api_fail','code'=>$code],500);
$d = json_decode($res, true);
$t = trim($d['choices'][0]['message']['content'] ?? '', " \n\r\t\"'`");
json_response(['ok'=>true,'translated'=>$t]);