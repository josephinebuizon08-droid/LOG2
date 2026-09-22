<header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-950/80 backdrop-blur-md border-b border-slate-200 dark:border-white/10">
    <div class="max-w-7xl mx-auto px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            <!-- Logo -->
          <a href="#" class="flex items-center gap-2 shrink-0">
                <img src="assets/images/prioritylogo_padded.png" alt="Priority Handling & Logistics Corp. Logo" class="w-8 h-8 object-contain bg-white rounded-lg">

                <span class="text-[12px] font-semibold text-slate-900 dark:text-white tracking-tight">
                    Priority Handling <br>
                    Logistics Corp.
                </span>
         </a>

            <!-- Desktop nav links -->
            <nav class="hidden md:flex items-center gap-8">
                <a href="#home" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Home</a>
                <a href="#features" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Features</a>
                <a href="#how-it-works" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">How It Works</a>
                <a href="#about" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">About</a>
                <a href="#contact" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Contact</a>
            </nav>

            <!-- Right side -->
            <div class="hidden md:flex items-center gap-3">
                <button id="themeToggle" type="button" aria-label="Toggle dark mode"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">
                    <i id="themeToggleIcon" class="ti ti-moon text-base"></i>
                </button>
                <a href="auth/login.php"
                    class="inline-flex items-center justify-center rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white hover:opacity-90 transition-opacity shadow-[0_0_20px_-6px_rgba(59,130,246,0.6)]">
                    Sign in
                </a>
            </div>

            <!-- Mobile menu button -->
            <div class="md:hidden flex items-center gap-2">
                <button id="themeToggleMobile" type="button" aria-label="Toggle dark mode"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">
                    <i id="themeToggleMobileIcon" class="ti ti-moon text-base"></i>
                </button>
                <button id="landingMobileMenuBtn" type="button"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors"
                    aria-expanded="false" aria-label="Toggle navigation menu">
                    <i id="landingMobileMenuIcon" class="ti ti-menu-2 text-xl"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile nav panel -->
    <div id="landingMobileMenu" class="hidden md:hidden border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-950">
        <nav class="flex flex-col px-6 py-4 gap-1">
            <a href="#home" class="px-2 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 rounded-lg">Home</a>
            <a href="#features" class="px-2 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 rounded-lg">Features</a>
            <a href="#how-it-works" class="px-2 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 rounded-lg">How It Works</a>
            <a href="#about" class="px-2 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 rounded-lg">About</a>
            <a href="#contact" class="px-2 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 rounded-lg">Contact</a>
            <a href="auth/login.php"
                class="mt-2 inline-flex items-center justify-center rounded-lg bg-linear-to-r from-blue-500 to-purple-500 px-4 py-2.5 text-sm font-medium text-white">
                Login
            </a>
        </nav>
    </div>
</header>

<script>
    (function () {
        const btn = document.getElementById('landingMobileMenuBtn');
        const menu = document.getElementById('landingMobileMenu');
        const icon = document.getElementById('landingMobileMenuIcon');
        if (!btn || !menu) return;

        btn.addEventListener('click', function () {
            const isOpen = !menu.classList.contains('hidden');
            menu.classList.toggle('hidden');
            btn.setAttribute('aria-expanded', String(!isOpen));
            icon.classList.toggle('ti-menu-2', isOpen);
            icon.classList.toggle('ti-x', !isOpen);
        });

        menu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
                icon.classList.add('ti-menu-2');
                icon.classList.remove('ti-x');
            });
        });
    })();

    // Theme (light/dark) toggle — works with the inline no-flash script in <head>
    (function () {
        function isDark() {
            return document.documentElement.classList.contains('dark');
        }
        function updateIcons() {
            const dark = isDark();
            [document.getElementById('themeToggleIcon'), document.getElementById('themeToggleMobileIcon')].forEach(function (icon) {
                if (!icon) return;
                icon.classList.toggle('ti-moon', !dark);
                icon.classList.toggle('ti-sun', dark);
            });
        }
        function toggleTheme() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('ftms-theme', isDark() ? 'dark' : 'light');
            updateIcons();
        }
        updateIcons();
        const desktopBtn = document.getElementById('themeToggle');
        const mobileBtn = document.getElementById('themeToggleMobile');
        if (desktopBtn) desktopBtn.addEventListener('click', toggleTheme);
        if (mobileBtn) mobileBtn.addEventListener('click', toggleTheme);
    })();
</script>