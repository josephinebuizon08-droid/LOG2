<?php

            require_once __DIR__ . '/UI_helpers.php';
            require_once __DIR__ . '/../config/ftms_db.php';

            $vehicleCount        = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles"), 0, 0);
            $activeDriverCount = pg_fetch_result(
                pg_query($conn, "SELECT COUNT(*) FROM drivers WHERE LOWER(status) = 'active'"),
                0,
                0
            );
            $scheduledMaintCount = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM maintenance WHERE status = 'Scheduled'"), 0, 0);
            $inUseCount          = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles WHERE status NOT IN ('Available')"), 0, 0);
            $utilization         = $vehicleCount > 0 ? round(($inUseCount / $vehicleCount) * 100) : 0;

                //map

            // Default "depot" pin for vehicles that have never received a GPS
            // update yet (current_lat/current_lng still NULL). Without this,
            // any Available vehicle that hasn't done a trip yet never shows
            // up on the live map at all. TODO: replace with your actual
            // garage / main office coordinates.
            define('DEFAULT_DEPOT_LAT', 14.6507);
            define('DEFAULT_DEPOT_LNG', 121.1029);

           $mapRes = pg_query($conn, "
                SELECT
                    v.vehicle_id,
                    v.plate_number,
                    v.status,
                    COALESCE(v.current_lat, " . DEFAULT_DEPOT_LAT . ") AS current_lat,
                    COALESCE(v.current_lng, " . DEFAULT_DEPOT_LNG . ") AS current_lng,
                    d.first_name, d.last_name
                FROM vehicles v
                LEFT JOIN drivers d ON d.driver_id = v.assigned_driver_id
                WHERE v.status = 'Available'
                AND v.vehicle_id NOT IN (
                    SELECT vehicle_id FROM trips WHERE status = 'In Transit' AND vehicle_id IS NOT NULL
                )
            ");

            $justStartedVehicleId = $_SESSION['just_started_vehicle_id'] ?? null;
            unset($_SESSION['just_started_vehicle_id']);

            $mapVehicles = [];
            while ($row = pg_fetch_assoc($mapRes)) {
                $mapVehicles[] = [
                    'vehicle_id' => (int) $row['vehicle_id'],
                    'plate'      => $row['plate_number'],
                    'status'     => $row['status'],
                    'lat'        => (float) $row['current_lat'],
                    'lng'        => (float) $row['current_lng'],
                    'driver'     => $row['first_name'] ? full_name($row['first_name'], $row['last_name']) : null,
                    'justStarted' => $justStartedVehicleId !== null && (int) $row['vehicle_id'] === (int) $justStartedVehicleId,
                ];
            }

            function fleet_status_bar_color(?string $status): string {
                $map = [
                    'available'      => 'bg-green-500',
                    'active'         => 'bg-green-500',
                    'in use'         => 'bg-blue-500',
                    'assigned'       => 'bg-blue-500',
                    'maintenance'    => 'bg-orange-500',
                    'out of service' => 'bg-red-500',
                    'inactive'       => 'bg-slate-400',
                ];
                $key = strtolower(trim((string) $status));
                return $map[$key] ?? 'bg-slate-400';
            }

            // Hex equivalents of fleet_status_bar_color, needed for the Chart.js donut fill colors
            function fleet_status_hex(?string $status): string {
                $map = [
                    'available'      => '#22c55e',
                    'active'         => '#22c55e',
                    'in use'         => '#3b82f6',
                    'assigned'       => '#3b82f6',
                    'maintenance'    => '#f97316',
                    'out of service' => '#ef4444',
                    'inactive'       => '#94a3b8',
                ];
                $key = strtolower(trim((string) $status));
                return $map[$key] ?? '#94a3b8';
            }

            // Returns [bar color, badge classes] for a driver performance score (0-100)
            function driver_score_colors(?float $score): array {
                if ($score === null) {
                    return ['bg-slate-300', 'bg-slate-100 text-slate-500'];
                }
                if ($score >= 90) {
                    return ['bg-emerald-500', 'bg-emerald-50 text-emerald-600'];
                }
                if ($score >= 75) {
                    return ['bg-blue-500', 'bg-blue-50 text-blue-600'];
                }
                if ($score >= 60) {
                    return ['bg-amber-500', 'bg-amber-50 text-amber-600'];
                }
                return ['bg-rose-500', 'bg-rose-50 text-rose-600'];
            }

            // Rank badge classes for the top-5 driver performance list (medal styling for top 3)
            function rank_badge_classes(int $index): string {
                $medals = [
                    0 => 'bg-amber-100 text-amber-700 ring-1 ring-amber-300',
                    1 => 'bg-slate-200 text-slate-600 ring-1 ring-slate-300',
                    2 => 'bg-orange-100 text-orange-700 ring-1 ring-orange-300',
                ];
                return $medals[$index] ?? 'bg-slate-100 text-slate-500';
            }

            function initials_from(string $first, ?string $last): string {
                $f = mb_substr($first, 0, 1);
                $l = $last ? mb_substr($last, 0, 1) : '';
                return mb_strtoupper($f . $l);
            }

            // ================= DRIVER PERFORMANCE (top 5 by avg score) =================
            $driverPerfRes = pg_query($conn, "
                SELECT
                    d.driver_id,
                    d.first_name,
                    d.last_name,
                    COUNT(dp.performance_id) AS trip_count,
                    AVG(dp.score) AS avg_score,
                    SUM(CASE WHEN dp.flags IS NOT NULL AND dp.flags <> '' THEN 1 ELSE 0 END) AS flag_count
                FROM drivers d
                LEFT JOIN driver_performance dp ON dp.driver_id = d.driver_id
                WHERE d.status = 'Active'
                GROUP BY d.driver_id, d.first_name, d.last_name
                ORDER BY avg_score DESC NULLS LAST, trip_count DESC
                LIMIT 5
            ");

            // ================= NEEDS ATTENTION (alert counts) =================
            $pendingReservations = pg_fetch_result(pg_query($conn, "
                SELECT COUNT(*) FROM reservations WHERE LOWER(status) = 'pending'
            "), 0, 0);

            $openIncidents = pg_fetch_result(pg_query($conn, "
                SELECT COUNT(*) FROM incidents WHERE status IN ('Reported', 'Investigating')
            "), 0, 0);

            $flaggedFuelLogs = pg_fetch_result(pg_query($conn, "
                SELECT COUNT(*) FROM fuel_logs WHERE validation = 'Flagged'
            "), 0, 0);

            $overdueMaintenance = pg_fetch_result(pg_query($conn, "
                SELECT COUNT(*) FROM maintenance
                WHERE status = 'Scheduled' AND maintenance_date < CURRENT_DATE
            "), 0, 0);

            // Returns Tailwind classes for an alert chip based on its count
            function alert_chip_classes(int $count): string {
                return $count > 0
                    ? 'bg-rose-50 text-rose-600 border-rose-100'
                    : 'bg-emerald-50 text-emerald-600 border-emerald-100';
            }

            function alert_icon_classes(int $count): string {
                return $count > 0
                    ? 'bg-rose-100 text-rose-600'
                    : 'bg-emerald-100 text-emerald-600';
            }

            // ================= MONTHLY COST + POTENTIAL SAVINGS =================
            $monthlyCost = pg_fetch_result(pg_query($conn, "
                SELECT COALESCE(SUM(amount), 0) FROM transport_costs
                WHERE cost_month >= date_trunc('month', CURRENT_DATE)
            "), 0, 0);

            $potentialSavings = pg_fetch_result(pg_query($conn, "
                SELECT COALESCE(SUM(potential_savings), 0) FROM optimization_recommendations
                WHERE status = 'New'
            "), 0, 0);

            // ================= RECENT INCIDENTS (5 latest) =================
            $incidentsRes = pg_query($conn, "
                SELECT
                    i.incident_id,
                    i.incident_type,
                    i.status,
                    i.reported_at,
                    d.first_name,
                    d.last_name
                FROM incidents i
                LEFT JOIN drivers d ON d.driver_id = i.driver_id
                ORDER BY i.reported_at DESC
                LIMIT 5
            ");

            // ================= FLEET STATUS (for donut chart + legend) =================
            // Same query/data as before — just also collected into an array so it can feed both
            // the legend list and the Chart.js donut, instead of only a set of progress bars.
            $fleetStatusRes = pg_query($conn, "
                SELECT status, COUNT(*) AS cnt
                FROM vehicles
                GROUP BY status
                ORDER BY cnt DESC
            ");
            $fleetStatusRows = $fleetStatusRes ? pg_fetch_all($fleetStatusRes) : [];
            $fleetStatusChartData = array_map(function ($row) {
                return [
                    'label' => $row['status'] ?? 'Unknown',
                    'count' => (int) $row['cnt'],
                    'color' => fleet_status_hex($row['status']),
                ];
            }, $fleetStatusRows ?: []);
?>

<style>
    /* Explicit, immediate sizing for the Leaflet map container so it always has
       a real height at init time, regardless of when Tailwind's utility classes
       get applied (fixes the map rendering blank/0-height on some loads). */
    #fleet-map { width: 100%; height: 280px; }
    @media (min-width: 768px) { #fleet-map { height: 320px; } }
    @media (min-width: 1024px) { #fleet-map { height: 450px; } }
</style>

<!-- DASHBOARD -->
<section id="dashboard" class="section space-y-6">

    <div class="dashboard-card rounded-2xl p-6 sm:p-10 lg:p-16 bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 text-white shadow-sm" style="--card-delay:0s">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <p class="text-sm font-semibold tracking-wide text-white/90">Fleet Overview</p>
                <p class="text-xs text-white/60 mt-0.5"><?= date('l, F j, Y') ?> &middot; live snapshot</p>
            </div>
            <div class="flex items-center gap-4 sm:gap-5 text-xs text-white/80 flex-wrap">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>Utilization: <?= (int) $utilization ?>%</span>
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-blue-300 inline-block"></span>Active Drivers: <?= (int) $activeDriverCount ?></span>
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-300 inline-block"></span>Scheduled Maint.: <?= (int) $scheduledMaintCount ?></span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all"
             onclick="goToSection('fleet')" title="View Fleet and Vehicle Management">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total Vehicles</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $vehicleCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Fleet size</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-500 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 13l1.5-4.5A2 2 0 0 1 6.4 7h11.2a2 2 0 0 1 1.9 1.5L21 13"/><path d="M3 13h18v4a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1H6v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4Z"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all"
             onclick="goToSection('monitoring')" title="View Driver & Trip Performance Monitoring">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Active Drivers</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeDriverCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">On duty</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-500 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/></svg>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all"
             onclick="goToSection('fleet')" title="View Fleet and Vehicle Management">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Scheduled Maintenance</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $scheduledMaintCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Upcoming</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-amber-500 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-2-2Z"/></svg>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all"
             onclick="goToSection('fleet')" title="View Fleet and Vehicle Management">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Fleet Utilization</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $utilization ?>%</p>
                    <p class="text-xs text-slate-400 mt-1">In use / total</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(99 102 241 / 0.1)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-indigo-500 dark:text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z"/><path d="M12 12l4-3"/><path d="M12 8v1"/></svg>
                </div>
            </div>
        </div>
    </div>

  <div class="flex flex-col md:flex-row gap-4">

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 flex-1 min-w-0 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all"
         onclick="goToSection('monitoring')" title="View Driver & Trip Performance Monitoring">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Real-Time Trip Monitoring</p>
            <button
                id="dashboard-satellite-toggle"
                type="button"
                onclick="event.stopPropagation()"
                class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors"
            >
                Satellite: Off
            </button>
        </div>
        <div id="fleet-map" class="rounded-xl overflow-hidden" onclick="event.stopPropagation()"></div>
        <script type="application/json" id="fleet-map-data"><?= json_encode($mapVehicles) ?></script>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 w-full md:w-72 lg:w-80 shrink-0">
        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4">Needs Attention</p>
        <div class="space-y-3">
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer hover:opacity-80 hover:-translate-y-0.5 transition-all <?= alert_chip_classes((int) $pendingReservations) ?>"
                 onclick="goToSection('reservation')" title="View Reservation & Dispatch System">
                <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 <?= alert_icon_classes((int) $pendingReservations) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
                </span>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-lg font-semibold"><?= (int) $pendingReservations ?></span>
                    <span class="text-xs truncate">Pending Reservations</span>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer hover:opacity-80 hover:-translate-y-0.5 transition-all <?= alert_chip_classes((int) $openIncidents) ?>"
                 onclick="goToSection('monitoring')" title="View Driver & Trip Performance Monitoring">
                <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 <?= alert_icon_classes((int) $openIncidents) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                </span>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-lg font-semibold"><?= (int) $openIncidents ?></span>
                    <span class="text-xs truncate">Open Incidents</span>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer hover:opacity-80 hover:-translate-y-0.5 transition-all <?= alert_chip_classes((int) $flaggedFuelLogs) ?>"
                 onclick="goToSection('fuel')" title="View Fuel Management">
                <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 <?= alert_icon_classes((int) $flaggedFuelLogs) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22V9l7-6 7 6v13"/><path d="M14 9h3a2 2 0 0 1 2 2v4a1.5 1.5 0 0 0 3 0v-5l-2-2"/></svg>
                </span>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-lg font-semibold"><?= (int) $flaggedFuelLogs ?></span>
                    <span class="text-xs truncate">Flagged Fuel Logs</span>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer hover:opacity-80 hover:-translate-y-0.5 transition-all <?= alert_chip_classes((int) $overdueMaintenance) ?>"
                 onclick="goToSection('fleet')" title="View Fleet and Vehicle Management">
                <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 <?= alert_icon_classes((int) $overdueMaintenance) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-lg font-semibold"><?= (int) $overdueMaintenance ?></span>
                    <span class="text-xs truncate">Overdue Maintenance</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all min-w-0"
             onclick="goToSection('reservation')" title="View Reservation & Dispatch System">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4">Recent dispatches</p>

            <style>
                .dispatch-scroll {
                    scrollbar-width: none;
                    -ms-overflow-style: none;
                }
                .dispatch-scroll::-webkit-scrollbar {
                    display: none;
                }
            </style>

            <div style="max-height:260px; overflow-y:auto; overflow-x:auto;" onclick="event.stopPropagation()" class="dispatch-scroll">
                <table style="width:100%; min-width:500px; table-layout:fixed; border-collapse:collapse;" class="text-sm">
                    <colgroup>
                        <col style="width:70px">
                        <col style="width:70px">
                        <col style="width:110px">
                        <col>
                        <col style="width:90px">
                    </colgroup>
                    <thead>
                        <tr class="text-left text-xs text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-800" style="position:sticky; top:0; background:inherit; z-index:1;">
                            <th class="pb-2 font-medium">Trip</th>
                            <th class="font-medium">Vehicle</th>
                            <th class="font-medium">Driver</th>
                            <th class="font-medium">Route</th>
                            <th class="font-medium text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-400">
                    <?php
                    $res = pg_query($conn, "
                        SELECT t.trip_id, v.plate_number, d.first_name, d.last_name, r.route_name, t.status
                        FROM trips t
                        LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                        LEFT JOIN drivers d ON d.driver_id = t.driver_id
                        LEFT JOIN routes r ON r.route_id = t.route_id
                        ORDER BY t.trip_id DESC
                        LIMIT 5
                    ");
                    if (pg_num_rows($res) === 0): ?>
                        <tr><td colspan="5" class="text-center text-slate-400 dark:text-slate-500 py-6">No dispatches yet.</td></tr>
                    <?php else: while ($row = pg_fetch_assoc($res)): ?>
                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-2.5" style="vertical-align:middle; overflow:hidden;">
                                <?= manifest_tag(code_id('TRP', $row['trip_id'])) ?>
                            </td>
                            <td class="tag text-xs text-slate-700 dark:text-slate-300" style="vertical-align:middle; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars($row['plate_number'] ?? '—') ?>
                            </td>
                            <td class="text-slate-700 dark:text-slate-300" style="vertical-align:middle; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                title="<?= htmlspecialchars($row['first_name'] ? full_name($row['first_name'], $row['last_name']) : '') ?>">
                                <?= $row['first_name'] ? htmlspecialchars(full_name($row['first_name'], $row['last_name'])) : '—' ?>
                            </td>
                            <td class="text-slate-700 dark:text-slate-300" style="vertical-align:middle; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                title="<?= htmlspecialchars($row['route_name'] ?? '') ?>">
                                <?= htmlspecialchars($row['route_name'] ?? '—') ?>
                            </td>
                            <td style="vertical-align:middle; text-align:right;">
                                <?= badge($row['status'], status_color($row['status'])) ?>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all min-w-0"
             onclick="goToSection('fleet')" title="View Fleet and Vehicle Management">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4">Fleet status</p>
            <?php if (empty($fleetStatusChartData)): ?>
                <p class="text-sm text-slate-400 dark:text-slate-500">No vehicles yet.</p>
            <?php else: ?>
                <div class="relative mx-auto" style="width:150px; height:150px; max-width:100%;">
                    <canvas id="fleetStatusChart" width="150" height="150" style="width:100%; height:100%;"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span class="text-xl font-semibold text-slate-800 dark:text-slate-100"><?= (int) $vehicleCount ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 tracking-wide">VEHICLES</span>
                    </div>
                </div>
                <div class="space-y-2 mt-4">
                <?php foreach ($fleetStatusChartData as $item): ?>
                    <div class="flex items-center justify-between text-sm flex-wrap gap-1">
                        <span class="flex items-center gap-2 text-slate-500 dark:text-slate-400 min-w-0">
                            <span class="w-2.5 h-2.5 rounded-full inline-block shrink-0" style="background-color:<?= htmlspecialchars($item['color']) ?>"></span>
                            <span class="truncate"><?= htmlspecialchars($item['label']) ?></span>
                        </span>
                        <span class="font-medium text-slate-700 dark:text-slate-200 shrink-0"><?= (int) $item['count'] ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
                <script type="application/json" id="fleet-status-data"><?= json_encode($fleetStatusChartData) ?></script>
                <script>
                (function () {
                    var el = document.getElementById('fleetStatusChart');
                    var dataEl = document.getElementById('fleet-status-data');
                    if (!el || !dataEl || typeof Chart === 'undefined') return;
                    var data = JSON.parse(dataEl.textContent);
                    var isDark = document.documentElement.classList.contains('dark');
                    if (el._chartInstance) el._chartInstance.destroy();
                    el._chartInstance = new Chart(el.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: data.map(function (d) { return d.label; }),
                            datasets: [{
                                data: data.map(function (d) { return d.count; }),
                                backgroundColor: data.map(function (d) { return d.color; }),
                                borderWidth: 2,
                                borderColor: isDark ? '#0f172a' : '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '72%',
                            plugins: { legend: { display: false }, tooltip: { enabled: true } }
                        }
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all min-w-0"
             onclick="goToSection('monitoring')" title="View Driver & Trip Performance Monitoring">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4">Driver Performance</p>
                <div class="space-y-4">
                    <?php if (!$driverPerfRes || pg_num_rows($driverPerfRes) === 0): ?>
                        <p class="text-sm text-slate-400 dark:text-slate-500">No driver performance data yet.</p>
                    <?php else: $rank = 0; while ($row = pg_fetch_assoc($driverPerfRes)):
                        $avgScore = $row['avg_score'] !== null ? (float) $row['avg_score'] : null;
                        [$barColor, $badgeClasses] = driver_score_colors($avgScore);
                        $scoreLabel = $avgScore !== null ? number_format($avgScore, 1) : '—';
                        $rankIndex = $rank++;
                    ?>
                <div class="flex items-center gap-3">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold shrink-0 <?= rank_badge_classes($rankIndex) ?>">
                        <?= $rankIndex + 1 ?>
                    </span>
                    <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-xs font-semibold flex items-center justify-center shrink-0">
                        <?= htmlspecialchars(initials_from($row['first_name'], $row['last_name'])) ?>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium truncate text-slate-800 dark:text-slate-100">
                            <?= htmlspecialchars(full_name($row['first_name'], $row['last_name'])) ?>
                        </p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                            <?= (int) $row['trip_count'] ?> trip(s)
                            <?php if ((int) $row['flag_count'] > 0): ?>
                                &middot; <span class="text-rose-500 dark:text-rose-400"><?= (int) $row['flag_count'] ?> flagged</span>
                            <?php endif; ?>
                        </p>
                        <div class="w-full h-1.5 rounded-full bg-slate-100 dark:bg-slate-700 mt-2">
                            <div class="h-1.5 rounded-full <?= $barColor ?>" style="width:<?= $avgScore !== null ? min(100, max(0, $avgScore)) : 0 ?>%"></div>
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded-full <?= $badgeClasses ?> text-xs shrink-0">
                        <?= $scoreLabel ?>
                    </span>
                </div>
            <?php endwhile; endif; ?>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all min-w-0"
             onclick="goToSection('monitoring')" title="View Driver & Trip Performance Monitoring">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4">Recent Incidents</p>
            <div class="space-y-3">
            <?php if (!$incidentsRes || pg_num_rows($incidentsRes) === 0): ?>
                <p class="text-sm text-slate-400 dark:text-slate-500">No incidents reported.</p>
            <?php else: while ($row = pg_fetch_assoc($incidentsRes)): ?>
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0 flex items-center gap-3">
                        <span class="w-8 h-8 rounded-full bg-rose-50 dark:bg-rose-500/10 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate text-slate-800 dark:text-slate-100">
                                <?= htmlspecialchars($row['incident_type'] ?? 'Incident') ?>
                            </p>
                            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                                <?= $row['first_name'] ? htmlspecialchars(full_name($row['first_name'], $row['last_name'])) : 'Unassigned' ?>
                                &middot; <?= htmlspecialchars(date('M j', strtotime($row['reported_at']))) ?>
                            </p>
                        </div>
                    </div>
                    <?= badge($row['status'], status_color($row['status'])) ?>
                </div>
            <?php endwhile; endif; ?>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all min-w-0"
             onclick="goToSection('transport')" title="View Transport Cost Analysis & Optimization">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Monthly Cost vs Potential Savings</p>
                <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Cost</span>
                    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Savings</span>
                </div>
            </div>
            <div style="height:220px;">
                <canvas id="costSavingsChart"></canvas>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Monthly Transport Cost</p>
                    <p class="text-xl font-semibold mt-1 text-slate-800 dark:text-slate-100">₱<?= number_format((float) $monthlyCost, 2) ?></p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">This month, all categories</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Potential Savings</p>
                    <p class="text-xl font-semibold mt-1 text-slate-800 dark:text-slate-100">₱<?= number_format((float) $potentialSavings, 2) ?></p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">From open optimization recommendations</p>
                </div>
            </div>
            <script type="application/json" id="cost-savings-data"><?= json_encode([
                'cost'     => (float) $monthlyCost,
                'savings'  => (float) $potentialSavings,
            ]) ?></script>
        </div>
    </div>
</div>

</section>