<?php
declare(strict_types=1);

// Mark this page as public so shared includes can't force a redirect loop.
define('PUBLIC_PAGE', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php'; // centralizes session + helpers
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- CSRF (scoped to login) ---------- */
function login_csrf_token(): string {
  if (!empty($_SESSION['login_csrf'])) return $_SESSION['login_csrf'];
  try { $_SESSION['login_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['login_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['login_csrf'];
}
function login_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['login_csrf']) && hash_equals($_SESSION['login_csrf'], $t);
}

/* ---------- Already logged in? ---------- */
if (isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . '/dashboard.php', true, 302);
  exit;
}

/* ---------- Handle POST ---------- */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!login_csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid session token. Please refresh and try again.';
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
      $error = 'Please enter your username and password.';
    } else {
      try {
        $pdo = get_pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string)$user['password'])) {
          // Secure the session id for this login
          session_regenerate_id(true);

          // Persist identity
          $_SESSION['user_id']  = (int)$user['id'];
          $_SESSION['username'] = (string)$user['username'];

          // Clear any stale role cache so ACP/wiki immediately see the DB role
          if (function_exists('role_cache_clear')) { role_cache_clear(); }

          // Optional: mark last_seen (column exists in your schema)
          try {
            $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([(int)$user['id']]);
          } catch (Throwable $e) { /* ignore */ }

          // Ensure session is written before redirect to avoid rare loops
          if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

          header('Location: ' . BASE_URL . '/dashboard.php', true, 302);
          exit;
        } else {
          $error = 'Invalid username or password.';
        }
      } catch (Throwable $e) {
        $error = 'Login failed. Please try again.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <main class="app-main">
<section class="auth-wrap">
  <div class="auth-bg"></div>
  <div class="auth-sparkles">
    <!-- a few drifting dots -->
    <span class="sparkle" style="left:5%; animation-delay:-2s;"></span>
    <span class="sparkle g" style="left:20%; animation-delay:-6s;"></span>
    <span class="sparkle" style="left:55%; animation-delay:-9s;"></span>
    <span class="sparkle g" style="left:80%; animation-delay:-4s;"></span>
  </div>

  <div class="auth-card">
    <!-- Promo / art -->
    <aside class="auth-hero-pane">
      <a href="<?= BASE_URL ?>/" class="auth-logo">
        <span class="glyph">E</span> Eldvar
      </a>
      <h2 class="pixel-title" style="margin:16px 0 6px;">Welcome back, Adventurer</h2>
      <p class="lead">Log in to continue your climb, claim rewards, and chat with your guild.</p>
      <ul class="auth-list">
        <li><span class="dot"></span> Challenge towers across the Mystic Harshlands and beyond</li>
        <li><span class="dot"></span> Battle unique mobs and earn rare loot</li>
        <li><span class="dot"></span> Track progress across regions on the world map</li>
      </ul>
    </aside>

    <!-- Form -->
    <div class="auth-form-pane">
      <h1 class="auth-title pixel-title">Login</h1>
      <p class="auth-sub">Enter your credentials to enter Eldvar.</p>

      <?php if ($error): ?>
        <p class="alert error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form method="post" class="form-grid" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(login_csrf_token()) ?>">

        <div class="form-row">
          <label class="muted">Username</label>
          <div class="field">
            <input type="text" name="username" autocomplete="username" required placeholder="Your hero name">
          </div>
        </div>

        <div class="form-row">
          <label class="muted">Password</label>
          <div class="field">
            <input id="pw" type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
            <button type="button" class="eye" onclick="const p=document.getElementById('pw'); p.type=p.type==='password'?'text':'password'; this.textContent = p.type==='password'?'Show':'Hide';">Show</button>
          </div>
        </div>

        <div class="auth-actions">
          <a class="link-muted" href="#">Forgot password?</a>
          <button type="submit" class="pixel-btn">Enter the Realm</button>
        </div>
      </form>

      <p class="auth-foot">New here? <a href="<?= BASE_URL ?>/register.php">Create an account</a></p>
    </div>
  </div>
</section>
    </main>
    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
