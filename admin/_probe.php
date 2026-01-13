<?php
declare(strict_types=1);

/* Loud errors */
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n";
  }
});

header('Content-Type: text/plain; charset=utf-8');
function p($k,$v=null){ echo is_null($v) ? "$k\n" : "$k: $v\n"; }

/* Start session FIRST, then include files */
p('P0 start');
if (session_status() === PHP_SESSION_NONE) session_start();

p('SESSION_NAME', session_name());
p('SESSION_ID', session_id());
p('HTTP_COOKIE', $_SERVER['HTTP_COOKIE'] ?? '(none)');

p('P1 require config');
require_once __DIR__ . '/../config/config.php';
p('OK config');
p('BASE_URL', defined('BASE_URL') ? BASE_URL : '(undefined)');

p('P2 require db');
require_once __DIR__ . '/../config/db.php';
p('OK db');

p('P3 get PDO');
$pdo = get_pdo();
p('PDO OK', is_object($pdo) ? 'yes' : 'no');

p('P4 require auth');
require_once __DIR__ . '/../includes/auth.php';
p('OK auth');

/* Now read session fields */
$uid = $_SESSION['user_id'] ?? null;
p('uid', var_export($uid,true));

/* Show DB role row for this uid, if any */
$dbRole = '(no uid)';
if ($uid) {
  try {
    $st = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $dbRole = $row ? json_encode($row) : '(not found)';
  } catch (Throwable $e) {
    $dbRole = 'DB error: ' . $e->getMessage();
  }
}
p('DB users row', $dbRole);

/* Role via helpers, both fresh and cached */
$r_fresh  = function_exists('current_user_role') ? current_user_role(true)  : '(no func)';
$r_cache  = function_exists('current_user_role') ? current_user_role(false) : '(no func)';
p('role_fresh', $r_fresh);
p('role_cached', $r_cache);

p('DONE');
