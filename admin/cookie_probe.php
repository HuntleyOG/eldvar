<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

echo "WHERE: /__cookie_probe.php\n";
echo "SESSION STATUS: " . session_status() . "\n";
echo "SESSION NAME:   " . session_name() . "\n";
echo "SESSION ID:     " . session_id() . "\n";
echo "user_id:        " . var_export($_SESSION['user_id'] ?? null, true) . "\n";
echo "HTTP_COOKIE:    " . ($_SERVER['HTTP_COOKIE'] ?? '(none)') . "\n\n";

$keys = [
  'session.save_path','session.save_handler',
  'session.cookie_path','session.cookie_domain','session.cookie_secure','session.cookie_samesite',
  'session.use_cookies','session.use_only_cookies','session.use_trans_sid'
];
foreach ($keys as $k) {
  echo $k . ": " . var_export(ini_get($k), true) . "\n";
}
