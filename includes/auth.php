<?php
// /includes/auth.php
declare(strict_types=1);

/**
 * Authentication & authorization helpers for Eldvar.
 * - Uses session user_id.
 * - Caches role for a short TTL (60s) to reduce DB hits (per-user cache).
 * - Never caches "player" for guests to avoid sticky guest state.
 * - Gates (ACP/wiki) force a fresh DB read to avoid promotion lag.
 * - No SHOW TABLES check: we query users directly and swallow errors.
 */

if (!defined('BASE_URL')) { require_once __DIR__ . '/../config/config.php'; }
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/** Get PDO (let infra errors bubble up) */
function get_pdo_checked(): PDO {
  return get_pdo();
}

/** Session-based user id */
function current_user_id(): ?int {
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/** Normalize role strings & legacy aliases */
function _normalize_role(?string $role): string {
  $r = strtolower(trim((string)$role));
  if ($r === 'govenor') $r = 'governor';   // legacy typo
  if ($r === '')        $r = 'player';
  return $r;
}

/**
 * Get current user's role.
 * @param bool $fresh When true, bypass session cache and hit DB.
 * @return string Canonical role id (player, supporter, helper, moderator, admin, governor, librarian)
 */
function current_user_role(bool $fresh = false): string {
  $now = time();
  $ttl = 60; // seconds
  $uid = current_user_id();

  // Use cache only when:
  // - not forcing fresh,
  // - a user is logged in,
  // - cache belongs to THIS uid,
  // - cache still valid.
  if (
    !$fresh &&
    $uid &&
    isset($_SESSION['role'], $_SESSION['role_cached_at'], $_SESSION['role_uid']) &&
    (int)$_SESSION['role_uid'] === (int)$uid &&
    ($now - (int)$_SESSION['role_cached_at'] < $ttl)
  ) {
    return (string)$_SESSION['role'];
  }

  // Guest: do NOT cache "player" (avoids sticky-guest after login)
  if (!$uid) {
    return 'player';
  }

  $role = 'player';
  try {
    $pdo  = get_pdo_checked();
    $stmt = $pdo->prepare('SELECT role FROM `users` WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $got  = $stmt->fetchColumn();

    if (is_string($got) && $got !== '') {
      $role = _normalize_role($got);
    }
  } catch (Throwable $e) {
    // Optional: error_log('current_user_role error: ' . $e->getMessage());
    $role = 'player';
  }

  // Cache per-user
  $_SESSION['role'] = $role;
  $_SESSION['role_cached_at'] = $now;
  $_SESSION['role_uid'] = (int)$uid;

  return $role;
}

/** Clear role cache (useful after promotion or role edits) */
function role_cache_clear(): void {
  unset($_SESSION['role'], $_SESSION['role_cached_at'], $_SESSION['role_uid']);
}

/** Logged-in? */
function is_logged_in(): bool {
  return current_user_id() !== null;
}

/** Staff roles (for ACP) */
function is_staff_role(string $role): bool {
  $r = _normalize_role($role);
  return in_array($r, ['admin', 'governor'], true);
}

/** Wiki editors: librarian + admin + governor */
function is_wiki_editor(?string $role = null): bool {
  $r = _normalize_role($role ?? current_user_role(true)); // force fresh for wiki checks
  return in_array($r, ['librarian', 'admin', 'governor'], true);
}

/**
 * Require login, else redirect (or inline fallback if headers sent)
 * Includes a small guard so public auth pages don't loop if this is called there.
 */
function require_login(): void {
  // Guard: never redirect from the public auth pages
  $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
  if (in_array($script, ['login.php', 'register.php'], true)) {
    return;
  }

  if (is_logged_in()) return;

  $loginUrl = BASE_URL . '/login.php';
  if (!headers_sent()) {
    header('Location: ' . $loginUrl, true, 302);
    exit;
  }

  http_response_code(401);
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Login Required</title></head><body>'
     . '<p>You need to <a href="' . htmlspecialchars($loginUrl) . '">log in</a> to view this page.</p>'
     . '</body></html>';
  exit;
}

/**
 * Generic role-based gate.
 * @param array $allowed List of allowed roles (any casing/aliases ok)
 * @param bool  $fresh   Force a fresh DB read (recommended for admin/wiki)
 */
function require_roles(array $allowed, bool $fresh = true): void {
  require_login();

  $role = _normalize_role(current_user_role($fresh));
  $allowed_norm = array_map('_normalize_role', $allowed);

  if (!in_array($role, $allowed_norm, true)) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>403 — Forbidden</title></head><body>'
       . '<h2>403 — Forbidden</h2>'
       . '<p>Your role (<strong>' . htmlspecialchars($role) . '</strong>) does not have access to this page.</p>'
       . '</body></html>';
    exit;
  }
}

/** Admin Control Panel gate: admin + governor (fresh read) */
function require_acp(): void {
  require_roles(['admin', 'governor'], true);
}
