<?php
declare(strict_types=1);

/* battle_trace.php — traces includes + DB (no DDL) and logs fatals */
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: text/plain; charset=utf-8');

$LOG = __DIR__ . '/battle_trace.log';
ini_set('log_errors','1');
ini_set('error_log', $LOG);
ini_set('display_errors','0'); // force into the log
error_reporting(E_ALL);

/* Flush fatal errors to the log file */
register_shutdown_function(function() use ($LOG) {
  $e = error_get_last();
  if ($e) {
    error_log(sprintf("[FATAL] %s in %s:%d", $e['message'], $e['file'], $e['line']));
    echo "\n(FATAL logged) Check: {$LOG}\n";
  }
});

function T($msg){ error_log($msg); echo $msg."\n"; }

/* 1) Config + DB bootstrap (no DDL) */
T("[1] loading config");
require __DIR__ . '/config/config.php';

T("[2] loading db.php");
require __DIR__ . '/config/db.php';

T("[3] get_pdo()");
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

T("[4] mysql version: ".($pdo->query("SELECT VERSION()")->fetchColumn() ?: 'unknown'));

/* 2) (Optional) auth include — use hardened version you pasted */
T("[5] include includes/auth.php (optional)");
if (file_exists(__DIR__.'/includes/auth.php')) {
  try { require __DIR__.'/includes/auth.php'; T("[5] auth.php included"); }
  catch (Throwable $e){ T("[5] auth include threw: ".$e->getMessage()); }
} else {
  T("[5] auth.php not found");
}

/* 3) Determine user id (prefer session) */
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if (function_exists('current_user_id')) {
  try { $uid2 = (int)(current_user_id() ?? 0); if ($uid2) $uid = $uid2; } catch (Throwable $e) { T("[6] current_user_id() error: ".$e->getMessage()); }
}
T("[6] user_id = ".$uid);

/* 4) Check tables exist (NO CREATEs) */
function tbl_exists(PDO $pdo, string $name): bool {
  $st=$pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$name]); return (bool)$st->fetchColumn();
}
$have_mobs = tbl_exists($pdo,'mobs');              T("[7] mobs table: ".($have_mobs?'yes':'NO'));
$have_battles = tbl_exists($pdo,'battles');        T("[7] battles table: ".($have_battles?'yes':'NO'));
$have_turns = tbl_exists($pdo,'battle_turns');     T("[7] battle_turns table: ".($have_turns?'yes':'NO'));

/* 5) Tiny read queries to ensure SELECTs work */
if ($have_mobs) {
  try { $one = $pdo->query("SELECT id,name FROM mobs ORDER BY id ASC LIMIT 1")->fetch(); T("[8] first mob: ".json_encode($one)); }
  catch (Throwable $e){ T("[8] mobs SELECT error: ".$e->getMessage()); }
}
if ($uid && $have_battles) {
  try {
    $st=$pdo->prepare("SELECT id,status FROM battles WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]); $b=$st->fetch(); T("[9] user battle: ".json_encode($b));
  } catch (Throwable $e){ T("[9] battles SELECT error: ".$e->getMessage()); }
}

/* 6) Includes — header/sidebar/footer (wrapped) */
T("[10] include header");
try { if (file_exists(__DIR__.'/includes/header.php')) include __DIR__.'/includes/header.php'; } catch (Throwable $e){ T("[10] header error: ".$e->getMessage()); }

T("[11] include sidebar");
try { if (file_exists(__DIR__.'/includes/sidebar.php')) include __DIR__.'/includes/sidebar.php'; } catch (Throwable $e){ T("[11] sidebar error: ".$e->getMessage()); }

T("[12] include footer");
try { if (file_exists(__DIR__.'/includes/footer.php')) include __DIR__.'/includes/footer.php'; } catch (Throwable $e){ T("[12] footer error: ".$e->getMessage()); }

T("[13] TRACE COMPLETE");
