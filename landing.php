<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FTMS — Smart Fleet Management for Modern Logistics</title>
<link rel="icon" type="image/png" href="assets/images/prioritylogo.png">

<!-- Theme init — runs before CSS/paint so there's no flash of the wrong theme -->
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

<!-- Tailwind (compiled utility classes — shared with the rest of the app) -->
<link rel="stylesheet" href="src/output.css">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

<style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }

    /* Entrance animation for hero/section content */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(18px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-up {
        opacity: 0;
        animation: fadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .delay-1 { animation-delay: .08s; }
    .delay-2 { animation-delay: .16s; }
    .delay-3 { animation-delay: .24s; }
    .delay-4 { animation-delay: .32s; }
    .delay-5 { animation-delay: .4s; }

    /* Animated gradient text (blue -> purple shimmer) */
    @keyframes gradientShift {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    .text-gradient-animate {
        background-image: linear-gradient(90deg, #60a5fa, #a78bfa, #60a5fa);
        background-size: 200% auto;
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        animation: gradientShift 8s ease-in-out infinite;
    }

    /* Slow-pulsing status dot */
    @keyframes pulseDot {
        0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
        50% { opacity: .55; box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    }
    .pulse-dot { animation: pulseDot 2s ease-in-out infinite; }

    @keyframes pulseDotBlue {
        0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(96,165,250,0.5); }
        50% { opacity: .55; box-shadow: 0 0 0 6px rgba(96,165,250,0); }
    }
    .pulse-dot-blue { animation: pulseDotBlue 2s ease-in-out infinite; }

    /* Soft glow blobs behind hero */
    .glow-blob { filter: blur(90px); }
</style>
</head>

<body class="bg-white dark:bg-slate-950 text-slate-900 dark:text-white antialiased transition-colors">

    <?php include 'includes/landing-navbar.php'; ?>


    <section id="home" class="relative overflow-hidden">
        <!-- Background glow -->
        <div class="pointer-events-none absolute -top-24 left-1/4 w-105 h-105 bg-blue-600/10 dark:bg-blue-600/25 rounded-full glow-blob -z-10"></div>
        <div class="pointer-events-none absolute top-40 right-0 w-95 h-95 bg-purple-600/10 dark:bg-purple-600/20 rounded-full glow-blob -z-10"></div>

        <div class="max-w-7xl mx-auto px-6 lg:px-8 pt-16 pb-20 lg:pt-24 lg:pb-28">
            <div class="flex flex-col items-center text-center max-w-3xl mx-auto">

                <div>
                    <h1 class="animate-fade-up delay-1 text-5xl sm:text-5xl lg:text-[4rem] font-bold tracking-tight leading-[1.1]">
                        <span class="text-slate-900 dark:text-white">Fleet &amp; Transportation Management System</span><br>
                        <span class="text-gradient-animate">for Modern Logistics</span>
                    </h1>

                    <p class="animate-fade-up delay-2 mt-6 text-lg text-slate-600 dark:text-slate-400 leading-relaxed max-w-xl mx-auto">
                        Manage vehicles, drivers, trips, deliveries, and transportation operations through one centralized platform.
                    </p>

                    <div class="animate-fade-up delay-3 mt-9 flex flex-col sm:flex-row gap-8 justify-center">
                        <a href="auth/login.php"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-500 px-6 py-3 text-sm font-semibold text-white shadow-[0_0_30px_-8px_rgba(59,130,246,0.7)] hover:opacity-90 transition-opacity">
                            Get Started
                            <i class="ti ti-arrow-right text-base"></i>
                        </a>
                        <a href="#features"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 dark:border-white/15 bg-slate-100 dark:bg-white/5 px-6 py-3 text-sm font-semibold text-slate-900 dark:text-white hover:bg-slate-200 dark:hover:bg-white/10 transition-colors">
                            Explore Features
                        </a>
                    </div>
                </div>
            </div>

            <!-- Capability badges row -->
            <div class="animate-fade-up delay-5 mt-14 flex flex-wrap items-center justify-center gap-3">
                <span class="inline-flex items-center gap-4 rounded-full border border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-white/5 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span> Live GPS Tracking
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-white/5 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span> Automated Dispatch
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-white/5 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-purple-400"></span> Driver Scorecards
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-white/5 px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Fuel Cost Analytics
                </span>
            </div>
        </div>
    </section>

    <!-- ============================= FEATURES ============================= -->
    <section id="features" class="py-20 lg:py-28 bg-slate-50 dark:bg-slate-900/40 border-y border-slate-200 dark:border-white/5">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">Features</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Everything you need to run your fleet
                </h2>
                <p class="mt-4 text-slate-600 dark:text-slate-400">
                    Purpose-built tools for logistics teams to plan, dispatch, and monitor every vehicle and trip.
                </p>
            </div>

            <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-truck text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Fleet &amp; Vehicle Management</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Keep a complete record of every vehicle — specs, status, documents, and assignment history.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-map-pin text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">GPS Trip Monitoring</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Track vehicles in real time and follow every trip from dispatch to delivery.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-steering-wheel text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Driver Performance Monitoring</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Score driving behavior and delivery reliability to keep every trip accountable.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-route text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Route Planning &amp; Optimization</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Plan efficient routes that cut travel time, fuel use, and delivery delays.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-gas-station text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Fuel Management</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Log fuel usage and cost per vehicle to spot waste and control spending.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-6 hover:border-slate-300 dark:hover:border-white/20 transition-colors shadow-sm dark:shadow-none">
                    <div class="w-11 h-11 rounded-xl bg-blue-500/10 flex items-center justify-center">
                        <i class="ti ti-clipboard-check text-blue-500 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Vehicle Inspection &amp; Maintenance</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Schedule inspections and maintenance before small issues become downtime.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================= HOW IT WORKS ============================= -->
    <section id="how-it-works" class="py-20 lg:py-28">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">How It Works</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                    From planning to delivery
                </h2>
            </div>

            <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="relative">
                    <p class="text-4xl font-bold text-slate-200 dark:text-white/10">01</p>
                    <h3 class="mt-2 text-base font-semibold text-slate-900 dark:text-white">Plan</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Schedule vehicles, drivers, and deliveries.
                    </p>
                </div>
                <div class="relative">
                    <p class="text-4xl font-bold text-slate-200 dark:text-white/10">02</p>
                    <h3 class="mt-2 text-base font-semibold text-slate-900 dark:text-white">Dispatch</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Assign and dispatch vehicles for trips.
                    </p>
                </div>
                <div class="relative">
                    <p class="text-4xl font-bold text-slate-200 dark:text-white/10">03</p>
                    <h3 class="mt-2 text-base font-semibold text-slate-900 dark:text-white">Monitor</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Monitor trips, GPS location, and driver performance.
                    </p>
                </div>
                <div class="relative">
                    <p class="text-4xl font-bold text-slate-200 dark:text-white/10">04</p>
                    <h3 class="mt-2 text-base font-semibold text-slate-900 dark:text-white">Complete</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                        Complete deliveries and record proof of delivery.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================= DASHBOARD PREVIEW ============================= -->
    <section class="py-20 lg:py-28 bg-slate-50 dark:bg-slate-900/40 border-y border-slate-200 dark:border-white/5">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">Dashboard</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                    A clear view of your entire operation
                </h2>
                <p class="mt-4 text-slate-600 dark:text-slate-400">
                    A preview of what your team sees the moment they log in.
                </p>
            </div>

            <div class="mt-14 rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 shadow-xl shadow-slate-200/60 dark:shadow-black/30 p-5 sm:p-8">

                <div class="flex items-center justify-end mb-4">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-400 pulse-dot"></span>
                        <span class="text-xs text-green-600 dark:text-green-400 font-medium">Live Fleet Telemetry Active</span>
                    </span>
                </div>

                <!-- Stat cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-500">Total Vehicles</p>
                            <i class="ti ti-truck text-slate-500"></i>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">128</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-500">Active Trips</p>
                            <i class="ti ti-route text-slate-500"></i>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">42</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-500">Deliveries</p>
                            <i class="ti ti-package text-slate-500"></i>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">316</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-500">Driver Score</p>
                            <i class="ti ti-steering-wheel text-slate-500"></i>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">94.2</p>
                    </div>
                </div>

                <!-- Chart + Map -->
                <div class="mt-5 grid lg:grid-cols-3 gap-4">
                    <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Trips Overview</p>
                            <span class="text-xs text-slate-500">Last 7 days</span>
                        </div>
                        <div class="flex items-end gap-3 h-32">
                            <div class="flex-1 rounded-t-md bg-blue-500/20" style="height:50%"></div>
                            <div class="flex-1 rounded-t-md bg-blue-500/20" style="height:70%"></div>
                            <div class="flex-1 rounded-t-md bg-blue-500/30" style="height:45%"></div>
                            <div class="flex-1 rounded-t-md bg-blue-500/50" style="height:85%"></div>
                            <div class="flex-1 rounded-t-md bg-linear-to-t from-blue-500 to-purple-500" style="height:100%"></div>
                            <div class="flex-1 rounded-t-md bg-blue-500/20" style="height:60%"></div>
                            <div class="flex-1 rounded-t-md bg-blue-500/30" style="height:75%"></div>
                        </div>
                        <div class="flex justify-between mt-2">
                            <span class="text-[11px] text-slate-500">Mon</span>
                            <span class="text-[11px] text-slate-500">Tue</span>
                            <span class="text-[11px] text-slate-500">Wed</span>
                            <span class="text-[11px] text-slate-500">Thu</span>
                            <span class="text-[11px] text-slate-500">Fri</span>
                            <span class="text-[11px] text-slate-500">Sat</span>
                            <span class="text-[11px] text-slate-500">Sun</span>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-5 relative overflow-hidden">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">GPS Monitoring</p>
                        <svg viewBox="0 0 160 110" class="absolute inset-0 w-full h-full opacity-60">
                            <path d="M10,90 C40,30 70,95 100,40 S150,20 155,50" fill="none" stroke="#60a5fa" stroke-width="2"/>
                        </svg>
                        <span class="absolute top-14 left-16 w-2.5 h-2.5 rounded-full bg-blue-400 ring-4 ring-blue-400/20"></span>
                        <span class="absolute bottom-10 right-8 w-2.5 h-2.5 rounded-full bg-green-400 ring-4 ring-green-400/20"></span>
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="mt-4 rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-950/50 p-5">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Recent Activity</p>
                    <ul class="divide-y divide-slate-200 dark:divide-white/5">
                        <li class="flex items-center justify-between py-2.5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Trip #1042 delivered — Quezon City</span>
                            </div>
                            <span class="text-xs text-slate-500">2m ago</span>
                        </li>
                        <li class="flex items-center justify-between py-2.5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Vehicle FT-08 dispatched</span>
                            </div>
                            <span class="text-xs text-slate-500">18m ago</span>
                        </li>
                        <li class="flex items-center justify-between py-2.5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Maintenance due — FT-14</span>
                            </div>
                            <span class="text-xs text-slate-500">1h ago</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================= MOBILE APP ============================= -->
    <section class="py-20 lg:py-28">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">

                <!-- Phone mockup (kept dark — styled as a physical device, same in both themes) -->
                <div class="flex justify-center order-2 lg:order-1">
                    <div class="w-65 rounded-[2.5rem] border-8 border-slate-800 bg-slate-900 shadow-2xl shadow-slate-300/50 dark:shadow-black/40">
                        <div class="rounded-4xl overflow-hidden bg-slate-950 relative">
                            <!-- Notch -->
                            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-24 h-5 bg-slate-800 rounded-b-xl z-10"></div>

                            <div class="pt-8 pb-6 px-4 bg-blue-600">
                                <p class="text-white text-xs font-medium opacity-80">Good morning,</p>
                                <p class="text-white text-base font-semibold">Juan Dela Cruz</p>
                            </div>

                            <div class="p-4 -mt-4">
                                <div class="rounded-xl bg-slate-900 border border-white/10 shadow-sm p-4">
                                    <p class="text-[11px] text-slate-500">Current Trip</p>
                                    <p class="text-sm font-semibold text-white mt-1">Trip #1042</p>
                                    <p class="text-xs text-slate-500 mt-0.5">Quezon City &rarr; Makati</p>
                                    <button class="mt-3 w-full rounded-lg bg-blue-600 text-white text-xs font-semibold py-2">
                                        Start Trip
                                    </button>
                                </div>

                                <div class="mt-3 rounded-xl border border-white/10 bg-slate-900 p-3 h-20 relative overflow-hidden">
                                    <p class="text-[10px] text-slate-500 mb-1">GPS Location</p>
                                    <svg viewBox="0 0 120 50" class="absolute inset-0 w-full h-full opacity-60">
                                        <path d="M5,40 C25,10 45,45 65,15 S100,5 115,20" fill="none" stroke="#60a5fa" stroke-width="2"/>
                                    </svg>
                                    <span class="absolute bottom-3 right-6 w-2 h-2 rounded-full bg-blue-400 ring-4 ring-blue-400/20"></span>
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <div class="rounded-xl border border-white/10 bg-slate-900 p-3">
                                        <p class="text-[10px] text-slate-500">Trip Monitoring</p>
                                        <p class="text-sm font-semibold text-white mt-1">Live</p>
                                    </div>
                                    <div class="rounded-xl border border-white/10 bg-slate-900 p-3">
                                        <p class="text-[10px] text-slate-500">Driver Score</p>
                                        <p class="text-sm font-semibold text-white mt-1">96.5</p>
                                    </div>
                                </div>

                                <button class="mt-3 w-full rounded-lg border border-white/15 text-white text-xs font-semibold py-2.5">
                                    Submit Proof of Delivery
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Copy -->
                <div class="order-1 lg:order-2">
                    <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">Driver Mobile App</p>
                    <h2 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Built for drivers on the road
                    </h2>
                    <p class="mt-4 text-slate-600 dark:text-slate-400 leading-relaxed max-w-md">
                        Drivers get a focused companion app to run every trip from start to finish — no separate systems, no paperwork.
                    </p>

                    <ul class="mt-8 space-y-4">
                        <li class="flex items-start gap-3">
                            <span class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="ti ti-player-play text-blue-500 dark:text-blue-400 text-base"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Start Trip</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Begin a dispatched trip with a single tap.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="ti ti-map-pin text-blue-500 dark:text-blue-400 text-base"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">GPS Location</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Share live location for real-time tracking.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="ti ti-route text-blue-500 dark:text-blue-400 text-base"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Trip Monitoring</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Follow trip progress and stops in real time.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="ti ti-steering-wheel text-blue-500 dark:text-blue-400 text-base"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Driver Performance</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">See scores and feedback after every trip.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="ti ti-file-check text-blue-500 dark:text-blue-400 text-base"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Proof of Delivery</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Capture confirmation the moment a delivery is complete.</p>
                            </div>
                        </li>
                         <div class="mt-8 flex justify-center">
                            <a href="assets/downloads/app-release.apk" 
                                download
                                class="inline-flex items-center justify-center gap-3 max-w-[250px] rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 px-3 py-2 text-slate-900 dark:text-white hover:opacity-90 transition-opacity">
                                <i class="ti ti-download text-base"></i>
                                <span class="text-left leading-tight">
                                    <span class="block text-xs opacity-50">Download for</span>
                                    <span class="block text-base font-semibold">Fleet App (APK)</span>
                                </span>
                            </a>
                        </div>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================= ABOUT ============================= -->
    <section id="about" class="py-20 lg:py-28 bg-slate-50 dark:bg-slate-900/40 border-y border-slate-200 dark:border-white/5">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <p class="text-sm font-semibold text-blue-600 dark:text-blue-400">About</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                Priority Handling Logistics Inc.
            </h2>
             <p class="mt-5 text-slate-600 dark:text-slate-400 leading-relaxed text-center">
            Priority Handling Logistics Inc. was established on the 14th of February, 2005.
            As a spin-off from a similar company, Priority immediately started servicing
            different companies for their courier and distribution needs.

            To the expertise of its organization in the fields of courier and freight forwarding,
            its people are committed in providing trusted and excellent distribution services.
            Priority Handling Logistics Inc. is now expanding transport and express delivery
            services in global market including Asia, North, South and Central America, Europe,
            Middle East, Australia and the Pacific Islands and Africa, through the most reliable
            and expeditious logistics system developed by the company in partnership with the
            key leaders in the industry.

            The company earned the respect and trust of its clients, as reflected by its
            excellent performance for the past 10 years and continuously increasing presence
            with strong partnership in local and global market.
        </p>
        </div>
    </section>

    <!-- ============================= CTA ============================= -->
    <section class="py-20 lg:py-24">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="rounded-2xl bg-linear-to-r from-blue-600 to-purple-600 px-8 py-14 sm:px-16 text-center">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-white">
                    Take Control of Your Fleet Operations
                </h2>
                <p class="mt-4 text-blue-100 max-w-xl mx-auto">
                    Bring your vehicles, drivers, trips, and deliveries into one centralized platform.
                </p>
                <div class="mt-8">
                    <a href="auth/login.php"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-6 py-3 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-50 transition-colors">
                        Get Started
                        <i class="ti ti-arrow-right text-base"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/landing-footer.php'; ?>