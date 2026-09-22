<?php
/* Fuel Management — stats + fuel log table, backed by public.fuel_logs
 *
 * Fuel logs are inserted by the mobile app straight into fuel_logs with
 * validation = 'Pending'. There is no manual "Add Log" here anymore — the
 * admin reviews each driver's Pending logs in the row popup and approves
 * ('Verified') or rejects ('Rejected') them.
 *
 * Rows display by Driver rather than Vehicle: every driver in the drivers table
 * (the ones added under Fleet & Vehicle Management > Drivers > Add driver) gets a
 * row, and their logs come from fuel_logs.vehicle_id -> the vehicle's
 * assigned_driver_id -> drivers. Each driver's Fleet Card metadata
 * (card number, cost center, monthly limit, statement period) comes from
 * fleet_cards, keyed by driver_id — see fleet_cards_migration.sql.
 */
require_once __DIR__ . '/UI_helpers.php';
require_once __DIR__ . '/../config/ftms_db.php';

// ---- Handle: Approve / Reject a Pending fuel log (AJAX from the view popup) --
// Logs sent by the mobile app arrive as 'Pending'. index.php has usually
// already echoed HTML by the time this file runs, so the result is returned
// inside an HTML-comment marker that the JS below extracts.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fuel_validate'])) {
    $fvId     = (int) ($_POST['fuel_log_id'] ?? 0);
    $fvAction = $_POST['fuel_action'] ?? '';
    $fvMap    = ['approve' => 'Verified', 'reject' => 'Rejected'];
    $fvResult = ['success' => false, 'message' => 'Invalid request.'];

    if ($fvId > 0 && isset($fvMap[$fvAction])) {
        // Only Pending logs can be processed (prevents double approve/reject).
        $fvRes = pg_query_params(
            $conn,
            "UPDATE fuel_logs SET validation = $1
             WHERE fuel_log_id = $2 AND LOWER(COALESCE(validation, '')) = 'pending'
             RETURNING fuel_log_id",
            [$fvMap[$fvAction], $fvId]
        );
        $fvResult = ($fvRes && pg_num_rows($fvRes) === 1)
            ? ['success' => true, 'status' => $fvMap[$fvAction]]
            : ['success' => false, 'message' => 'Log was already processed or not found.'];
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    echo '<!--FUEL_VALIDATE:' . json_encode($fvResult) . '-->';
    exit;
}

