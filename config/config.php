<?php
// config/config.php
declare(strict_types=1);

/**
 * Canonicalize the site host to avoid session split across www/apex.
 * We choose https://eldvar.com as the single base.
 */
const CANON_SCHEME = 'https';
const CANON_HOST   = 'eldvar.com';
const CANON_URL    = CANON_SCHEME . '://' . CANON_HOST;

// If request host/scheme differs, 301 to canonical before anything else.
$reqHost   = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$httpsLike = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443;
// Support proxies (Cloudflare, ALB) if present:
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
  $httpsLike = stripos((string)$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false;
}
$reqScheme = $httpsLike ? 'https' : 'http';

if ($reqHost && ($reqHost !== CANON_HOST || $reqScheme !== CANON_SCHEME)) {
  $uri = $_SERVER['REQUEST_URI'] ?? '/';
  header('Location: ' . CANON_URL . $uri, true, 301);
  exit;
}

// App-wide URLs
define('ROOT_URL', CANON_URL);
define('BASE_URL', ROOT_URL);

// Database credentials
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'REDACTED');
define('DB_USER', 'REDACTED');
define('DB_PASS', 'REDACTED');
define('DB_CHARSET', 'utf8mb4');

// Toggle this on a dev machine only:
define('APP_DEBUG', false);

/**
 * Helper for building absolute asset URLs.
 * Usage: <link rel="stylesheet" href="<?= asset_url('public/css/style.css') ?>">
 */
function asset_url(string $path): string {
  return ROOT_URL . '/' . ltrim($path, '/');
}
