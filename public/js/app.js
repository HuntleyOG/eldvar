// Sidebar toggle with persistent state + backdrop close
(function () {
  const btn = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');
  const KEY = 'eldvar.sidebar.collapsed';

  if (!btn || !sidebar) return;

  // Read saved preference (default collapsed on <=860px if none saved)
  const saved = localStorage.getItem(KEY);
  const defaultCollapsed = saved === null ? (window.innerWidth <= 860) : (saved === 'true');

  function apply(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    btn.setAttribute('aria-expanded', String(!collapsed));
    localStorage.setItem(KEY, String(collapsed));
  }

  // Initialize
  apply(defaultCollapsed);

  // Toggle via button
  btn.addEventListener('click', () => {
    apply(!document.body.classList.contains('sidebar-collapsed'));
  });

  // Click backdrop to close (mobile)
  if (backdrop) {
    backdrop.addEventListener('click', () => apply(true));
  }

  // If no saved pref yet, adapt to breakpoint on first few resizes
  window.addEventListener('resize', () => {
    if (localStorage.getItem(KEY) === null) {
      apply(window.innerWidth <= 860);
    }
  });
})();
