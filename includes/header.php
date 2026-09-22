<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Freight Management System — Fleet & Transportation Management</title>
<link rel="icon" type="image/png" href="assets/images/prioritylogo.png">

<script>
    (function () {
        const saved = localStorage.getItem('theme');
        const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = saved ? saved === 'dark' : systemDark;
        document.documentElement.classList.toggle('dark',isDark);
    })();
</script>
<!-- Chart.js (charts) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>


<!-- Tailwind (utility classes) -->
    <link rel="stylesheet" href="src/output.css">


<!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">

<!-- Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
   
<!-- Leaflet (maps, OpenStreetMap tiles) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- App scripts: loaded with defer so the sidebar/nav still works even if a
     module below throws a PHP error partway through the page -->
    <script src="js/data.js" defer></script>
    <script src="js/render.js" defer></script>
    <script src="js/session_timeout.js" defer></script>
    <script src="js/nav.js" defer></script>
    <script src="js/subtabs.js" defer></script>
    <script src="js/dashboard_charts.js" defer></script>
    <script src="js/app.js" defer></script>
    <script src="js/map.js" defer></script>
  
<script>
    // Prevent flash of wrong section on page load
    (function() {
        const savedSection = localStorage.getItem('currentSection') || 'dashboard';
        document.addEventListener('DOMContentLoaded', function() {
            const targetSection = document.getElementById(savedSection);
            if (targetSection) {
                targetSection.classList.add('active');
            }
        });
    })();
</script>

<style>
    /* Prevent flash of wrong section on page load */
    .section {
        display: none;
    }
    .section.active {
        display: block;
    }
</style>

</head>

<body class="bg-paper dark:bg-slate-950 text-ink-900 dark:text-ink-900">