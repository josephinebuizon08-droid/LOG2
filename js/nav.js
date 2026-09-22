function goToSection(target) {
  const navItems = document.querySelectorAll('.nav-item');
  const sections = document.querySelectorAll('.section');
  const pageTitle = document.getElementById('pageTitle');
  const breadcrumbGroup = document.getElementById('breadcrumbGroup');

  navItems.forEach(i =>
    i.classList.toggle(
      'active',
      i.getAttribute('data-target') === target
    )
  );

  sections.forEach(s => {
    // Handle both class-based and style-based display
    s.classList.toggle('active', s.id === target);
    s.style.display = s.id === target ? 'block' : 'none';
  });

  if (pageTitle) {
    pageTitle.textContent = PAGE_TITLES[target] || '';
  }

  if (breadcrumbGroup) {
    breadcrumbGroup.textContent = SECTION_GROUPS[target] || 'App';
  }

  // Remember the current section
  localStorage.setItem('currentSection', target);
}


function initNav() {
  const navItems = document.querySelectorAll('.nav-item');

  // Restore the last section after page reload
  const savedSection = localStorage.getItem('currentSection');

  if (savedSection && document.getElementById(savedSection)) {
    goToSection(savedSection);
  } else {
    // Default to Dashboard on first visit only if no section is already active
    const hasActiveSection = document.querySelector('.section.active');
    if (!hasActiveSection) {
      goToSection('dashboard');
    }
  }

  navItems.forEach(item => {
    item.addEventListener('click', () => {
      goToSection(item.getAttribute('data-target'));
    });
  });
}

/* Sidebar collapse/expand toggle — click the chevron button in the
   sidebar header to shrink it to an icon-only rail, click again to
   restore it. Purely visual/UI state, nothing else depends on it. */
function initSidebarToggle() {
  const sidebar = document.getElementById('sidebar');
  const toggleBtn = document.getElementById('sidebarToggle');
  const toggleIcon = document.getElementById('sidebarToggleIcon');

  if (!sidebar || !toggleBtn) return;

  toggleBtn.addEventListener('click', () => {
    const collapsed = sidebar.classList.toggle('collapsed');

    toggleBtn.setAttribute('aria-expanded', String(!collapsed));
    toggleBtn.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');

    if (toggleIcon) {
      toggleIcon.classList.toggle('ti-layout-sidebar-left-collapse', !collapsed);
      toggleIcon.classList.toggle('ti-layout-sidebar-left-expand', collapsed);
    }
  });
}

/* Light/dark mode toggle — click the sun icon in the topbar to switch
   themes. Icon swaps sun/moon and the choice persists via localStorage. */
function initThemeToggle() {
  const toggleBtn = document.getElementById('themeToggle');
  const toggleIcon = document.getElementById('themeToggleIcon');

  if (!toggleBtn || !toggleIcon) return;

  const applyTheme = (isDark) => {
    document.documentElement.classList.toggle('dark', isDark);
    toggleIcon.classList.toggle('ti-sun', !isDark);
    toggleIcon.classList.toggle('ti-moon', isDark);
  };

  const saved = localStorage.getItem('theme');
  applyTheme(saved === 'dark');

  toggleBtn.addEventListener('click', () => {
    const isDark = !document.documentElement.classList.contains('dark');
    applyTheme(isDark);
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
  });
}

/* Profile menu — click the FM avatar in the topbar to reveal
   Profile / Settings / Logout. Click anywhere outside to close it. */
function toggleProfileMenu() {
  const menu = document.getElementById('profileMenu');
  if (!menu) return;
  menu.classList.toggle('hidden');
  
  // Close settings menu when profile menu is toggled
  const settingsMenu = document.getElementById('settingsMenu');
  if (settingsMenu) {
    settingsMenu.classList.add('hidden');
  }
}

/* Settings dropdown submenu — click Settings to show Profile/Security options */
function toggleSettingsMenu() {
  const settingsMenu = document.getElementById('settingsMenu');
  if (!settingsMenu) return;
  settingsMenu.classList.toggle('hidden');
}

document.addEventListener('click', (event) => {
  const menu = document.getElementById('profileMenu');
  const button = document.getElementById('fmToggle');
  if (!menu || !button) return;

  if (!menu.contains(event.target) && !button.contains(event.target)) {
    menu.classList.add('hidden');
  }
});

document.addEventListener('click', (event) => {
  const settingsMenu = document.getElementById('settingsMenu');
  const settingsButton = document.getElementById('settingsToggle');
  if (!settingsMenu || !settingsButton) return;

  if (!settingsMenu.contains(event.target) && !settingsButton.contains(event.target)) {
    settingsMenu.classList.add('hidden');
  }
});

function toggleSidebarProfileMenu() {
  const menu = document.getElementById('sidebarProfileMenu');
  if (!menu) return;
  menu.classList.toggle('hidden');
}

document.addEventListener('click', (event) => {
  const menu = document.getElementById('sidebarProfileMenu');
  const button = document.getElementById('sidebarProfileToggle');
  if (!menu || !button) return;

  if (!menu.contains(event.target) && !button.contains(event.target)) {
    menu.classList.add('hidden');
  }
});

/* Close all dropdown menus */
function closeAllMenus() {
  const profileMenu = document.getElementById('profileMenu');
  const settingsMenu = document.getElementById('settingsMenu');
  const sidebarProfileMenu = document.getElementById('sidebarProfileMenu');
  
  if (profileMenu) profileMenu.classList.add('hidden');
  if (settingsMenu) settingsMenu.classList.add('hidden');
  if (sidebarProfileMenu) sidebarProfileMenu.classList.add('hidden');
}