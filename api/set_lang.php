<?php
/**
 * api/set_lang.php — تعيين لغة الجلسة
 */
require_once dirname(__DIR__) . '/config.php';
require_login();

$lang = $_GET['lang'] ?? $_POST['lang'] ?? 'ar';
set_lang($lang);

json_response(['ok' => true, 'lang' => current_lang()]);