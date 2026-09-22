<?php
session_start();

  header("Cache-Control: no-cache, no-store, must-revalidate");
  header("Pragma: no-cache");
  header("Expires: 0");

  $error = $_SESSION['login_error'] ?? '';
  unset($_SESSION['login_error']); 
?>

  <!DOCTYPE html>
  <html lang="en">
  <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Sign In — Fleet and Transport Management System</title>
  <link rel="icon" type="image/png" href="../assets/images/prioritylogo.png">


<script>
(function () {
    try {
        const stored = localStorage.getItem('ftms-theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = stored ? stored === 'dark' : prefersDark;
        if (isDark) document.documentElement.classList.add('dark');
    } catch (e) {}
})();
</script>

<link rel="stylesheet" href="../src/output.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

<style>
      body {
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
      }

      @keyframes pulseDot {
        0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
        50% { opacity: .55; box-shadow: 0 0 0 6px rgba(34,197,94,0); }
      }
      .pulse-dot { animation: pulseDot 2s ease-in-out infinite; }

      .glow-blob { filter: blur(90px); }

      /* Fade-up entrance transition for when this page loads (e.g. from the landing page) */
      @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(24px); }
        to   { opacity: 1; transform: translateY(0); }
      }
      .page-fade-up {
        animation: fadeInUp 0.6s ease-out both;
      }
      @media (prefers-reduced-motion: reduce) {
        .page-fade-up { animation: none; }
      }
</style>
</head>

