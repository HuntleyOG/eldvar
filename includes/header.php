<?php
// /includes/header.php
if (!defined('BASE_URL')) require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$role    = current_user_role();
$isStaff = is_staff_role($role);
$PAGE_TITLE = $PAGE_TITLE ?? 'Eldvar';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($PAGE_TITLE) ?></title>

  <!-- GLOBAL CSS/JS — use absolute URLs so they work under /admin/* -->
  <link rel="stylesheet" href="<?= asset_url('public/css/style.css') ?>?v=1.0.0">
  <link rel="icon" href="<?= asset_url('public/img/favicon.png') ?>">
  <script defer src="<?= asset_url('public/js/app.js') ?>"></script>
</head>
<body>

<header class="app-header" role="banner">
  <div class="header-left">
    <button
      id="sidebarToggle"
      class="icon-btn"
      type="button"
      aria-label="Toggle sidebar"
      aria-controls="sidebar"
      aria-expanded="false"
      title="Toggle sidebar"
      style="margin-right:8px"
    >☰</button>

    <a class="brand" href="<?= BASE_URL ?>/">
      <span class="brand-glyph">✦</span> Eldvar
    </a>
  </div>

  <nav class="header-nav" role="navigation" aria-label="Primary">
    <a href="<?= BASE_URL ?>/">Home</a>
    <a href="<?= BASE_URL ?>/wiki/">Wiki</a>
    <a href="<?= BASE_URL ?>/news.php">News</a>
    <a href="<?= BASE_URL ?>/support.php">Support</a>

    <?php if ($isStaff): ?>
      <a href="<?= BASE_URL ?>/admin/">Admin</a>
    <?php endif; ?>

    <?php if (empty($_SESSION['user_id'])): ?>
      <a href="<?= BASE_URL ?>/login.php">Login</a>
      <a href="<?= BASE_URL ?>/register.php">Register</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
      <a href="<?= BASE_URL ?>/logout.php">Logout</a>
    <?php endif; ?>
  </nav>
</header>

<script>
  (function () {
    const btn = document.getElementById('sidebarToggle');
    if (!btn) return;
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) { btn.style.display = 'none'; return; }
    const sync = () => {
      const expanded = !document.body.classList.contains('sidebar-collapsed');
      btn.setAttribute('aria-expanded', String(expanded));
    };
    document.addEventListener('keydown', sync);
    document.addEventListener('click', sync);
    window.addEventListener('resize', sync);
    sync();
  })();
</script>
