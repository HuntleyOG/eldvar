<?php
declare(strict_types=1);
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
session_start();

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = get_pdo();

// Fetch current profile data
$stmt = $pdo->prepare("SELECT display_name, bio, avatar_url, banner_url, status_text FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch();

$notice = '';
$error  = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $display_name = trim($_POST['display_name'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $status_text  = trim($_POST['status_text'] ?? '');
    $avatar_url   = trim($_POST['avatar_url'] ?? '');
    $banner_url   = trim($_POST['banner_url'] ?? '');

    if ($display_name === '') {
        $error = "Display name cannot be empty.";
    } else {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET display_name = ?, bio = ?, status_text = ?, avatar_url = ?, banner_url = ?
            WHERE id = ?
        ");
        $stmt->execute([$display_name, $bio, $status_text, $avatar_url, $banner_url, $_SESSION['user_id']]);
        $notice = "Profile updated successfully.";
        $profile = [
            'display_name' => $display_name,
            'bio'          => $bio,
            'status_text'  => $status_text,
            'avatar_url'   => $avatar_url,
            'banner_url'   => $banner_url,
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Profile Settings — Eldvar</title>
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    .form-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      max-width: 700px;
    }
    .form-row { margin-bottom: 16px; display: flex; flex-direction: column; }
    label { font-weight: 600; margin-bottom: 6px; }
    input, textarea {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px;
      color: var(--text);
      font: inherit;
    }
    textarea { resize: vertical; min-height: 120px; }
    .btn-row { display:flex; gap:10px; }
    .notice { background: rgba(119,221,119,0.1); border:1px solid rgba(119,221,119,0.35); padding:10px; border-radius:8px; color:#9fe7a9; margin-bottom:12px; }
    .error { background: rgba(200,50,50,0.1); border:1px solid rgba(200,50,50,0.35); padding:10px; border-radius:8px; color:#fbb; margin-bottom:12px; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <section class="form-card">
        <h1 class="pixel-title">Profile Settings</h1>
        <p class="muted">Update how your profile appears to other players.</p>

        <?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post">
          <div class="form-row">
            <label for="display_name">Display Name</label>
            <input type="text" name="display_name" id="display_name" maxlength="64" value="<?= htmlspecialchars($profile['display_name'] ?? '') ?>" required>
          </div>

          <div class="form-row">
            <label for="status_text">Status Text <span class="muted">(optional, short tagline)</span></label>
            <input type="text" name="status_text" id="status_text" maxlength="140" value="<?= htmlspecialchars($profile['status_text'] ?? '') ?>">
          </div>

          <div class="form-row">
            <label for="bio">Bio</label>
            <textarea name="bio" id="bio"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
          </div>

          <div class="form-row">
            <label for="avatar_url">Avatar URL</label>
            <input type="url" name="avatar_url" id="avatar_url" value="<?= htmlspecialchars($profile['avatar_url'] ?? '') ?>">
          </div>

          <div class="form-row">
            <label for="banner_url">Banner URL</label>
            <input type="url" name="banner_url" id="banner_url" value="<?= htmlspecialchars($profile['banner_url'] ?? '') ?>">
          </div>

          <div class="btn-row">
            <button class="btn primary" type="submit">Save Changes</button>
            <a class="btn" href="dashboard.php">Cancel</a>
          </div>
        </form>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
