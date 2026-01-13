<?php
if (!defined('BASE_URL')) { require_once __DIR__ . '/../config/config.php'; }
?>
<aside id="sidebar" class="app-sidebar" role="complementary" aria-label="Sidebar">
  <!-- Mobile-only close button -->
  <div class="sidebar-section" style="display:flex;justify-content:flex-end;">
    <button type="button" id="sidebarClose" class="icon-btn" aria-label="Close sidebar" title="Close">
      ✕
    </button>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-title">Main</div>
    <a class="nav-link" href="<?= BASE_URL ?>/">Home</a>
    <a class="nav-link" href="<?= BASE_URL ?>/wiki/">Wiki</a>
    <a class="nav-link" href="<?= BASE_URL?>/town.php">Town</a>
    <a class="nav-link" href="#">Inventory</a>
    <a class="nav-link" href="<?= BASE_URL ?>/battle.php">Battle</a>
    <a class="nav-link" href="<?= BASE_URL ?>/locations.php">Locations</a>
    <a class="nav-link" href="#">Quests</a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-title">Character</div>
    <a class="nav-link" href="<?= BASE_URL ?>/character.php">Your Character</a>
    <a class="nav-link" href="#">Profession</a>
    <a class="nav-link" href="#">Crafting</a>
    <a class="nav-link" href="#">Tasks</a>
    <a class="nav-link" href="#">Collections</a>
    <a class="nav-link" href="#">Guilds</a>
  </div>

  <div class="sidebar-footer">
    <small>© <?= date('Y') ?> Eldvar</small>
  </div>
</aside>

<script>
(function () {
  // Ensure body has the with-sidebar marker so layout margins apply
  document.body.classList.add('with-sidebar');

  const body     = document.body;
  const sidebar  = document.getElementById('sidebar');

  // Use existing backdrop if present, otherwise create one (safe no-op if already in your pages)
  let backdrop = document.getElementById('backdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'backdrop';
    backdrop.className = 'backdrop';
    document.body.appendChild(backdrop);
  }

  // Toggle helpers
  const toggle = () => body.classList.toggle('sidebar-collapsed');
  const close  = () => body.classList.add('sidebar-collapsed');
  const open   = () => body.classList.remove('sidebar-collapsed');

  // Respect mobile default: collapsed on small screens
  const mq = window.matchMedia('(max-width: 860px)');
  const applyInitial = () => {
    if (mq.matches) body.classList.add('sidebar-collapsed');
    else body.classList.remove('sidebar-collapsed');
  };
  applyInitial();
  mq.addEventListener?.('change', applyInitial);

  // Header toggle button (if your header includes a #sidebarToggle button)
  const headerToggle = document.getElementById('sidebarToggle');
  if (headerToggle) headerToggle.addEventListener('click', toggle);

  // Mobile close button inside the sidebar
  const sidebarClose = document.getElementById('sidebarClose');
  if (sidebarClose) sidebarClose.addEventListener('click', close);

  // Click backdrop to close
  backdrop.addEventListener('click', close);

  // Optional: close with Escape, open with Ctrl/Cmd+Shift+S
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key.toLowerCase() === 's')) {
      e.preventDefault();
      toggle();
    }
  });
})();
</script>
