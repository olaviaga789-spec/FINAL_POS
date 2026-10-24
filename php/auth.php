
<?php
/*
 * Padayon Kapetid POS — Auth Guard + Nav Renderer
 */

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

function buildNav(currentPage, user) {
  const isAdmin    = user && user.role === 'admin';
  const initials   = (user?.full_name || 'U').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
  const role_label = isAdmin ? 'Administrator' : 'Cashier';

  const ni = (page, icon, label, href) => `
    <div class="nav-item ${currentPage===page?'active':''}" onclick="${href?'location.href=\''+href+'\'':''}">
      ${icon}<span>${label}</span>
    </div>`;

  const icons = {
    dashboard: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>`,
    orders:    `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>`,
    reports:   `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>`,
    menu:      `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
    inventory: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>`,
    shift:     `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
    customers: `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>`,
    settings:  `<svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93L17.66 6.34A8 8 0 116.34 17.66l-1.41 1.41"/></svg>`,
  };

  const navHTML = `
    <span class="nav-section-label">Main</span>
    ${ni('index',            icons.dashboard, 'Dashboard',           'index.html')}
    ${ni('orders',           icons.orders,    'Orders',              'orders.html')}
    ${isAdmin ? `
    ${ni('sales-report',     icons.reports,   'Sales Reports',       'sales-report.html')}
    ${ni('menu-management',  icons.menu,      'Menu Management',     'menu-management.html')}
    <span class="nav-section-label">Reports</span>
    ${ni('', icons.inventory, 'Inventory Reports',  '')}
    ${ni('', icons.shift,     'Shift &amp; Cash Log', '')}
    ${ni('', icons.customers, 'Customer Directory', '')}
    <span class="nav-section-label">System</span>
    ${ni('', icons.settings,  'Store Settings',     '')}
    ` : ''}
  `;

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = `
    <div class="sidebar-logo">
      <div class="logo-icon">☕</div>
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