<body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-white antialiased min-h-screen transition-colors">

  <!-- Theme toggle -->
  <button id="themeToggle" type="button" aria-label="Toggle dark mode"
    class="fixed top-4 right-4 sm:top-6 sm:right-6 z-50 inline-flex items-center justify-center w-10 h-10 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 shadow-sm transition-colors">
    <i id="themeToggleIcon" class="ti ti-moon text-base"></i>
  </button>

  <div class="min-h-screen">
    <div class="w-full min-h-screen grid lg:grid-cols-2 bg-white dark:bg-slate-950">

      <!-- LEFT — brand / feature panel (kept dark — works as a showcase panel in both themes) -->
      <div class="relative hidden lg:flex flex-col justify-between p-10 xl:p-12 bg-linear-to-br from-sidebar-blue-900 via-slate-950 to-slate-950 overflow-hidden">

        <!-- Background glow -->
        <div class="pointer-events-none absolute -top-16 -left-10 w-72 h-72 bg-sidebar-blue-400/20 rounded-full glow-blob"></div>
        <div class="pointer-events-none absolute bottom-0 right-0 w-72 h-72 bg-purple-600/20 rounded-full glow-blob"></div>

        <!-- Top row -->
        <div class="relative flex items-center justify-between">
            <span class="leading-tight">
              <a href="../landing.php" class="flex items-center gap-2 shrink-0">
                <img src="../assets/images/prioritylogo_padded.png" alt="Priority Handling & Logistics Corp. Logo" class="w-8 h-8 object-contain bg-white rounded-lg">
              <span class="block text-[12px] font-semibold text-white tracking-tight">Priority Handling Logistics Corp.</span>
            </span>
          </a>
          <a href="../landing.php" class="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-white transition-colors">
            <i class="ti ti-arrow-left text-sm"></i>
            Return to Landing
          </a>
        </div>

        <!-- Middle content -->
        <div class="page-fade-up relative mt-10">
          <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 backdrop-blur px-3 py-1 mb-6">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
            <span class="text-xs font-medium text-slate-300">Unified Fleet Command Console</span>
          </span>

          <h1 class="text-3xl xl:text-[2.15rem] font-bold tracking-tight leading-[1.15] text-white">
            Real-Time Fleet &amp; Transportation
            <br>
            <span class="bg-linear-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent">Logistics Command Center</span>
          </h1>

          <p class="mt-4 text-sm text-slate-400 leading-relaxed max-w-sm">
            Sign in to manage vehicles, drivers, trips, and deliveries with live GPS tracking, automated dispatch, and driver performance analytics.
          </p>

          <div class="mt-8 grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-white/10 bg-white/5 p-3.5">
              <div class="flex items-center gap-2">
                <i class="ti ti-map-pin text-blue-400 text-base"></i>
                <p class="text-xs font-semibold text-white">Live GPS Tracking</p>
              </div>
              <p class="mt-1 text-[11px] text-slate-500">Real-time vehicle location</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3.5">
              <div class="flex items-center gap-2">
                <i class="ti ti-route text-green-400 text-base"></i>
                <p class="text-xs font-semibold text-white">Automated Dispatch</p>
              </div>
              <p class="mt-1 text-[11px] text-slate-500">Trip assignment &amp; routing</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3.5">
              <div class="flex items-center gap-2">
                <i class="ti ti-steering-wheel text-purple-400 text-base"></i>
                <p class="text-xs font-semibold text-white">Driver Scorecards</p>
              </div>
              <p class="mt-1 text-[11px] text-slate-500">Behavior &amp; reliability</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3.5">
              <div class="flex items-center gap-2">
                <i class="ti ti-gas-station text-amber-400 text-base"></i>
                <p class="text-xs font-semibold text-white">Fuel Cost Analytics</p>
              </div>
              <p class="mt-1 text-[11px] text-slate-500">Usage &amp; spend tracking</p>
            </div>
          </div>
        </div>

        <!-- Bottom status bar -->
        <div class="relative mt-10 flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-4 py-3">
          <span class="text-xs text-slate-300">Centralized Fleet Database Active</span>
          <span class="inline-flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full bg-green-400 pulse-dot"></span>
            <span class="text-[11px] text-green-400 font-medium">Online</span>
          </span>
        </div>
      </div>

      <!-- RIGHT — sign in form -->
      <div class="flex items-center justify-center p-8 sm:p-12 xl:p-16 bg-white dark:bg-slate-950">
        <div class="page-fade-up w-full max-w-sm">

          <!-- Mobile-only brand row -->
          <a href="../landing.php" class="lg:hidden flex items-center gap-2 mb-8">
            <img src="../assets/images/prioritylogo_padded.png" alt="Priority Handling & Logistics Corp. Logo" class="w-9 h-9 object-contain bg-white rounded-lg">
            <span class="text-[13px] font-semibold text-slate-900 dark:text-white tracking-tight">Priority Handling Logistics Corp.</span>
          </a>

          <div class="flex items-center justify-between">
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
              System Sign In
            </h2>
            <span class="w-9 h-9 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
              <i class="ti ti-lock text-blue-500 dark:text-blue-400 text-base"></i>
            </span>
          </div>
          <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
            Enter your credentials to access your assigned dashboard.
          </p>

          <form action="./authentication.php" method="POST" class="mt-8">

            <div class="mb-5">
              <label class="text-xs font-medium text-slate-600 dark:text-slate-400">
                Username
              </label>
              <div class="relative mt-2">
                <i class="ti ti-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                <input type="text" name="username" required autofocus
                  class="w-full rounded-xl border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
              </div>
            </div>

            <div class="mb-5">
              <div class="flex items-center justify-between">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-400">
                  Password
                </label>
                <a href="#" class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300 transition-colors">
                  Forgot password?
                </a>
              </div>
              <div class="relative mt-2">
                <i class="ti ti-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
                <input id="loginPassword" type="password" name="password" required
                  class="w-full rounded-xl border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-11 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                <button type="button" id="togglePassword"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                  <i id="togglePasswordIcon" class="ti ti-eye text-base"></i>
                </button>
              </div>
            </div>

            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400 mb-6">
              <input type="checkbox" class="accent-blue-500 rounded">
              Remember me on this device
            </label>

            <?php if (!empty($error)): ?>
              <div class="mb-5 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3">
                <p class="text-red-600 dark:text-red-400 text-sm"><?= htmlspecialchars($error) ?></p>
              </div>
            <?php endif; ?>

            <button type="submit"
              class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-500 text-white py-3 text-sm font-semibold shadow-[0_0_30px_-8px_rgba(59,130,246,0.7)] hover:opacity-90 transition-opacity">
              <i class="ti ti-login text-base"></i>
              Sign In
            </button>

          </form>

          <p class="mt-8 text-center text-[11px] text-slate-500">
            Protected by role-based access control &amp; encrypted session security.
          </p>
        </div>
      </div>

    </div>
  </div>

  <script>
    (function () {
      const toggleBtn = document.getElementById('togglePassword');
      const input = document.getElementById('loginPassword');
      const icon = document.getElementById('togglePasswordIcon');
      if (!toggleBtn || !input || !icon) return;

      toggleBtn.addEventListener('click', function () {
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        icon.classList.toggle('ti-eye', !isPassword);
        icon.classList.toggle('ti-eye-off', isPassword);
      });
    })();

    // Theme (light/dark) toggle — works with the inline no-flash script in <head>
    (function () {
      const btn = document.getElementById('themeToggle');
      const icon = document.getElementById('themeToggleIcon');
      if (!btn) return;

      function updateIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        if (icon) {
          icon.classList.toggle('ti-moon', !isDark);
          icon.classList.toggle('ti-sun', isDark);
        }
      }
      updateIcon();

      btn.addEventListener('click', function () {
        document.documentElement.classList.toggle('dark');
        localStorage.setItem('ftms-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        updateIcon();
      });
    })();
  </script>

</body>
</html>