// Logs from the mobile app start as 'Pending'. Only approved ('Verified') logs
// count toward the monthly totals; Pending, Rejected and Flagged ones don't.
$totalLitersRow = pg_fetch_assoc(pg_query($conn, "
    SELECT COALESCE(SUM(liters),0) AS liters FROM fuel_logs
    WHERE log_date >= date_trunc('month', CURRENT_DATE) AND validation = 'Verified'
"));
$totalLiters = (float) $totalLitersRow['liters'];

$totalCostRow = pg_fetch_assoc(pg_query($conn, "
    SELECT COALESCE(SUM(cost),0) AS cost FROM fuel_logs
    WHERE log_date >= date_trunc('month', CURRENT_DATE) AND validation = 'Verified'
"));
$totalFuelCost = (float) $totalCostRow['cost'];

$flaggedCount = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM fuel_logs WHERE validation = 'Flagged'"), 0, 0);

// Every driver from the drivers table (the same table Fleet & Vehicle
// Management > Add driver writes to) — active drivers, plus any inactive
// driver who already has fuel logs so their history stays reachable.
$driversRes = pg_query($conn, "
    SELECT d.driver_id, d.first_name, d.last_name
    FROM drivers d
    WHERE LOWER(d.status) = 'active'
       OR d.driver_id IN (
            SELECT v.assigned_driver_id
            FROM vehicles v
            JOIN fuel_logs f ON f.vehicle_id = v.vehicle_id
            WHERE v.assigned_driver_id IS NOT NULL
       )
    ORDER BY d.last_name, d.first_name
");
$driverOptions = [];
while ($d = pg_fetch_assoc($driversRes)) {
    $driverOptions[] = $d;
}

// ---- Per-driver Fleet Card (header block in the view modal) ---------------
$fleetCardsRes = pg_query($conn, "
    SELECT driver_id, provider, card_number, cost_center, account_no, monthly_limit,
           statement_period_start, statement_period_end, previous_month_consumption
    FROM fleet_cards
");
$fleetCardByDriver = [];
while ($fc = pg_fetch_assoc($fleetCardsRes)) {
    $fleetCardByDriver[(int) $fc['driver_id']] = [
        'provider'               => $fc['provider'],
        'card_number'            => $fc['card_number'],
        'cost_center'            => $fc['cost_center'],
        'account_no'             => $fc['account_no'],
        'monthly_limit'          => $fc['monthly_limit'] !== null ? (float) $fc['monthly_limit'] : null,
        'statement_period_start' => $fc['statement_period_start'],
        'statement_period_end'   => $fc['statement_period_end'],
        'prev_month_consumption' => $fc['previous_month_consumption'] !== null ? (float) $fc['previous_month_consumption'] : null,
    ];
}

// ---- Per-driver transaction history + monthly liters (view modal chart) ---
// Km Driven / Avg Km per Liter aren't stored columns — the Petron statement
// derives them from the odometer reading at the previous fill-up for the
// same vehicle, so we do the same with a LAG() window function here.
$allLogsRes = pg_query($conn, "
    SELECT f.fuel_log_id, f.vehicle_id, f.log_date, f.liters, f.cost, f.odometer_km,
           f.validation, f.fuel_type, f.fuel_station, f.price_per_liter, f.payment_method, f.notes,
           v.assigned_driver_id,
           LAG(f.odometer_km) OVER (PARTITION BY f.vehicle_id ORDER BY f.log_date, f.fuel_log_id) AS prev_odometer_km
    FROM fuel_logs f
    LEFT JOIN vehicles v ON v.vehicle_id = f.vehicle_id
    ORDER BY v.assigned_driver_id, f.log_date, f.fuel_log_id
");

$driverTransactions = [];
$driverMonthlyLiters = [];

while ($t = pg_fetch_assoc($allLogsRes)) {
    $driverId = (int) $t['assigned_driver_id'];

    $kmDriven = null;
    if ($t['odometer_km'] !== null && $t['prev_odometer_km'] !== null) {
        $diff = (float) $t['odometer_km'] - (float) $t['prev_odometer_km'];
        $kmDriven = $diff >= 0 ? $diff : null; // odometer rollback/typo — leave blank rather than show a negative
    }
    $avgKmPerLiter = ($kmDriven !== null && (float) $t['liters'] > 0) ? $kmDriven / (float) $t['liters'] : null;

    if (!isset($driverTransactions[$driverId])) {
        $driverTransactions[$driverId] = [];
    }
    $driverTransactions[$driverId][] = [
        'fuel_log_id'      => (int) $t['fuel_log_id'],
        'log_date'         => $t['log_date'],
        'fuel_station'     => $t['fuel_station'],
        'fuel_type'        => $t['fuel_type'],
        'liters'           => (float) $t['liters'],
        'price_per_liter'  => $t['price_per_liter'] !== null ? (float) $t['price_per_liter'] : null,
        'cost'             => (float) $t['cost'],
        'odometer_km'      => $t['odometer_km'] !== null ? (float) $t['odometer_km'] : null,
        'km_driven'        => $kmDriven,
        'avg_km_per_liter' => $avgKmPerLiter,
        'validation'       => $t['validation'],
        'payment_method'   => $t['payment_method'],
    ];

    $monthKey = date('Y-m', strtotime($t['log_date']));
    if (!isset($driverMonthlyLiters[$driverId])) {
        $driverMonthlyLiters[$driverId] = [];
    }
    if (!isset($driverMonthlyLiters[$driverId][$monthKey])) {
        $driverMonthlyLiters[$driverId][$monthKey] = 0.0;
    }
    $driverMonthlyLiters[$driverId][$monthKey] += (float) $t['liters'];
}
// Pending logs per driver — drives the "Review pending" button in the Actions column.
$pendingByDriver = [];
foreach ($driverTransactions as $pDriverId => $pList) {
    $pendingByDriver[$pDriverId] = 0;
    foreach ($pList as $pt) {
        if (strtolower((string) $pt['validation']) === 'pending') {
            $pendingByDriver[$pDriverId]++;
        }
    }
}
?>

<!-- Fuel Management -->
<style>
</style>
<section id="fuel" class="section space-y-4 text-slate-700 dark:text-slate-400">

    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900 dark:text-white">Fuel Management</h1>
                <span class="text-xs font-medium px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 border border-blue-500/20">
                    FMS Module
                </span>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Fuel Cost Management
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="modules/fuel_report.php" target="_blank" rel="noopener"
               class="bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                <i class="ti ti-download"></i> Download Full Report
            </a>
        </div>
    </div>


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total Liters</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= number_format($totalLiters, 1) ?> L</p>
                    <p class="text-xs text-slate-400 mt-1">This month</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <i class="ti ti-gas-station text-blue-500 dark:text-blue-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total Fuel Cost</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white">&#8369;<?= number_format($totalFuelCost, 2) ?></p>
                    <p class="text-xs text-slate-400 mt-1">This month</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <i class="ti ti-currency-peso text-emerald-500 dark:text-emerald-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Flagged Logs</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $flaggedCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Needs review</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(239 68 68 / 0.1)">
                    <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 text-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
        <div class="flex justify-between items-center mb-4">
            <p class="text-sm text-slate-500">Fuel logs</p>
        </div>
        <div class="flex flex-wrap items-end gap-4 pb-4">
            <div>
                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1.5">Driver</label>
                <select id="fuelFilterDriver" onchange="filterFuelLogs()"
                    class="border border-slate-200 dark:border-slate-700 rounded-lg px-3.5 py-2.5 text-sm min-w-[160px] text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800">
                    <option value="">All drivers</option>
                    <?php foreach ($driverOptions as $d): ?>
                        <option value="<?= (int) $d['driver_id'] ?>">
                            <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="fuelFilterName" class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1.5">Search name</label>
                <div class="relative">
                    <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500"></i>
                    <input type="text" id="fuelFilterName" oninput="filterFuelLogs()"
                        placeholder="Type a driver name..." autocomplete="off"
                        style="padding-left:2.25rem;" class="border border-slate-200 dark:border-slate-700 rounded-lg pl-9 pr-3.5 py-2.5 text-sm w-[260px] text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>
            </div>

            <button type="button" onclick="resetFuelFilters()"
                class="text-sm px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                Clear filters
            </button>

            <span id="fuelFilterCount" class="text-xs text-slate-400 dark:text-slate-500 ml-auto self-center whitespace-nowrap"></span>
        </div>

    <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900 mt-4">
    <table class="w-full text-sm dark:bg-slate-900">
        <thead>
            <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
            <th class="py-3 px-4 font-normal">Driver</th>
            <th class="font-normal">Actions</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        <?php
        // One row per driver, pulled straight from the drivers table — so a driver
        // added in Fleet & Vehicle Management shows up here right away, even with
        // no fuel logs yet. Logs are tied to drivers through vehicles.assigned_driver_id.
        // Logs on a vehicle with no assigned driver are grouped into one "Unassigned" row.
        // All of a driver's log details (totals, odometer, validation) are in the row popup.
        $res = pg_query($conn, "
            SELECT * FROM (
                SELECT d.driver_id, d.first_name, d.last_name,
                       COUNT(f.fuel_log_id) AS log_count,
                       (ARRAY_AGG(v.vehicle_id   ORDER BY f.log_date DESC NULLS LAST, f.fuel_log_id DESC NULLS LAST, v.vehicle_id))[1] AS vehicle_id,
                       (ARRAY_AGG(v.plate_number ORDER BY f.log_date DESC NULLS LAST, f.fuel_log_id DESC NULLS LAST, v.vehicle_id))[1] AS plate_number
                FROM drivers d
                LEFT JOIN vehicles v   ON v.assigned_driver_id = d.driver_id
                LEFT JOIN fuel_logs f  ON f.vehicle_id = v.vehicle_id
                GROUP BY d.driver_id, d.first_name, d.last_name, d.status
                HAVING LOWER(d.status) = 'active' OR COUNT(f.fuel_log_id) > 0

                UNION ALL

                SELECT NULL::int, NULL::text, NULL::text,
                       COUNT(f.fuel_log_id),
                       (ARRAY_AGG(f.vehicle_id   ORDER BY f.log_date DESC, f.fuel_log_id DESC))[1],
                       (ARRAY_AGG(v.plate_number ORDER BY f.log_date DESC, f.fuel_log_id DESC))[1]
                FROM fuel_logs f
                LEFT JOIN vehicles v ON v.vehicle_id = f.vehicle_id
                WHERE v.assigned_driver_id IS NULL
                HAVING COUNT(f.fuel_log_id) > 0
            ) t
            ORDER BY (driver_id IS NULL), last_name, first_name
        ");
        if (pg_num_rows($res) === 0): ?>
            <tr><td colspan="2" class="text-center text-slate-400 dark:text-slate-500 py-6">No drivers yet. Add a driver in Fleet &amp; Vehicle Management.</td></tr>
        <?php else: while ($row = pg_fetch_assoc($res)):
            $rowDriverId = (int) ($row['driver_id'] ?? 0);
            $driverName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $driverName = $driverName !== '' ? $driverName : 'Unassigned';
            $logCount = (int) $row['log_count'];
            $hasVehicle = !empty($row['vehicle_id']);
            $pendingCount = (int) ($pendingByDriver[$rowDriverId] ?? 0);
        ?>
            <tr
                class="fuel-log-row cursor-pointer hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition-colors"
                data-driver-id="<?= $rowDriverId ?>"
                data-driver-name="<?= htmlspecialchars(mb_strtolower($driverName)) ?>"
                onclick='openViewFuelLogModal(<?= $rowDriverId ?>, <?= json_encode($driverName, JSON_HEX_APOS) ?>, <?= json_encode($row['plate_number'] ?? '—', JSON_HEX_APOS) ?>, "all")'>
                <td class="py-3 px-4">
                    <div class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($driverName) ?></div>
                    <div class="text-xs text-slate-400 dark:text-slate-500">
                        <?= $hasVehicle ? htmlspecialchars($row['plate_number'] ?? '—') : 'No vehicle assigned' ?> &middot; <?= $logCount ?> <?= $logCount === 1 ? 'log' : 'logs' ?>
                    </div>
                </td>
                <td onclick="event.stopPropagation()" class="whitespace-nowrap fuel-action-cell" data-driver-id="<?= $rowDriverId ?>">
                    <button type="button"
                        onclick='openViewFuelLogModal(<?= $rowDriverId ?>, <?= json_encode($driverName, JSON_HEX_APOS) ?>, <?= json_encode($row['plate_number'] ?? '—', JSON_HEX_APOS) ?>, "pending")'
                        class="fuel-pending-btn text-xs px-3 py-1.5 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors <?= $pendingCount > 0 ? '' : 'hidden' ?>">
                        <i class="ti ti-checklist"></i> Review pending (<span class="fuel-pending-count"><?= $pendingCount ?></span>)
                    </button>
                    <span class="fuel-no-pending text-xs text-slate-400 dark:text-slate-500 <?= $pendingCount > 0 ? 'hidden' : '' ?>">No pending</span>
                </td>
            </tr>
        <?php endwhile; endif; ?>
        <tr id="fuelNoResultsRow" class="hidden">
            <td colspan="2" class="text-center text-slate-400 dark:text-slate-500 py-6">No drivers match your filter.</td>
        </tr>
        </tbody>
    </table>
    </div>
    </div>

    <!-- ================= VIEW FUEL LOG MODAL ================= -->
    <div id="viewFuelLogModal"
         class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">

            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Fuel Log Details</h2>
                    <p id="viewFuelDriver" class="text-sm text-slate-500 dark:text-slate-400"></p>
                </div>
                <button type="button" onclick="closeViewFuelLogModal()" class="text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 text-xl">&times;</button>
            </div>

            <div class="p-6 space-y-6 text-sm">

                <div id="viewFuelAccountInfo" class="space-y-6">
                <!-- Fleet Card -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-2">Fleet Card</p>
                    <div class="grid grid-cols-4 gap-4 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Provider</p><p id="viewFuelProvider" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Card Number</p><p id="viewFuelCardNumber" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Cardholder</p><p id="viewFuelCardholder" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Cost Center</p><p id="viewFuelCostCenter" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Monthly Limit</p><p id="viewFuelMonthlyLimit" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Vehicle</p><p id="viewFuelPlate" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Statement Period</p><p id="viewFuelStatementPeriod" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Prev. Month Consumption</p><p id="viewFuelPrevConsumption" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                        <div><p class="text-slate-400 dark:text-slate-500 text-xs">Account No.</p><p id="viewFuelAccountNo" class="font-medium text-slate-800 dark:text-slate-200"></p></div>
                    </div>
                </div>

                <!-- Monthly Consumption Chart -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Cards Details and Analysis</p>
                        <button type="button" id="viewFuelMonthReset" onclick="selectMonth(null)"
                            class="text-xs text-blue-600 dark:text-blue-400 hover:underline hidden">Show all months</button>
                    </div>
                    <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-4">
                        <div id="viewFuelChart" class="flex items-end gap-2" style="height:120px;"></div>
                        <p id="viewFuelMonthLabel" class="text-xs text-slate-400 dark:text-slate-500 mt-2">Click a bar to see that month's transactions.</p>
                    </div>
                </div>
                </div>

                <!-- Details of Transactions -->
                <div>
                    <p id="viewFuelTxnTitle" class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-2">Details of Transactions</p>
                    <div class="border border-slate-200 dark:border-slate-700 rounded-lg overflow-x-auto">
                        <table class="w-full text-xs whitespace-nowrap">
                            <thead>
                                <tr class="text-left text-slate-400 dark:text-slate-500 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700">
                                    <th class="py-2 px-3 font-medium">Posting Date</th>
                                    <th class="font-medium">Station</th>
                                    <th class="font-medium">Product</th>
                                    <th class="font-medium">Qty (L)</th>
                                    <th class="font-medium">Price/L</th>
                                    <th class="font-medium">Amount</th>
                                    <th class="font-medium">Odometer</th>
                                    <th class="font-medium">Km Driven</th>
                                    <th class="font-medium">Avg Km/L</th>
                                    <th class="font-medium">Validation</th>
                                </tr>
                            </thead>
                            <tbody id="viewFuelTxnBody" class="text-slate-700 dark:text-slate-300"></tbody>
                            <tfoot id="viewFuelTxnFoot" class="font-semibold border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-800 dark:text-slate-200"></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</section>

<script>
// Embedded once at page load — per-driver Fleet Card info, full transaction
// history, and monthly liter totals (used to draw the chart and the
// Details of Transactions table without a round trip per row click).
const driverFleetCards    = <?= json_encode($fleetCardByDriver, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const driverTransactions  = <?= json_encode($driverTransactions, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const driverMonthlyLiters = <?= json_encode($driverMonthlyLiters, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let currentViewDriverId = null;
let currentSelectedMonth = null; // 'YYYY-MM' or null for "all months"
let currentViewMode = 'all'; // 'all' (row click, read-only status) or 'pending' (Review pending button, Approve/Reject)

const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Chart bars are drawn with inline styles, so they can't pick up Tailwind's
// `dark:` classes — check the same `dark` class the rest of the app's
// dark-mode toggle uses on <html>/<body> and pick a matching palette.
function isDarkMode() {
    return document.documentElement.classList.contains('dark') ||
           document.body.classList.contains('dark');
}

function openViewFuelLogModal(driverId, driverName, plate, mode) {
    currentViewDriverId = driverId;
    currentViewMode = mode === 'pending' ? 'pending' : 'all';
    currentSelectedMonth = null;

    document.getElementById('viewFuelDriver').textContent = driverName || '—';

    const accountInfo = document.getElementById('viewFuelAccountInfo');
    if (accountInfo) accountInfo.classList.toggle('hidden', currentViewMode === 'pending');

    const card = driverFleetCards[driverId] || {};
    document.getElementById('viewFuelProvider').textContent = card.provider || 'Petron';
    document.getElementById('viewFuelCardNumber').textContent = card.card_number || '—';
    document.getElementById('viewFuelCardholder').textContent = driverName || '—';
    document.getElementById('viewFuelCostCenter').textContent = card.cost_center || '—';
    document.getElementById('viewFuelMonthlyLimit').textContent =
        card.monthly_limit != null ? '₱' + Number(card.monthly_limit).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '—';
    document.getElementById('viewFuelPlate').textContent = plate || '—';
    document.getElementById('viewFuelStatementPeriod').textContent =
        (card.statement_period_start && card.statement_period_end)
            ? `${card.statement_period_start} – ${card.statement_period_end}` : '—';
    document.getElementById('viewFuelPrevConsumption').textContent =
        card.prev_month_consumption != null ? Number(card.prev_month_consumption).toFixed(2) + ' L' : '—';
    document.getElementById('viewFuelAccountNo').textContent = card.account_no || '—';

    if (currentViewMode !== 'pending') {
        renderMonthlyChart(driverId);
    }
    renderTransactionsTable(driverId, null);

    const txnTitle = document.getElementById('viewFuelTxnTitle');
    if (txnTitle) {
        txnTitle.textContent = currentViewMode === 'pending' ? 'Pending Approvals' : 'Details of Transactions';
    }

    const modal = document.getElementById('viewFuelLogModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}


function renderMonthlyChart(driverId) {
    const monthly = driverMonthlyLiters[driverId] || {};
    const chartEl = document.getElementById('viewFuelChart');
    chartEl.innerHTML = '';

    const dark = isDarkMode();
    const colorSelected = dark ? '#3b82f6' : '#2563eb';
    const colorFilled   = dark ? '#1d4ed8' : '#93c5fd';
    const colorEmpty    = dark ? '#334155' : '#e2e8f0';
    const labelColor    = dark ? '#64748b' : '#94a3b8';
    const labelHoverColor = dark ? '#cbd5e1' : '#475569';

    // Always draw Jan–Dec of the current year, like the statement's chart —
    // months with no fuel logs yet just render as empty bars.
    const year = new Date().getFullYear();
    const months = MONTH_LABELS.map((label, i) => {
        const key = `${year}-${String(i + 1).padStart(2, '0')}`;
        return { key, label, liters: monthly[key] || 0 };
    });

    const maxLiters = Math.max(1, ...months.map(m => m.liters));

    months.forEach(m => {
        const col = document.createElement('div');
        col.className = 'flex-1 flex flex-col items-center justify-end gap-1 cursor-pointer group';
        col.onclick = () => selectMonth(m.key);

        const barHeight = m.liters > 0 ? Math.max(6, (m.liters / maxLiters) * 80) : 2;
        const isSelected = currentSelectedMonth === m.key;
        const barColor = isSelected ? colorSelected : (m.liters > 0 ? colorFilled : colorEmpty);

        col.innerHTML = `
            <span class="text-[10px]" style="color:${labelColor};">${m.liters > 0 ? m.liters.toFixed(1) : ''}</span>
            <div style="height:${barHeight}px; width:100%; border-radius:3px 3px 0 0;
                        background:${barColor};
                        transition:background .12s;"></div>
            <span class="text-[10px] ${isSelected ? 'font-semibold' : ''}" style="color:${isSelected ? colorSelected : labelColor};">${m.label}</span>
        `;
        col.addEventListener('mouseenter', () => { col.style.opacity = '0.85'; });
        col.addEventListener('mouseleave', () => { col.style.opacity = '1'; });
        chartEl.appendChild(col);
    });
}

function selectMonth(monthKey) {
    currentSelectedMonth = monthKey;
    renderMonthlyChart(currentViewDriverId);
    renderTransactionsTable(currentViewDriverId, monthKey);

    const label = document.getElementById('viewFuelMonthLabel');
    const resetBtn = document.getElementById('viewFuelMonthReset');
    if (monthKey) {
        const [y, m] = monthKey.split('-');
        label.textContent = `Showing transactions for ${MONTH_LABELS[parseInt(m, 10) - 1]} ${y}.`;
        resetBtn.classList.remove('hidden');
    } else {
        label.textContent = "Click a bar to see that month's transactions.";
        resetBtn.classList.add('hidden');
    }
}

function renderTransactionsTable(driverId, monthKey) {
    let rows = driverTransactions[driverId] || [];
    if (monthKey) rows = rows.filter(t => t.log_date && t.log_date.startsWith(monthKey));
    if (currentViewMode === 'pending') rows = rows.filter(t => (t.validation || '').toLowerCase() === 'pending');

    const body = document.getElementById('viewFuelTxnBody');
    const foot = document.getElementById('viewFuelTxnFoot');
    body.innerHTML = '';

    if (rows.length === 0) {
        const emptyMsg = currentViewMode === 'pending'
            ? 'No pending transactions.'
            : `No transactions${monthKey ? ' for this month' : ''} yet.`;
        body.innerHTML = `<tr><td colspan="10" class="text-center text-slate-400 dark:text-slate-500 py-4">${emptyMsg}</td></tr>`;
        foot.innerHTML = '';
        return;
    }

    let totalQty = 0, totalAmount = 0;

    rows.forEach(t => {
        totalQty += t.liters;
        totalAmount += t.cost;

        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-100 dark:border-slate-800 last:border-0';
        tr.innerHTML = `
            <td class="py-2 px-3">${t.log_date || '—'}</td>
            <td>${t.fuel_station || '—'}</td>
            <td>${t.fuel_type || '—'}</td>
            <td>${Number(t.liters).toFixed(2)}</td>
            <td>${t.price_per_liter != null ? '₱' + Number(t.price_per_liter).toFixed(2) : '—'}</td>
            <td>₱${Number(t.cost).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
            <td>${t.odometer_km != null ? Number(t.odometer_km).toLocaleString() : '—'}</td>
            <td>${t.km_driven != null ? Number(t.km_driven).toFixed(0) : '—'}</td>
            <td>${t.avg_km_per_liter != null ? Number(t.avg_km_per_liter).toFixed(2) : '—'}</td>
            <td class="pr-3">${validationCell(t)}</td>
        `;
        body.appendChild(tr);
    });

    foot.innerHTML = `
        <tr>
            <td class="py-2 px-3" colspan="3">Total</td>
            <td>${totalQty.toFixed(2)}</td>
            <td></td>
            <td>₱${totalAmount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
            <td colspan="3"></td>
        </tr>
    `;
}

function validationCell(t) {
    const v = (t.validation || '').toLowerCase();

    if (currentViewMode === 'pending' && v === 'pending') {
        return `
            <div class="flex items-center gap-1">
                <button type="button" onclick="setFuelValidation(${t.fuel_log_id}, 'approve', this)"
                        class="px-2.5 py-1 rounded-md bg-blue-600 text-white text-[11px] font-medium hover:bg-blue-700 transition-colors">
                    Approve
                </button>
                <button type="button" onclick="setFuelValidation(${t.fuel_log_id}, 'reject', this)"
                        class="px-2.5 py-1 rounded-md border border-red-200 bg-red-50 text-red-700 text-[11px] font-medium hover:bg-red-100 transition-colors dark:bg-red-950/30 dark:border-red-900/60 dark:text-red-400">
                    Reject
                </button>
            </div>`;
    }

    const styles = {
        verified: 'text-green-600 dark:text-green-400',
        rejected: 'text-red-600 dark:text-red-400',
        flagged:  'text-amber-600 dark:text-amber-400',
    };
    return `<span class="font-medium ${styles[v] || ''}">${t.validation || '—'}</span>`;
}

// Keeps the "Review pending (N)" button in the main table in sync after an approve/reject.
function refreshPendingCell(driverId) {
    const cell = document.querySelector(`.fuel-action-cell[data-driver-id="${driverId}"]`);
    if (!cell) return;
    const pending = (driverTransactions[driverId] || [])
        .filter(t => (t.validation || '').toLowerCase() === 'pending').length;
    cell.querySelector('.fuel-pending-count').textContent = pending;
    cell.querySelector('.fuel-pending-btn').classList.toggle('hidden', pending === 0);
    cell.querySelector('.fuel-no-pending').classList.toggle('hidden', pending !== 0);
}

async function setFuelValidation(logId, action, btn) {
    if (action === 'reject' && !confirm('Reject this fuel log?')) return;

    const buttons = btn.parentElement.querySelectorAll('button');
    buttons.forEach(b => b.disabled = true);

    try {
        const res = await fetch(window.location.href.split('#')[0], {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ fuel_validate: '1', fuel_log_id: logId, fuel_action: action })
        });
        const text = await res.text();
        const m = text.match(/<!--FUEL_VALIDATE:(\{.*?\})-->/);
        if (!m) throw new Error('No response from the server.');
        const data = JSON.parse(m[1]);
        if (!data.success) throw new Error(data.message || 'Could not update the log.');

        // Update the in-page data, then redraw the popup table.
        for (const list of Object.values(driverTransactions)) {
            const t = list.find(x => x.fuel_log_id === logId);
            if (t) { t.validation = data.status; break; }
        }
        renderTransactionsTable(currentViewDriverId, currentSelectedMonth);
        refreshPendingCell(currentViewDriverId);
    } catch (err) {
        alert(err.message);
        buttons.forEach(b => b.disabled = false);
    }
}

function closeViewFuelLogModal() {
    const modal = document.getElementById('viewFuelLogModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function filterFuelLogs() {
    const driverId = document.getElementById('fuelFilterDriver').value;
    const query    = document.getElementById('fuelFilterName').value.trim().toLowerCase();
    const rows = document.querySelectorAll('.fuel-log-row');
    let visibleCount = 0;

    rows.forEach(row => {
        // Name search matches the start of the name or of any word in it
        // (first name, middle name, last name) — not the middle of a word.
        const name = ' ' + (row.dataset.driverName || '');
        let visible = true;
        if (driverId && row.dataset.driverId !== driverId) visible = false;
        if (query && !name.includes(' ' + query)) visible = false;

        row.classList.toggle('hidden', !visible);
        if (visible) visibleCount++;
    });

    document.getElementById('fuelNoResultsRow').classList.toggle('hidden', visibleCount !== 0);
    document.getElementById('fuelFilterCount').textContent =
        (driverId || query) ? `Showing ${visibleCount} of ${rows.length} drivers` : '';
}

function resetFuelFilters() {
    document.getElementById('fuelFilterDriver').value = '';
    document.getElementById('fuelFilterName').value = '';
    filterFuelLogs();
}
</script>