<?php
    // Session is already started in index.php before this file is included.
    $fullName    = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $fullName    = $fullName !== '' ? $fullName : ($_SESSION['username'] ?? 'User');
    $userRole    = $_SESSION['role'] ?? 'User';
    $userInitial = strtoupper(substr($fullName, 0, 1));
?>
<aside id="sidebar" class="w-65 bg-black text-slate-300 flex flex-col shrink-0 relative transition-all duration-300">

  <div class="sidebar-header px-6 py-6 border-b border-white/10 flex items-center gap-2">
      <div class="bg-white rounded-md p-2 flex items-center justify-center shrink-0 ">
         <img src="assets/images/prioritylogo_padded.png" alt="Priority logo" class="w-7 h-7 object-contain">
      </div>
       <div class="sidebar-label min-w-0">
          <h1 class="text-lg font-medium text-white mt-3 leading-tight">Fleet & Transportation</h1>
           <p class="text-xs text-white mt-0.5">Management subsystem</p>
       </div>
    </div>

   <nav class="flex-1 py-4 space-y-4 overflow-y-auto">

      <!-- ================= OVERVIEW ================= -->
      <div class="space-y-1">
        <p class="sidebar-label px-6 text-[11px] font-semibold uppercase tracking-wider text-slate-400/70 mb-1">Overview</p>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="dashboard" title="Dashboard">
          <i class="ti ti-layout-dashboard text-xl shrink-0"></i>
          <span class="nav-label">Dashboard</span>
        </button>
      </div>

      <!-- ================= OPERATIONS ================= -->
      <div class="space-y-1">
        <p class="sidebar-label px-6 text-[11px] font-semibold uppercase tracking-wider text-slate-400/70 mb-1">Operations</p>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="fleet" title="Fleet and Vehicle Management">
          <i class="ti ti-truck text-xl shrink-0"></i>
          <span class="nav-label">Fleet and Vehicle Management</span>
        </button>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="route" title="Route Planning & Optimization">
          <i class="ti ti-route text-xl shrink-0"></i>
          <span class="nav-label">Route Planning & Optimization</span>
        </button>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="reservation" title="Reservation & Dispatch">
          <i class="ti ti-calendar-event text-xl shrink-0"></i>
          <span class="nav-label">Reservation & Dispatch System</span>
        </button>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="monitoring" title="Driver & Trip Monitoring">
          <i class="ti ti-steering-wheel text-xl shrink-0"></i>
          <span class="nav-label">Driver & Trip Performance Monitoring</span>
        </button>
      </div>

      <!-- ================= REPORTS ================= -->
      <div class="space-y-1">
        <p class="sidebar-label px-6 text-[11px] font-semibold uppercase tracking-wider text-slate-400/70 mb-1">Reports</p>

         <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="fuel" title="Fuel Management">
          <i class="ti ti-gas-station text-xl shrink-0"></i>
          <span class="nav-label">Fuel Management</span>
        </button>

        <button class="nav-item w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="transport" title="Transport Cost Analysis & Optimization">
          <i class="ti ti-chart-bar text-xl shrink-0"></i>
          <span class="nav-label">Transport Cost Analysis & Optimization</span>
        </button>
      </div>

       <!-- ================= System ================= -->
      <div class="space-y-1">
        <p class="sidebar-label px-6 text-[11px] font-semibold uppercase tracking-wider text-slate-400/70 mb-1">Settings</p>

        <!-- Settings dropdown (Profile & Security nested underneath) -->
        <div id="settingsDropdownWrap">
          <button
            id="settingsToggle"
            type="button"
            onclick="toggleSettingsMenu()"
            class="w-full text-left mx-3 px-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors"
            title="Settings"
          >
            <i class="ti ti-settings text-xl shrink-0"></i>
            <span class="nav-label flex-1">Settings</span>
            <i id="settingsToggleChevron" class="ti ti-chevron-down text-base shrink-0 transition-transform duration-200 mr-2"></i>
          </button>

          <div id="settingsMenu" class="hidden pl-4 mt-1 space-y-1">
            <button class="nav-item w-full text-left mx-3 px-3 py-2 rounded-lg flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="settings" title="Profile Settings">
              <i class="ti ti-user text-lg shrink-0"></i>
              <span class="nav-label">Profile</span>
            </button>

            <button class="nav-item w-full text-left mx-3 px-3 py-2 rounded-lg flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="security" title="Security Settings">
              <i class="ti ti-lock text-lg shrink-0"></i>
              <span class="nav-label">Security</span>
            </button>
          </div>
        </div>

        <p class="sidebar-label px-6 text-[11px] font-semibold uppercase tracking-wider text-slate-400/70 mb-1">System</p>
    
        <?php if (strtolower(trim($_SESSION['role'] ?? '')) === 'administrator'):  ?>
              <button class="nav-item w-full text-left mx-3 px-3 py-3 py-2.5 rounded-xl flex items-center gap-3 text-sm hover:bg-white/5 transition-colors" data-target="users" title="User Management">
                  <i class="ti ti-users-group text-xl shrink-0"></i>
                  <span class="nav-label">User Management</span>
              </button>
        <?php endif ?>
  
      </nav>

    <div class="mt-auto border-t border-white/10 px-2 py-2 relative">
      <button
        id="sidebarProfileToggle"
        type="button"
        onclick="toggleSidebarProfileMenu()"
        title="<?= htmlspecialchars($fullName) ?>"
        class="w-full flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-white/5 transition-colors"
      >
        <div class="w-9 h-9 rounded-full bg-linear-to-br from-sidebar-blue-900 via-sidebar-blue-300 to-sidebar-blue-600 text-white text-xs flex items-center justify-center font-medium shrink-0">
          <?= htmlspecialchars($userInitial) ?>
        </div>
        <div class="sidebar-label min-w-0 text-left">
          <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($fullName) ?></p>
          <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($userRole) ?></p>
        </div>
        <i class="ti ti-chevron-down text-lg text-slate-400 ml-auto shrink-0"></i>
      </button>

      <div
        id="sidebarProfileMenu"
        class="hidden absolute mb-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg py-1 z-50"
        style="bottom: 100%; left: 0.5rem; right: 0.5rem;"
      >
        <a href="#settings" onclick="goToSection('settings'); closeAllMenus();" class="block px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700">
          <i class="ti ti-user mr-2"></i> Profile 
        </a>
        <a href="#security" onclick="goToSection('security'); closeAllMenus();" class="block px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700">
          <i class="ti ti-lock mr-2"></i> Security 
        </a>
        <hr class="my-1 border-slate-100 dark:border-slate-600">
        <a href="auth/logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-slate-50 dark:hover:bg-slate-700">
          <i class="ti ti-logout mr-2"></i> Logout
        </a>
      </div>
    </div>

