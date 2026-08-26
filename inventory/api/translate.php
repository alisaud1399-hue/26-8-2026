<?php
/**
 * inventory/api/translate.php — ترجمة AI للأسماء (AJAX)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/locations/_tree.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_ajax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'method'], 405);
}
if (!is_admin() && !(function_exists('can') && can('inventory.locations', 'manage'))) {
    json_response(['error' => 'forbidden'], 403);
}
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$text = trim($input['text'] ?? '');
$to   = $input['to'] ?? 'ar';
if ($text === '') json_response(['error' => 'empty'], 400);
$translated = tree_ai_translate($text, $to === 'ar' ? 'en' : 'ar', $to);
json_response(['ok' => true, 'translated' => $translated]);