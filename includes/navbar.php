<?php
    // Session is already started in index.php before this file is included.
    $fullName   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $fullName   = $fullName !== '' ? $fullName : ($_SESSION['username'] ?? 'User');
    $userRole   = $_SESSION['role'] ?? 'User';
    $userEmail  = $_SESSION['email'] ?? '';
    $userInitial = strtoupper(substr($fullName, 0, 1));
?>
<header class="h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 shrink-0">

    <div class="flex items-center gap-2">
    <button
        id="sidebarToggle"
        type="button"
        aria-expanded="true"
        title="Collapse sidebar"
        class="w-5 h-8 flex items-center justify-center rounded-md text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
    >
        <i id="sidebarToggleIcon" class="ti ti-layout-sidebar text-xl"></i>
    </button>

    <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm">
        <a id = "breadcrumbGroup" href="index.php" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors">App</a>
        <i class="ti ti-chevron-right text-slate-400 dark:text-slate-500 text-sm"></i>
        <span id="pageTitle" class="font-medium text-ink-900 dark:text-white">Dashboard</span>
    </nav>
</div>

    <div class="flex items-center gap-4">

        <div class="bg-slate-100 dark:bg-slate-800 rounded-full p-1">
            <button id="themeToggle" type="button" title="Toggle theme"
                class="w-7 h-7 flex items-center justify-center rounded-full bg-white dark:bg-slate-700 shadow-sm text-slate-800 dark:text-slate-100 transition-colors">
                <i id="themeToggleIcon" class="ti ti-sun text-base"></i>
            </button>
        </div>

        <i class="ti ti-bell text-slate-800 dark:text-slate-200 text-lg"></i>

        <div class="relative">
            <div class="relative">
                <button
                    id="fmToggle"
                    type="button"
                    onclick="toggleProfileMenu()"
                    title="<?= htmlspecialchars($fullName) ?>"
                    class="w-8 h-8 rounded-full bg-linear-to-br from-sidebar-blue-900 via-sidebar-blue-300 to-sidebar-blue-600 text-white text-xs flex items-center justify-center font-medium tag"
                >
                    <?= htmlspecialchars($userInitial) ?>
                </button>

                <div
                    id="profileMenu"
                    class="hidden absolute right-0 mt-2 w-64 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg py-1 z-50"
                >
                    <div class="px-4 py-2.5 border-b border-slate-100 dark:border-slate-600">
                        <p class="text-sm font-medium text-slate-800 dark:text-white truncate"><?= htmlspecialchars($fullName) ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate"><?= htmlspecialchars($userRole) ?> <br> <?= $userEmail ? ' ' . htmlspecialchars($userEmail) : '' ?></p>
                    </div>
                    
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
        </div>
    </div>
</header>