/**
 * Padayon Kapetid POS — Auth Guard + Nav Renderer
 * Updated: Dashboard = analytics page, Order System = separate nav item
 */

/* ── THEME INIT — runs immediately on every page to prevent flash ── */
(function () {
  const t = localStorage.getItem('pk_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();

function getUser() {
  try { return JSON.parse(sessionStorage.getItem('pos_user')); }
  catch(e) { return null; }
}

function requireLogin(allowedRoles) {
  const user = getUser();
  if (!user) { location.href = 'login.html'; return null; }
  if (allowedRoles && !allowedRoles.includes(user.role)) {
    location.href = 'index.html';
    return null;
  }
  return user;
}

async function logout() {
  try { await fetch('./php/auth.php?action=logout'); } catch(e) {}
  sessionStorage.removeItem('pos_user');
  location.href = 'login.html';
}

function navTo(url) {
  const sidebar = document.getElementById('sidebar');
  if (sidebar && sidebar.classList.contains('open')) closeSidebarNav();
  location.href = url;
}

function buildNav(currentPage, user) {
  const isAdmin    = user && user.role === 'admin';
  const initials   = (user?.full_name || 'U').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
  const role_label = isAdmin ? 'Administrator' : 'Cashier';

  const ni = (page, icon, label, href) => href
    ? `<a class="nav-item ${currentPage===page?'active':''}" href="${href}">${icon}<span>${label}</span></a>`
    : `<div class="nav-item" style="opacity:0.4;cursor:not-allowed">${icon}<span>${label}</span></div>`;

  const icons = {
    dashboard: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>`,
    pos:       `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 8h.01M11 8h2M15 8h.01M7 12h10"/></svg>`,
    orders:    `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>`,
    reports:   `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>`,
    menu:      `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
    inventory: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>`,
    shift:     `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
    customers: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>`,
    settings:  `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93L17.66 6.34A8 8 0 116.34 17.66l-1.41 1.41"/></svg>`,
  };

  // ── NAV STRUCTURE ─────────────────────────────────────────────
  // Dashboard  → analytics overview (dashboard.html)   — admin only
  // Order System → POS / menu ordering (index.html)    — all roles
  // Orders     → order list / kitchen view (orders.html)
  const navHTML = `
    <span class="nav-section-label">Main</span>
    ${isAdmin ? ni('dashboard', icons.dashboard, 'Dashboard',      'dashboard.html') : ''}
    ${ni('index',   icons.pos,     'Order System',  'index.html')}
    ${ni('orders',  icons.orders,  'Orders',        'orders.html')}
    ${isAdmin ? `
    <span class="nav-section-label">Management</span>
    ${ni('sales-report',    icons.reports,  'Sales Reports',     'sales-report.html')}
    ${ni('menu-management', icons.menu,     'Menu Management',   'menu-management.html')}
    <span class="nav-section-label">Reports</span>
    ${ni('inventory-report', icons.inventory, 'Inventory Reports', 'inventory-reports.html')}
    ${ni('shift',     icons.shift,     'Shift &amp; Cash Log', 'shift-cash-log.html')}
    ${ni('customers', icons.customers, 'Customer Directory',   'customer-directory.html')}
    <span class="nav-section-label">System</span>
    ${ni('store-settings', icons.settings,  'Store Settings',     'store-settings.html')}
    ` : ''}
  `;

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = `
    <div class="sidebar-logo">
      <div class="logo-icon" style="width:42px;height:32px;background:transparent;padding:0;overflow:hidden;border-radius:8px;flex-shrink:0;"><img src="data:image/png;base64,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" style="width:42px;height:32px;object-fit:fill;" alt="PK"></div>
      <span class="logo-text">Padayon<br>Kapetid</span>
    </div>
    <nav class="sidebar-nav">${navHTML}</nav>
    <div class="sidebar-footer">
      <div class="sidebar-user" onclick="showLogoutMenu()" id="sidebarUserBtn" title="Click to log out">
        <div class="user-avatar">${initials}</div>
        <div class="user-info">
          <div class="user-name">${user?.full_name || 'User'}</div>
          <div class="user-role">${role_label}</div>
        </div>
        <span style="color:rgba(255,255,255,.3);font-size:11px;margin-left:auto">▲</span>
      </div>
      <div id="logoutMenu" style="display:none;margin-top:6px;">
        <button onclick="logout()" style="width:100%;padding:9px 12px;border-radius:8px;background:rgba(220,38,38,.15);color:#FCA5A5;font-size:13px;font-weight:600;cursor:pointer;border:1px solid rgba(220,38,38,.25);text-align:left;font-family:inherit;">
          🚪 Log Out
        </button>
      </div>
    </div>
  `;

  // ── WIRE UP HAMBURGER (already in HTML on every page) ──────────────
  const toggleBtn = document.getElementById('sidebarToggle');
  const backdrop  = document.getElementById('sidebarBackdrop');
  if (toggleBtn) toggleBtn.onclick = toggleSidebarNav;
  if (backdrop)  backdrop.onclick  = closeSidebarNav;

  // NOTE: sidebar close on mobile is handled inside navTo() — do NOT add a
  // separate document click listener here or it will race and cancel navigation.
}

function showLogoutMenu() {
  const menu = document.getElementById('logoutMenu');
  if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
  const btn  = document.getElementById('sidebarUserBtn');
  const menu = document.getElementById('logoutMenu');
  if (menu && btn && !btn.contains(e.target) && !menu.contains(e.target)) {
    menu.style.display = 'none';
  }
});

// ── MOBILE SIDEBAR TOGGLE ─────────────────────────────────────────
function toggleSidebarNav() {
  const sidebar  = document.getElementById('sidebar');
  const toggle   = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  const isOpen = sidebar.classList.contains('open');
  if (isOpen) { closeSidebarNav(); } else {
    sidebar.classList.add('open');
    if (toggle)   toggle.classList.add('open');
    if (backdrop) backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
}
function closeSidebarNav() {
  const sidebar  = document.getElementById('sidebar');
  const toggle   = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  sidebar.classList.remove('open');
  if (toggle)   toggle.classList.remove('open');
  if (backdrop) backdrop.classList.remove('show');
  document.body.style.overflow = '';
}