</aside>

<script>
// Keep the chevron in sync whenever #settingsMenu is shown/hidden
// (toggleSettingsMenu() itself lives in nav.js and just toggles the
// 'hidden' class on #settingsMenu — this only handles the chevron icon).
(function () {
  const menu = document.getElementById('settingsMenu');
  const chevron = document.getElementById('settingsToggleChevron');
  if (!menu || !chevron) return;

  const syncChevron = () => {
    chevron.classList.toggle('rotate-180', !menu.classList.contains('hidden'));
  };

  // Watch for the hidden class being toggled by toggleSettingsMenu()
  const observer = new MutationObserver(syncChevron);
  observer.observe(menu, { attributes: true, attributeFilter: ['class'] });

  // Auto-open the dropdown if the user is already on Profile or Security
  document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash.replace('#', '');
    const savedSection = localStorage.getItem('currentSection');
    if (hash === 'settings' || hash === 'security' || savedSection === 'settings' || savedSection === 'security') {
      menu.classList.remove('hidden');
      syncChevron();
    }
  });

  // Close the dropdown when clicking outside it
  document.addEventListener('click', function (event) {
    const toggleBtn = document.getElementById('settingsToggle');
    if (!toggleBtn) return;
    if (!menu.contains(event.target) && !toggleBtn.contains(event.target)) {
      menu.classList.add('hidden');
      syncChevron();
    }
  });
})();
</script>