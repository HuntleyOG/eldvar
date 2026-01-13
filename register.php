<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php'; // centralizes session cookie params + helpers
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- CSRF (scoped to register) ---------- */
function reg_csrf_token(): string {
  if (!empty($_SESSION['reg_csrf'])) return $_SESSION['reg_csrf'];
  try { $_SESSION['reg_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['reg_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['reg_csrf'];
}
function reg_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['reg_csrf']) && hash_equals($_SESSION['reg_csrf'], $t);
}

/* small helpers */
function column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]); return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}

/* ---------- Already logged in? ---------- */
if (isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . '/dashboard.php', true, 302);
  exit;
}

/* ---------- Handle POST ---------- */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!reg_csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid session token. Please refresh and try again.';
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    // Basic validations
    if ($username === '' || $password === '' || $confirm === '') {
      $error = 'Please fill out all fields.';
    } elseif ($password !== $confirm) {
      $error = 'Passwords do not match.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
      $error = 'Username must be 3–50 characters: letters, numbers, or underscore.';
    }

    if ($error === '') {
      try {
        $pdo = get_pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Ensure uniqueness (users.username is UNIQUE in your schema)
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
          $error = 'Username already exists.';
        } else {
          $hash = password_hash($password, PASSWORD_DEFAULT);

          // Insert: schema defaults will fill other columns (role defaults to 'player')
          $ins = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
          $ins->execute([$username, $hash]);

          $uid = (int)$pdo->lastInsertId();

          // Seed normalized user_skills rows for this user (1/0xp to start)
          try {
            $pdo->prepare("
              INSERT INTO user_skills (user_id, skill_id, level, xp)
              SELECT ?, s.id, 1, 0 FROM skills s
              ON DUPLICATE KEY UPDATE level = user_skills.level
            ")->execute([$uid]);
          } catch (Throwable $e) {
            // non-fatal
          }

          // Ensure overall_xp exists then initialize (optional)
          try {
            if (column_exists($pdo, 'users', 'overall_xp')) {
              $pdo->prepare("UPDATE users SET overall_xp = 0 WHERE id = ?")->execute([$uid]);
            }
          } catch (Throwable $e) {}

          // Create the session for the new user
          session_regenerate_id(true);
          $_SESSION['user_id']  = $uid;
          $_SESSION['username'] = $username;

          // Clear role cache (consistency with login)
          unset($_SESSION['role'], $_SESSION['role_cached_at']);

          header('Location: ' . BASE_URL . '/dashboard.php', true, 302);
          exit;
        }
      } catch (Throwable $e) {
        $error = 'Registration failed. Please try again.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Register — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <main class="app-main">
<section class="auth-wrap">
  <div class="auth-bg"></div>
  <div class="auth-sparkles">
    <span class="sparkle" style="left:8%; animation-delay:-1s;"></span>
    <span class="sparkle g" style="left:30%; animation-delay:-7s;"></span>
    <span class="sparkle" style="left:62%; animation-delay:-10s;"></span>
    <span class="sparkle g" style="left:85%; animation-delay:-5s;"></span>
  </div>

  <div class="auth-card">
    <!-- Promo / art -->
    <aside class="auth-hero-pane">
      <a href="<?= BASE_URL ?>/" class="auth-logo">
        <span class="glyph">E</span> Eldvar
      </a>
      <h2 class="pixel-title" style="margin:16px 0 6px;">Forge Your Legend</h2>
      <p class="lead">Create your account and begin your ascent through the towers.</p>
      <ul class="auth-list">
        <li><span class="dot"></span> Choose your path across Yulon Forest, Reichal, Undar, and more</li>
        <li><span class="dot"></span> Earn XP and gold; unlock deeper floors</li>
        <li><span class="dot"></span> MMO-style chat, profiles, and progression</li>
      </ul>
    </aside>

    <!-- Form -->
    <div class="auth-form-pane">
      <h1 class="auth-title pixel-title">Create Account</h1>
      <p class="auth-sub">It takes less than a minute.</p>

      <?php if ($error): ?>
        <p class="alert error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form method="post" class="form-grid" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(reg_csrf_token()) ?>">

        <div class="form-row">
          <label class="muted">Username</label>
          <div class="field">
            <input type="text" name="username" autocomplete="username" required placeholder="Pick a unique name">
          </div>
        </div>

        <div class="form-row">
          <label class="muted">Password</label>
          <div class="field">
            <input id="npw" type="password" name="password" autocomplete="new-password" required placeholder="Create a password">
            <button type="button" class="eye" onclick="const p=document.getElementById('npw'); p.type=p.type==='password'?'text':'password'; this.textContent = p.type==='password'?'Show':'Hide';">Show</button>
          </div>
          <div class="hint">8+ characters recommended</div>
        </div>

        <div class="form-row">
          <label class="muted">Confirm Password</label>
          <div class="field">
            <input id="cpw" type="password" name="confirm_password" autocomplete="new-password" required placeholder="Repeat password">
            <button type="button" class="eye" onclick="const p=document.getElementById('cpw'); p.type=p.type==='password'?'text':'password'; this.textContent = p.type==='password'?'Show':'Hide';">Show</button>
          </div>
        </div>

        <div class="auth-actions">
          <span></span>
          <button type="submit" class="pixel-btn">Create Account</button>
        </div>
      </form>

      <p class="auth-foot">Already have an account? <a href="<?= BASE_URL ?>/login.php">Login</a></p>
    </div>
  </div>
</section>

    </main>
    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
