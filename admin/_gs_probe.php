<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo "DB: " . DB_NAME . "\n\n";

try {
  $rows = $pdo->query("SELECT `key`,`value` FROM game_settings ORDER BY `key`")->fetchAll();
  if (!$rows) { echo "(no rows in game_settings)\n"; }
  foreach ($rows as $r) {
    echo $r['key'] . " = " . $r['value'] . "\n";
  }
} catch (Throwable $e) {
  echo "ERROR reading game_settings: " . $e->getMessage() . "\n";
}

echo "\nDirect check:\n";
try {
  $st = $pdo->prepare("SELECT `value` FROM game_settings WHERE `key` = 'wins_required_per_floor' LIMIT 1");
  $st->execute();
  $v = $st->fetchColumn();
  var_dump(['wins_required_per_floor' => $v]);
} catch (Throwable $e) {
  echo "ERROR single read: " . $e->getMessage() . "\n";
}
