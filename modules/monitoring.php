<?php

require_once __DIR__ . '/UI_helpers.php';
require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/../auth/rbac.php';

$activeDrivers = pg_fetch_result( pg_query($conn, "SELECT COUNT(*) FROM drivers WHERE LOWER(status) = 'active'"), 0,0);
$recordedTrips   = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM trips"), 0, 0);
$completedEvals  = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM evaluations"), 0, 0);
$reportedIncidents = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM incidents WHERE status = 'Reported'"), 0, 0);

// Evaluation areas, weights and levels live in one shared file (also used by the save endpoint).
require_once __DIR__ . '/evaluation_scheme.php';

// trip_id => driver name, so the Add Evaluation form can show the driver of the chosen trip.
$tripDriverMap = [];
$tdRes = pg_query($conn, "SELECT t.trip_id, d.first_name, d.last_name FROM trips t LEFT JOIN drivers d ON d.driver_id = t.driver_id");
while ($tdRow = pg_fetch_assoc($tdRes)) {
    $tripDriverMap[(int) $tdRow['trip_id']] = $tdRow['first_name'] ? full_name($tdRow['first_name'], $tdRow['last_name']) : '—';
}
?>

<!-- DTPM -->
<section id="monitoring" class="section space-y-6 dark:text-slate-400">

    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900 dark:text-white">Driver & Trip Performance</h1>
                <span class="text-xs font-medium px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 border border-blue-500/20">
                    DTPM Module
                </span>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Trips, logs, incidents, proofs, evaluations, drivers, vehicles, supervisors, archive
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Drivers</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeDrivers ?></p>
                    <p class="text-xs text-slate-400 mt-1">Active drivers</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <i class="ti ti-users text-emerald-500 dark:text-emerald-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Trips</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $recordedTrips ?></p>
                    <p class="text-xs text-slate-400 mt-1">Recorded trips</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <i class="ti ti-route text-blue-500 dark:text-blue-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Evaluations</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $completedEvals ?></p>
                    <p class="text-xs text-slate-400 mt-1">Completed</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                    <i class="ti ti-star text-amber-500 dark:text-amber-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Incidents</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $reportedIncidents ?></p>
                    <p class="text-xs text-slate-400 mt-1">Reported</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(239 68 68 / 0.1)">
                    <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 text-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
        <div class="flex justify-center mb-4">
            <?php render_subtabs('dtpm', [
                'trips' => 'Trips', 'logs' => 'Logs',
                'incidents' => 'Incidents', 'proofs' => 'Proofs',
                'evaluations' => 'Evaluations', 'performance' => 'Performance', 'archive' => 'Archive',
            ], 'evaluations'); ?>
        </div>

        <!-- ============== TRIPS PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="trips">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $recordedTrips ?> trip(s) recorded</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                            <th class="py-3 px-4 font-normal">Trip</th>
                            <th class="font-normal">Driver</th>
                            <th class="font-normal">Vehicle</th>
                            <th class="font-normal">Departure</th>
                            <th class="font-normal">Status</th>
                        </tr>
               </thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $res = pg_query($conn, "
                    SELECT t.trip_id, t.departure_time, t.status, v.plate_number, d.first_name, d.last_name
                    FROM trips t
                    LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                    LEFT JOIN drivers d ON d.driver_id = t.driver_id
                    WHERE t.status <> 'Archived'
                    ORDER BY t.trip_id DESC
                ");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="5" class="text-center text-slate-400 py-6">No trips recorded yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr>
                        <td class="py-3 px-4"><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                        <td><?= $row['first_name'] ? htmlspecialchars(full_name($row['first_name'], $row['last_name'])) : '—' ?></td>
                        <td class="tag text-xs"><?= htmlspecialchars($row['plate_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['departure_time'] ?? '—') ?></td>
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== LOGS PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="logs">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Trip logs</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                            <th class="py-3 px-4 font-normal">Trip</th>
                            <th class="font-normal">Time</th>
                            <th class="font-normal">Event</th>
                            <th class="font-normal">Notes</th>
                        </tr>
                    </thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $res = pg_query($conn, "SELECT log_id, trip_id, log_time, event, notes FROM trip_logs ORDER BY log_time DESC LIMIT 100");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="4" class="text-center text-slate-400 py-6">No trip logs yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr>
                        <td class="py-3 px-4"><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                        <td><?= htmlspecialchars($row['log_time']) ?></td>
                        <td><?= htmlspecialchars($row['event'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== INCIDENTS PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="incidents">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $reportedIncidents ?> incident(s) reported</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">ID</th><th class="font-normal">Trip</th>
                    <th class="font-normal">Driver</th><th class="font-normal">Type</th>
                    <th class="font-normal">Status</th><th class="font-normal">Reported</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $res = pg_query($conn, "
                    SELECT i.incident_id, i.incident_type, i.status, i.reported_at, i.trip_id, d.first_name, d.last_name
                    FROM incidents i
                    LEFT JOIN drivers d ON d.driver_id = i.driver_id
                    ORDER BY i.incident_id DESC
                ");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="6" class="text-center text-slate-400 py-6">No incidents reported.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr>
                        <td class="py-3 px-4"><?= manifest_tag(code_id('INC', $row['incident_id'])) ?></td>
                        <td><?= $row['trip_id'] ? manifest_tag(code_id('TRP', $row['trip_id'])) : '—' ?></td>
                        <td><?= $row['first_name'] ? htmlspecialchars(full_name($row['first_name'], $row['last_name'])) : '—' ?></td>
                        <td><?= htmlspecialchars($row['incident_type'] ?? '—') ?></td>
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
                        <td><?= htmlspecialchars($row['reported_at']) ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== PROOFS PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="proofs">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Trip proofs</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                        <th class="py-3 px-4 font-normal">Trip</th>
                        <th class="font-normal">Type</th>
                        <th class="font-normal">File</th>
                        <th class="font-normal">Uploaded</th>
                        <th class="font-normal">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $res = pg_query($conn, "SELECT proof_id, trip_id, proof_type, file_url, uploaded_at, notes FROM trip_proofs ORDER BY uploaded_at DESC");
               if (pg_num_rows($res) === 0): ?>
                <tr><td colspan="5" class="text-center text-slate-400 py-6">No proofs uploaded yet.</td></tr>
            <?php else: while ($row = pg_fetch_assoc($res)): ?>
                <tr>
                    <td class="py-3 px-4"><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                    <td><?= htmlspecialchars($row['proof_type'] ?? '—') ?></td>
                    <td><?= $row['file_url'] ? '<button type="button" class="text-blue-600 underline" onclick="openProofModal(\'' . htmlspecialchars($row['file_url'], ENT_QUOTES) . '\')">View</button>' : '—' ?></td>
                    <td><?= htmlspecialchars($row['uploaded_at']) ?></td>
                    <td><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
                </tr>
            <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== EVALUATIONS PANEL (default) ============== -->
        <div class="subtab-panel" data-module="dtpm" data-subtab="evaluations">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $completedEvals ?> evaluation(s) completed</p>
                <button type="button" onclick="openEvalModal()" class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors">
                    <i class="ti ti-plus"></i> Add evaluation
                </button>
            </div>

            <form method="get" id="evalFilterForm" class="flex flex-wrap items-center gap-3 mb-4">
                <input type="hidden" name="section" value="monitoring">
                <select name="eval_trip" onchange="document.getElementById('evalFilterForm').submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">All Trips</option>
                    <?php
                    $tres = pg_query($conn, "SELECT trip_id FROM trips ORDER BY trip_id");
                    while ($t = pg_fetch_assoc($tres)):
                        $sel = (($_GET['eval_trip'] ?? '') == $t['trip_id']) ? 'selected' : '';
                    ?>
                        <option value="<?= $t['trip_id'] ?>" <?= $sel ?>><?= code_id('TRP', $t['trip_id']) ?></option>
                    <?php endwhile; ?>
                </select>
                <input type="date" name="eval_from" value="<?= htmlspecialchars($_GET['eval_from'] ?? '') ?>" onchange="document.getElementById('evalFilterForm').submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
                <input type="date" name="eval_to" value="<?= htmlspecialchars($_GET['eval_to'] ?? '') ?>" onchange="document.getElementById('evalFilterForm').submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
                <?php if (!empty($_GET['eval_trip']) || !empty($_GET['eval_from']) || !empty($_GET['eval_to'])): ?>
                    <a href="?section=monitoring" class="text-sm text-slate-500 underline">Clear filters</a>
                <?php endif; ?>
            </form>

            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">ID</th><th class="font-normal">Trip</th>
                    <th class="font-normal">Driver</th>
                    <th class="font-normal">Overall Score</th>
                    <th class="font-normal">Performance Level</th><th class="font-normal">Date</th>
                    <th class="font-normal text-right pr-4">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $where = [];
                $params = [];
                if (!empty($_GET['eval_trip'])) { $params[] = $_GET['eval_trip']; $where[] = "e.trip_id = $" . count($params); }
                if (!empty($_GET['eval_from'])) { $params[] = $_GET['eval_from']; $where[] = "e.eval_date >= $" . count($params); }
                if (!empty($_GET['eval_to'])) { $params[] = $_GET['eval_to']; $where[] = "e.eval_date <= $" . count($params); }
                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

                $sql = "
                    SELECT e.*, d.driver_id, d.first_name, d.last_name
                    FROM evaluations e
                    LEFT JOIN trips t ON t.trip_id = e.trip_id
                    LEFT JOIN drivers d ON d.driver_id = t.driver_id
                    $whereSql
                    ORDER BY e.eval_date DESC
                ";
                $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);

                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="7" class="text-center text-slate-400 py-6">No evaluations found.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)):
                    $rowLevel = eval_level($row['rating'], $evalLevels);
                    $editData = [
                        'evaluation_id' => $row['evaluation_id'],
                        'trip_id'       => $row['trip_id'],
                        'eval_date'     => $row['eval_date'],
                        'comments'      => $row['comments'] ?? '',
                    ];
                    foreach ($evalAreas as $area) {
                        $editData[$area['key']] = $row[$area['key']] ?? null;
                    }
                ?>
                    <tr>
                        <td class="py-3 px-4"><?= manifest_tag(code_id('EVL', $row['evaluation_id'])) ?></td>
                        <td><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                        <td>
                            <?php if ($row['driver_id']): ?>
                                <button type="button"
                                    class="text-blue-600 hover:underline text-left"
                                    onclick="openDriverModal(<?= (int) $row['driver_id'] ?>, '<?= htmlspecialchars(addslashes(full_name($row['first_name'], $row['last_name']))) ?>')">
                                    <?= htmlspecialchars(full_name($row['first_name'], $row['last_name'])) ?>
                                </button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="font-medium"><?= $row['rating'] !== null ? number_format((float) $row['rating'], 1) . ' / 5' : '—' ?></td>
                        <td>
                            <?php if ($rowLevel): ?>
                                <span class="inline-block text-xs font-medium px-2.5 py-1 rounded-full"
                                      style="background:<?= $rowLevel['bg'] ?>;color:<?= $rowLevel['fg'] ?>;"><?= htmlspecialchars($rowLevel['label']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= $row['eval_date'] ? htmlspecialchars(date('M j, Y', strtotime($row['eval_date']))) : '—' ?></td>
                        <td class="text-right pr-4">
                            <button type="button"
                                class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors"
                                onclick='openEvalModal(<?= json_encode($editData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="ti ti-edit text-base"></i>
                            </button>
                            <?php if (is_admin()): ?>
                            <button type="button"
                                class="inline-flex items-center justify-center w-8 h-8 text-red-600 border border-red-200 rounded-md hover:bg-red-50 hover:border-red-300 transition-colors"
                                onclick="deleteEvaluation(<?= (int) $row['evaluation_id'] ?>)">
                                <i class="ti ti-trash text-base"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== PERFORMANCE PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="performance">
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">Driver</th>
                    <th class="font-normal">Avg. Score</th>
                    <th class="font-normal">Avg. Rating</th>
                    <th class="font-normal">Completed Trips</th>
                    <th class="font-normal">Incidents</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                // One row per driver: avg driver_performance.score (raw
                // telematics/scoring, if that table is populated),
                // avg evaluations.rating (supervisor-given, 0-5), and
                // trip/incident counts -- clicking a name reuses the same
                // openDriverModal() already wired up in the Evaluations
                // panel above, so there's one performance drill-down for
                // a driver, not two competing ones.
                $perfRes = pg_query($conn, "
                    SELECT
                        d.driver_id, d.first_name, d.last_name,
                        (SELECT AVG(dp.score) FROM driver_performance dp WHERE dp.driver_id = d.driver_id) AS avg_score,
                        (SELECT AVG(e.rating) FROM evaluations e JOIN trips t ON t.trip_id = e.trip_id WHERE t.driver_id = d.driver_id) AS avg_rating,
                        (SELECT COUNT(*) FROM trips t2 WHERE t2.driver_id = d.driver_id AND t2.status IN ('Completed','Archived')) AS completed_trips,
                        (SELECT COUNT(*) FROM incidents i WHERE i.driver_id = d.driver_id) AS incident_count
                    FROM drivers d
                    ORDER BY avg_score DESC NULLS LAST, avg_rating DESC NULLS LAST
                ");
                if (pg_num_rows($perfRes) === 0): ?>
                    <tr><td colspan="5" class="text-center text-slate-400 py-6">No drivers found.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($perfRes)): ?>
                    <tr>
                        <td class="py-3 px-4">
                            <button type="button"
                                class="text-blue-600 hover:underline text-left"
                                onclick="openDriverModal(<?= (int) $row['driver_id'] ?>, '<?= htmlspecialchars(addslashes(full_name($row['first_name'], $row['last_name']))) ?>')">
                                <?= htmlspecialchars(full_name($row['first_name'], $row['last_name'])) ?>
                            </button>
                        </td>
                        <td><?= $row['avg_score'] !== null ? number_format((float) $row['avg_score'], 1) : '—' ?></td>
                        <td><?= $row['avg_rating'] !== null ? number_format((float) $row['avg_rating'], 2) : '—' ?></td>
                        <td><?= (int) $row['completed_trips'] ?></td>
                        <td><?= (int) $row['incident_count'] > 0
                                ? '<span class="text-red-600 font-medium">' . (int) $row['incident_count'] . '</span>'
                                : (int) $row['incident_count'] ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ============== ARCHIVE PANEL ============== -->
        <div class="subtab-panel hidden" data-module="dtpm" data-subtab="archive">
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-700">
                    <th class="py-3 px-4 font-normal">Trip</th><th class="font-normal">Driver</th>
                    <th class="font-normal">Vehicle</th><th class="font-normal">Completed</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                $res = pg_query($conn, "
                    SELECT t.trip_id, t.arrival_time, v.plate_number, d.first_name, d.last_name
                    FROM trips t
                    LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                    LEFT JOIN drivers d ON d.driver_id = t.driver_id
                    WHERE t.status IN ('Completed','Archived')
                    ORDER BY t.arrival_time DESC
                ");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="4" class="text-center text-slate-400 py-6">No archived trips yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr>
                        <td class="py-3 px-4"><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                        <td><?= $row['first_name'] ? htmlspecialchars(full_name($row['first_name'], $row['last_name'])) : '—' ?></td>
                        <td class="tag text-xs"><?= htmlspecialchars($row['plate_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['arrival_time'] ?? '—') ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</section>

<!-- ============== EVALUATION MODAL ============== -->
<style>
    .eval-scale { display: flex; gap: .375rem; flex-shrink: 0; }
    .eval-scale-opt { position: relative; }
    .eval-scale-opt input { position: absolute; inset: 0; opacity: 0; cursor: pointer; margin: 0; }
    .eval-scale-opt span {
        display: flex; align-items: center; justify-content: center;
        width: 2.25rem; height: 2.25rem; border-radius: .5rem;
        border: 1px solid #e2e8f0; background: #fff; color: #475569;
        font-size: .875rem; pointer-events: none; transition: background .15s, color .15s, border-color .15s;
    }
    .eval-scale-opt:hover span { border-color: #93c5fd; }
    .eval-scale-opt input:checked + span { background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 600; }
    .eval-scale-opt input:focus-visible + span { outline: 2px solid #93c5fd; outline-offset: 1px; }
</style>
<div id="evalModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(15,23,42,.4);">
    <!-- Card = header (fixed) + scrolling form body + footer (fixed), so Save/Cancel are always visible. -->
    <div class="bg-white rounded-xl shadow-lg w-full max-w-lg" style="display:flex;flex-direction:column;max-height:calc(100vh - 2rem);">
        <div class="flex items-center justify-between" style="padding:1.5rem 1.5rem 1rem;">
            <p id="evalModalTitle" class="text-lg font-semibold">Add Driver Evaluation</p>
            <button type="button" onclick="closeEvalModal()" class="text-slate-400 hover:text-slate-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>

        <form id="evalForm" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
          <div class="space-y-4" style="overflow-y:auto;flex:1 1 auto;padding:0 1.5rem 1rem;">
            <input type="hidden" name="evaluation_id" id="eval_id">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Trip</label>
                    <select name="trip_id" id="eval_form_trip" required onchange="updateEvalDriver()" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">Select trip</option>
                        <?php
                        $tres2 = pg_query($conn, "SELECT trip_id FROM trips ORDER BY trip_id DESC");
                        while ($t = pg_fetch_assoc($tres2)): ?>
                            <option value="<?= $t['trip_id'] ?>"><?= code_id('TRP', $t['trip_id']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Driver</label>
                    <input type="text" id="eval_form_driver" readonly tabindex="-1" placeholder="Shown after selecting a trip"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-600">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Date</label>
                <input type="date" name="eval_date" id="eval_form_date" required class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </div>

            <div>
                <p class="text-sm font-semibold mb-1">Driver Performance</p>
                <p class="text-xs text-slate-500 mb-2">Score each area from 1 (poor) to 5 (excellent).</p>
                <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
                    <?php foreach ($evalAreas as $area): ?>
                        <div class="flex items-center justify-between gap-3 px-3 py-2.5">
                            <div class="min-w-0">
                                <p class="text-sm font-medium flex items-center gap-2">
                                    <i class="ti <?= $area['icon'] ?> text-blue-600"></i><?= htmlspecialchars($area['label']) ?>
                                </p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($area['hint']) ?></p>
                            </div>
                            <div class="eval-scale">
                                <?php for ($n = 1; $n <= 5; $n++): ?>
                                    <label class="eval-scale-opt">
                                        <input type="radio" name="<?= $area['key'] ?>" value="<?= $n ?>" required>
                                        <span><?= $n ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs text-slate-500">Overall Score</p>
                    <p class="text-lg font-semibold"><span id="evalOverall">—</span> <span class="text-sm font-normal text-slate-500">/ 5</span></p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-500 mb-1">Performance Level</p>
                    <span id="evalLevel" class="inline-block text-xs font-medium px-2.5 py-1 rounded-full bg-slate-200 text-slate-600">—</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Comments</label>
                <textarea name="comments" id="eval_form_comments" rows="3" maxlength="500" placeholder="Optional remarks about the driver's performance on this trip"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>

            <p id="evalFormError" class="hidden text-sm text-red-600"></p>
          </div>

            <div class="flex justify-end gap-3" style="padding:.875rem 1.5rem;border-top:1px solid #e2e8f0;flex-shrink:0;">
                <button type="button" onclick="closeEvalModal()" class="px-4 py-2 text-sm rounded-lg border border-slate-200 hover:bg-slate-50">Cancel</button>
                <button type="submit" id="evalSaveBtn" class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700">Save Evaluation</button>
            </div>
        </form>
    </div>
</div>

<!-- ============== DRIVER PERFORMANCE MODAL ============== -->
<div id="driverModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-2xl p-6" style="max-height:calc(100vh - 2rem);overflow-y:auto;">
        <div class="flex items-center justify-between mb-4">
            <p id="driverModalTitle" class="text-lg font-semibold">Driver Performance</p>
            <button type="button" onclick="closeDriverModal()" class="text-slate-400 hover:text-slate-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>

        <div id="driverModalBody" class="space-y-6">
            <p class="text-sm text-slate-400 text-center py-6">Loading…</p>
        </div>
    </div>
</div>

<!-- ============== PROOF PREVIEW MODAL ============== -->
<div id="proofModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
     onclick="if(event.target===this) closeProofModal()">
    <div class="relative w-full h-full max-w-5xl" style="max-height:calc(100vh - 2rem);">
        <button type="button" onclick="closeProofModal()"
            class="absolute -top-3 -right-3 z-10 w-9 h-9 rounded-full bg-white text-slate-600 border border-slate-200 shadow flex items-center justify-center hover:bg-slate-50">
            <i class="ti ti-x text-lg"></i>
        </button>

        <div id="proofModalImgWrap" class="hidden w-full h-full rounded-lg bg-white overflow-hidden relative" style="height:calc(100vh - 2rem);">
            <img id="proofModalImg" src="" alt="Proof" draggable="false"
                class="absolute top-1/2 left-1/2 max-w-none select-none"
                style="transform: translate(-50%, -50%) scale(1); cursor: zoom-in; transition: transform .08s linear;">

            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1 bg-slate-900/70 rounded-full px-2 py-1.5">
                <button type="button" onclick="proofZoomBy(-0.5)" class="w-8 h-8 rounded-full text-white flex items-center justify-center hover:bg-white/10">
                    <i class="ti ti-minus text-base"></i>
                </button>
                <span id="proofZoomLabel" class="text-white text-xs w-11 text-center select-none">100%</span>
                <button type="button" onclick="proofZoomBy(0.5)" class="w-8 h-8 rounded-full text-white flex items-center justify-center hover:bg-white/10">
                    <i class="ti ti-plus text-base"></i>
                </button>
                <span class="w-px h-4 bg-white/20 mx-1"></span>
                <button type="button" onclick="proofZoomReset()" class="w-8 h-8 rounded-full text-white flex items-center justify-center hover:bg-white/10">
                    <i class="ti ti-focus-2 text-base"></i>
                </button>
            </div>
        </div>

        <div id="proofModalFallback" class="hidden bg-white rounded-lg p-6 text-center">
            <p class="text-sm text-slate-500 mb-3">Hindi mapreview ang file na ito dito.</p>
            <a id="proofModalLink" href="#" target="_blank" rel="noopener" class="text-blue-600 underline text-sm">Buksan sa bagong tab</a>
        </div>
    </div>
</div>

<script>
// Evaluation scheme comes from the PHP config at the top of this file.
const EVAL_AREAS   = <?= json_encode(array_map(function ($a) { return ['key' => $a['key'], 'weight' => $a['weight']]; }, $evalAreas)) ?>;
const EVAL_LEVELS  = <?= json_encode($evalLevels) ?>;
const TRIP_DRIVERS = <?= json_encode($tripDriverMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function openDriverModal(driverId, driverName) {
    document.getElementById('driverModalTitle').textContent = driverName + ' — Performance';
    const body = document.getElementById('driverModalBody');
    body.innerHTML = '<p class="text-sm text-slate-400 text-center py-6">Loading…</p>';
    document.getElementById('driverModal').classList.remove('hidden');

    fetch('driver_performance.php?driver_id=' + encodeURIComponent(driverId))
        .then(res => res.json())
        .then(result => {
            if (!result.success) {
                body.innerHTML = '<p class="text-sm text-red-600 text-center py-6">' + (result.error || 'Could not load driver performance.') + '</p>';
                return;
            }
            renderDriverPerformance(result);
        })
        .catch(() => {
            body.innerHTML = '<p class="text-sm text-red-600 text-center py-6">Could not load driver performance.</p>';
        });
}

function closeDriverModal() {
    document.getElementById('driverModal').classList.add('hidden');
}

function renderDriverPerformance(data) {
    const s = data.summary;
    const evalRows = data.evaluations.map(e => `
        <tr>
            <td class="py-2 px-3">${e.eval_date ?? '—'}</td>
            <td class="py-2 px-3">${e.trip_code ?? '—'}</td>
            <td class="py-2 px-3">${e.rating !== null ? Number(e.rating).toFixed(2) : '—'}</td>
            <td class="py-2 px-3">${e.timeliness ?? '—'}</td>
            <td class="py-2 px-3">${e.completion ?? '—'}</td>
        </tr>
    `).join('') || '<tr><td colspan="5" class="text-center text-slate-400 py-4">No evaluations yet.</td></tr>';

    document.getElementById('driverModalBody').innerHTML = `
        <div class="grid grid-cols-4 gap-3">
            <div class="card p-4">
                <p class="text-xs text-slate-500">Avg. Rating</p>
                <p class="text-xl font-semibold mt-1">${s.avg_rating !== null ? Number(s.avg_rating).toFixed(2) : '—'}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">Evaluations</p>
                <p class="text-xl font-semibold mt-1">${s.total_evaluations}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">Trips Completed</p>
                <p class="text-xl font-semibold mt-1">${s.completed_trips}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">Incidents</p>
                <p class="text-xl font-semibold mt-1">${s.total_incidents}</p>
            </div>
        </div>

        <div>
            <p class="text-sm font-medium mb-2">Evaluation History</p>
            <div class="overflow-hidden rounded-lg border border-slate-100">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200">
                        <th class="py-2 px-3 font-normal">Date</th><th class="font-normal">Trip</th>
                        <th class="font-normal">Rating</th>
                        <th class="font-normal">Timeliness</th><th class="font-normal">Completion</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">${evalRows}</tbody>
                </table>
            </div>
        </div>
    `;
}

function evalLevelFor(score) {
    return EVAL_LEVELS.find(l => score >= l.min) || EVAL_LEVELS[EVAL_LEVELS.length - 1];
}

// Weighted average of the five scores, or null until all five are chosen.
function computeEvalOverall() {
    let total = 0;
    for (const area of EVAL_AREAS) {
        const chosen = document.querySelector(`#evalForm input[name="${area.key}"]:checked`);
        if (!chosen) return null;
        total += Number(chosen.value) * area.weight;
    }
    return Math.round(total * 100) / 100; // 2 decimals, same as the server
}

// POST to the evaluations endpoint and always give back a readable error --
// if the server sends back an HTML page instead of JSON (wrong path, PHP error,
// login redirect) we say so instead of showing a raw JSON parse error.
async function postEvaluation(payload) {
    const res = await fetch('modules/evaluations_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const raw = await res.text();

    let result;
    try {
        result = JSON.parse(raw);
    } catch (_) {
        console.error('evaluations_actions.php returned non-JSON (HTTP ' + res.status + '):', raw.slice(0, 500));
        throw new Error('The server did not return JSON (HTTP ' + res.status + '). Check that modules/evaluations_actions.php exists and has no PHP errors.');
    }
    if (!res.ok || !result.success) {
        throw new Error(result.error || 'Request failed (HTTP ' + res.status + ').');
    }
    return result;
}

function updateEvalOverall() {
    const overall = computeEvalOverall();
    const overallEl = document.getElementById('evalOverall');
    const levelEl = document.getElementById('evalLevel');

    if (overall === null) {
        overallEl.textContent = '—';
        levelEl.textContent = '—';
        levelEl.style.background = '#e2e8f0';
        levelEl.style.color = '#475569';
        return;
    }
    const level = evalLevelFor(overall);
    overallEl.textContent = overall.toFixed(1);
    levelEl.textContent = level.label;
    levelEl.style.background = level.bg;
    levelEl.style.color = level.fg;
}

function updateEvalDriver() {
    const tripId = document.getElementById('eval_form_trip').value;
    document.getElementById('eval_form_driver').value = tripId ? (TRIP_DRIVERS[tripId] || '—') : '';
}

function openEvalModal(data) {
    const form = document.getElementById('evalForm');
    form.reset();
    document.getElementById('evalFormError').classList.add('hidden');

    const saveBtn = document.getElementById('evalSaveBtn');
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save Evaluation';

    if (data) {
        document.getElementById('evalModalTitle').textContent = 'Edit Driver Evaluation';
        document.getElementById('eval_id').value = data.evaluation_id;
        document.getElementById('eval_form_trip').value = data.trip_id;
        document.getElementById('eval_form_date').value = data.eval_date || '';
        document.getElementById('eval_form_comments').value = data.comments || '';
        for (const area of EVAL_AREAS) {
            const value = data[area.key];
            if (value) {
                const radio = form.querySelector(`input[name="${area.key}"][value="${value}"]`);
                if (radio) radio.checked = true;
            }
        }
    } else {
        document.getElementById('evalModalTitle').textContent = 'Add Driver Evaluation';
        document.getElementById('eval_id').value = '';
        document.getElementById('eval_form_date').value = new Date().toLocaleDateString('en-CA'); // today, YYYY-MM-DD
    }

    updateEvalDriver();
    updateEvalOverall();
    document.getElementById('evalModal').classList.remove('hidden');
}

function closeEvalModal() {
    document.getElementById('evalModal').classList.add('hidden');
}

document.getElementById('evalForm').addEventListener('change', updateEvalOverall);

document.getElementById('evalForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const errorEl = document.getElementById('evalFormError');
    const saveBtn = document.getElementById('evalSaveBtn');
    errorEl.classList.add('hidden');

    if (saveBtn.disabled) return; // already saving -- ignore repeat clicks

    const overall = computeEvalOverall();
    if (overall === null) {
        errorEl.textContent = 'Please score all five performance areas.';
        errorEl.classList.remove('hidden');
        return;
    }

    const payload = Object.fromEntries(new FormData(this).entries());
    payload.action = payload.evaluation_id ? 'update' : 'create';
    // Existing columns keep working for the Performance tab and driver modal:
    // rating = overall score (0-5), kpi = performance level label.
    payload.rating = overall.toFixed(2);
    payload.kpi = evalLevelFor(overall).label;

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    try {
        await postEvaluation(payload);
        window.location.reload();
    } catch (err) {
        errorEl.textContent = err.message;
        errorEl.classList.remove('hidden');
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Evaluation';
    }
});

async function deleteEvaluation(id) {
    
    if (!confirm('Delete this evaluation? This cannot be undone.')) return;

    try {
        await postEvaluation({ action: 'delete', evaluation_id: id });
        window.location.reload();
    } catch (err) {
        alert(err.message);
    }
}

const PROOF_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
const PROOF_ZOOM_MIN = 1;
const PROOF_ZOOM_MAX = 5;

// Zoom/pan state for the proof image -- kept as plain vars since only one
// proof preview is ever open at a time.
let proofZoom = 1;
let proofPanX = 0;
let proofPanY = 0;
let proofDragging = false;
let proofDragStartX = 0;
let proofDragStartY = 0;
let proofPanStartX = 0;
let proofPanStartY = 0;

function openProofModal(url) {
    const ext = (url.split('.').pop() || '').toLowerCase().split('?')[0];
    const img = document.getElementById('proofModalImg');
    const wrap = document.getElementById('proofModalImgWrap');
    const fallback = document.getElementById('proofModalFallback');
    const link = document.getElementById('proofModalLink');

    proofZoomReset();

    if (PROOF_IMAGE_EXT.includes(ext)) {
        img.src = url;
        wrap.classList.remove('hidden');
        fallback.classList.add('hidden');
    } else {
        wrap.classList.add('hidden');
        img.src = '';
        link.href = url;
        fallback.classList.remove('hidden');
    }
    document.getElementById('proofModal').classList.remove('hidden');
}

function closeProofModal() {
    document.getElementById('proofModal').classList.add('hidden');
    document.getElementById('proofModalImg').src = '';
    proofZoomReset();
}

function applyProofTransform() {
    const img = document.getElementById('proofModalImg');
    img.style.transform = `translate(calc(-50% + ${proofPanX}px), calc(-50% + ${proofPanY}px)) scale(${proofZoom})`;
    img.style.cursor = proofZoom > 1 ? (proofDragging ? 'grabbing' : 'grab') : 'zoom-in';
    document.getElementById('proofZoomLabel').textContent = Math.round(proofZoom * 100) + '%';
}

function proofClampPan() {
    // Rough clamp so the image can't be dragged endlessly off-screen once zoomed.
    const wrap = document.getElementById('proofModalImgWrap');
    const maxOffset = (proofZoom - 1) * Math.max(wrap.clientWidth, wrap.clientHeight) * 0.6;
    proofPanX = Math.max(-maxOffset, Math.min(maxOffset, proofPanX));
    proofPanY = Math.max(-maxOffset, Math.min(maxOffset, proofPanY));
}

function proofZoomBy(delta) {
    proofZoom = Math.max(PROOF_ZOOM_MIN, Math.min(PROOF_ZOOM_MAX, proofZoom + delta));
    if (proofZoom === PROOF_ZOOM_MIN) { proofPanX = 0; proofPanY = 0; }
    proofClampPan();
    applyProofTransform();
}

function proofZoomReset() {
    proofZoom = 1;
    proofPanX = 0;
    proofPanY = 0;
    proofDragging = false;
    applyProofTransform();
}

(function initProofZoomInteractions() {
    const wrap = document.getElementById('proofModalImgWrap');
    const img = document.getElementById('proofModalImg');
    if (!wrap || !img) return;

    // Scroll wheel to zoom in/out (desktop).
    wrap.addEventListener('wheel', function (e) {
        e.preventDefault();
        proofZoomBy(e.deltaY < 0 ? 0.35 : -0.35);
    }, { passive: false });

    // Click to zoom in when not zoomed; drag to pan once zoomed.
    img.addEventListener('mousedown', function (e) {
        if (proofZoom <= 1) return;
        e.preventDefault();
        proofDragging = true;
        proofDragStartX = e.clientX;
        proofDragStartY = e.clientY;
        proofPanStartX = proofPanX;
        proofPanStartY = proofPanY;
        applyProofTransform();
    });
    window.addEventListener('mousemove', function (e) {
        if (!proofDragging) return;
        proofPanX = proofPanStartX + (e.clientX - proofDragStartX);
        proofPanY = proofPanStartY + (e.clientY - proofDragStartY);
        proofClampPan();
        applyProofTransform();
    });
    window.addEventListener('mouseup', function () {
        if (!proofDragging) return;
        proofDragging = false;
        applyProofTransform();
    });
    img.addEventListener('click', function () {
        if (proofDragging) return;
        if (proofZoom <= 1) proofZoomBy(1.5);
    });
    img.addEventListener('dblclick', proofZoomReset);

    // Basic touch support: one-finger drag pans when zoomed, pinch to zoom.
    let touchStartDist = null;
    let touchStartZoom = 1;
    wrap.addEventListener('touchstart', function (e) {
        if (e.touches.length === 2) {
            touchStartDist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            touchStartZoom = proofZoom;
        } else if (e.touches.length === 1 && proofZoom > 1) {
            proofDragging = true;
            proofDragStartX = e.touches[0].clientX;
            proofDragStartY = e.touches[0].clientY;
            proofPanStartX = proofPanX;
            proofPanStartY = proofPanY;
        }
    }, { passive: true });
    wrap.addEventListener('touchmove', function (e) {
        if (e.touches.length === 2 && touchStartDist) {
            e.preventDefault();
            const dist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            proofZoom = Math.max(PROOF_ZOOM_MIN, Math.min(PROOF_ZOOM_MAX, touchStartZoom * (dist / touchStartDist)));
            proofClampPan();
            applyProofTransform();
        } else if (e.touches.length === 1 && proofDragging) {
            proofPanX = proofPanStartX + (e.touches[0].clientX - proofDragStartX);
            proofPanY = proofPanStartY + (e.touches[0].clientY - proofDragStartY);
            proofClampPan();
            applyProofTransform();
        }
    }, { passive: false });
    wrap.addEventListener('touchend', function (e) {
        if (e.touches.length < 2) touchStartDist = null;
        if (e.touches.length === 0) proofDragging = false;
    });
})();

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeProofModal();
});
</script>