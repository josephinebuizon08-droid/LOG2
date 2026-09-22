<?php

    session_start();

        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");

        if (empty($_SESSION['pending_pre_auth_token'])) {
            header("Location: login.php");
            exit();
        }

        $error = $_SESSION['otp_error'] ?? '';
        unset($_SESSION['otp_error']);

        const OTP_RESEND_COOLDOWN_SECONDS = 180;
        $sentAt = $_SESSION['otp_sent_at'] ?? 0;
        $secondsRemaining = max(0, OTP_RESEND_COOLDOWN_SECONDS - (time() - $sentAt));
?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code — Fleet and Transport Management System</title>
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
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        </style>
        </head>

    <body class="bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-white antialiased min-h-screen transition-colors">

    <div class="min-h-screen flex items-center justify-center p-8">
        <div class="w-full max-w-sm">

        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
            Verify Code
            </h2>
            <span class="w-9 h-9 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
            <i class="ti ti-shield-lock text-blue-500 dark:text-blue-400 text-base"></i>
            </span>
        </div>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
            We sent a 6-digit code to your email. Enter it below to continue.
        </p>

        <?php if (!empty($error)): ?>
            <div class="mt-5 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3">
            <p class="text-red-600 dark:text-red-400 text-sm"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <form action="./verify_otp_web2.php" method="POST" class="mt-8">
            <label class="text-xs font-medium text-slate-600 dark:text-slate-400">
            Verification Code
            </label>
            <div class="relative mt-2">
            <i class="ti ti-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-base"></i>
            <input type="text" name="code" maxlength="6" required autofocus
                class="w-full rounded-xl border border-slate-300 dark:border-white/10 bg-slate-50 dark:bg-white/5 pl-10 pr-4 py-3 text-sm text-slate-900 dark:text-white tracking-[0.3em] text-center focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <button type="submit"
            class="mt-6 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-500 text-white py-3 text-sm font-semibold shadow-[0_0_30px_-8px_rgba(59,130,246,0.7)] hover:opacity-90 transition-opacity">
            Verify
            </button>
        </form>

        <form action="./resend_otp_web.php" method="POST" class="mt-4">
            <button id="resend-btn" type="submit"
            class="w-full text-center text-xs text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300 transition-colors disabled:text-slate-400 dark:disabled:text-slate-600 disabled:cursor-not-allowed disabled:hover:text-slate-400">
            <span id="resend-label">Resend code</span>
            </button>
        </form>

        </div>
    </div>

    <script>
    (function () {
        let secondsRemaining = <?= (int) $secondsRemaining ?>;
        const btn = document.getElementById('resend-btn');
        const label = document.getElementById('resend-label');

        function render() {
            if (secondsRemaining > 0) {
                btn.disabled = true;
                label.textContent = 'Resend code in ' + secondsRemaining + 's';
            } else {
                btn.disabled = false;
                label.textContent = 'Resend code';
            }
        }

        render();

        const timer = setInterval(function () {
            secondsRemaining -= 1;
            if (secondsRemaining <= 0) {
                secondsRemaining = 0;
                clearInterval(timer);
            }
            render();
        }, 1000);
    })();
    </script>

    </body>
</html>