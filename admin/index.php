<?php
declare(strict_types=1);

// /admin/index.php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/auth.php';  // brings DB + session helpers

require_acp(); // ensures only admin/governor can reach here

$role = current_user_role();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Control Panel — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .acp-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .acp-grid { display:grid; gap:16px; grid-template-columns: repeat(3, minmax(0,1fr)); }
    .acp-card .muted { display:block; margin-top:6px; }
    @media (max-width: 1100px){ .acp-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 760px){ .acp-grid{ grid-template-columns: 1fr; } }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">

      <div class="acp-header">
        <h1 class="pixel-title">Admin Control Panel</h1>
        <span class="badge"><?= htmlspecialchars(ucfirst($role)) ?></span>
      </div>

      <section class="grid acp-grid">
        <article class="card acp-card">
          <h2 class="card-title">Users</h2>
          <p class="muted">Manage players, roles & moderation actions.</p>
          <a class="btn primary" href="<?= BASE_URL ?>/admin/users.php">Open Users</a>
        </article>

        <article class="card acp-card">
          <h2 class="card-title">Wiki</h2>
          <p class="muted">Review, edit, and curate Eldvar wiki content.</p>
          <a class="btn" href="<?= BASE_URL ?>/wiki/admin/">Open Wiki Admin</a>
        </article>

        <article class="card acp-card">
          <h2 class="card-title">Chat & Reports</h2>
          <p class="muted">Moderate chat, view reports, mute/ban users. (Coming soon)</p>
          <a class="btn" href="<?= BASE_URL ?>/admin/chat_reports.php">Open Moderation</a>
        </article>

        <article class="card acp-card">
          <h2 class="card-title">Game Settings</h2>
          <p class="muted">Economy, drops, progression, world toggles. (Coming soon)</p>
          <a class="btn" href="<?= BASE_URL ?>/admin/gamesettings.php">Open Settings</a>
        </article>

        <article class="card acp-card">
          <h2 class="card-title">News & Announcements</h2>
          <p class="muted">Publish patch notes and updates. (Coming soon)</p>
          <a class="btn" href="<?= BASE_URL ?>/admin/news.php">Open News</a>
        </article>

        <article class="card acp-card">
          <h2 class="card-title">System Health</h2>
          <p class="muted">DB status, queues, error logs.</p>
          <a class="btn" href="<?= BASE_URL ?>/admin/health.php">Open Health</a>
        </article>

                <article class="card acp-card">
          <h2 class="card-title">Item Management</h2>
          <p class="muted">Create, edit, and view items.</p>
          <a class="btn" href="<?= BASE_URL ?>/admin/items.php">Open Items</a>
        </article>
      </section>

    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
