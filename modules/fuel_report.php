<?php
/* Fuel Management — Full Report (monthly, per-driver)
 *
 * Standalone/printable page linked from fuel.php's "Download Full Report"
 * button. Renders one Petron-style Fleet Card statement per driver for a
 * selected calendar month, then lets the admin use the browser's own
 * Print > Save as PDF to download it — no PDF library dependency needed.
 *
 * Filters (GET):
 *   driver_id     int or 'all' (default 'all')
 *   month         'YYYY-MM' (default: current month)
 *   include_empty '1' to also print drivers with zero Verified logs that month
 *
 * Place this file in modules/ next to fuel.php (uses the same
 * ../config/ftms_db.php connection).
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../landing.php');
    exit();
}
require_once __DIR__ . '/../config/ftms_db.php';

// Edit this to your company's name — shown on every statement, same as the
// "Priority Handling Logistics, Inc." line on the paper Petron statement.
$companyName = 'Priority Handling Logistics, Inc.';

$selectedDriverId = (isset($_GET['driver_id']) && $_GET['driver_id'] !== 'all' && $_GET['driver_id'] !== '')
    ? (int) $_GET['driver_id']
    : null;

$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$selectedYear = substr($selectedMonth, 0, 4);
$includeEmpty = isset($_GET['include_empty']);

$monthLabel = date('F Y', strtotime($selectedMonth . '-01'));

// ---- Drivers (same "who shows up" rule as fuel.php) ------------------------
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
$allDrivers = [];
while ($d = pg_fetch_assoc($driversRes)) {
    $allDrivers[(int) $d['driver_id']] = trim($d['first_name'] . ' ' . $d['last_name']);
}

$reportDriverIds = $selectedDriverId !== null
    ? (isset($allDrivers[$selectedDriverId]) ? [$selectedDriverId] : [])
    : array_keys($allDrivers);

// ---- Fleet Card per driver ---------------------------------------------
$fleetCardsRes = pg_query($conn, "
    SELECT driver_id, provider, card_number, cost_center, account_no, monthly_limit,
           statement_period_start, statement_period_end, previous_month_consumption
    FROM fleet_cards
");
$fleetCardByDriver = [];
while ($fc = pg_fetch_assoc($fleetCardsRes)) {
    $fleetCardByDriver[(int) $fc['driver_id']] = $fc;
}

// ---- Current vehicle plate per driver --------------------------------------
$vehicleRes = pg_query($conn, "
    SELECT DISTINCT ON (assigned_driver_id) assigned_driver_id, plate_number
    FROM vehicles
    WHERE assigned_driver_id IS NOT NULL
    ORDER BY assigned_driver_id, vehicle_id DESC
");
$plateByDriver = [];
while ($v = pg_fetch_assoc($vehicleRes)) {
    $plateByDriver[(int) $v['assigned_driver_id']] = $v['plate_number'];
}

// ---- All Verified logs, with km-driven via LAG (all-time, so month-boundary
// km readings stay accurate), then bucketed by month per driver -------------
$logsRes = pg_query($conn, "
    SELECT f.log_date, f.liters, f.cost, f.odometer_km, f.fuel_type, f.fuel_station,
           f.price_per_liter, v.assigned_driver_id,
           LAG(f.odometer_km) OVER (PARTITION BY f.vehicle_id ORDER BY f.log_date, f.fuel_log_id) AS prev_odometer_km
    FROM fuel_logs f
    LEFT JOIN vehicles v ON v.vehicle_id = f.vehicle_id
    WHERE f.validation = 'Verified' AND v.assigned_driver_id IS NOT NULL
    ORDER BY v.assigned_driver_id, f.log_date
");

$txByDriverMonth      = [];  // driverId => 'YYYY-MM' => [ tx, ... ]  (only $selectedMonth is used for the table)
$monthlyLitersByYear  = [];  // driverId => 'YYYY-MM' => liters       (Jan-Dec of $selectedYear, for the chart)
$monthlyAvgKmLByYear  = [];  // driverId => 'YYYY-MM' => avg km/L     (Jan-Dec of $selectedYear, for the chart)
$monthlyKmSumTmp      = [];
$monthlyLiterSumTmp   = [];

while ($t = pg_fetch_assoc($logsRes)) {
    $driverId = (int) $t['assigned_driver_id'];
    $monthKey = date('Y-m', strtotime($t['log_date']));

    $kmDriven = null;
    if ($t['odometer_km'] !== null && $t['prev_odometer_km'] !== null) {
        $diff = (float) $t['odometer_km'] - (float) $t['prev_odometer_km'];
        $kmDriven = $diff >= 0 ? $diff : null;
    }
    $avgKmL = ($kmDriven !== null && (float) $t['liters'] > 0) ? $kmDriven / (float) $t['liters'] : null;

    if (substr($monthKey, 0, 4) === $selectedYear) {
        $monthlyLitersByYear[$driverId][$monthKey] = ($monthlyLitersByYear[$driverId][$monthKey] ?? 0) + (float) $t['liters'];
        if ($kmDriven !== null) {
            $monthlyKmSumTmp[$driverId][$monthKey]    = ($monthlyKmSumTmp[$driverId][$monthKey] ?? 0) + $kmDriven;
            $monthlyLiterSumTmp[$driverId][$monthKey] = ($monthlyLiterSumTmp[$driverId][$monthKey] ?? 0) + (float) $t['liters'];
        }
    }

    if ($monthKey === $selectedMonth) {
        $txByDriverMonth[$driverId][] = [
            'log_date'         => $t['log_date'],
            'fuel_station'     => $t['fuel_station'],
            'fuel_type'        => $t['fuel_type'],
            'liters'           => (float) $t['liters'],
            'price_per_liter'  => $t['price_per_liter'] !== null ? (float) $t['price_per_liter'] : null,
            'cost'             => (float) $t['cost'],
            'odometer_km'      => $t['odometer_km'] !== null ? (float) $t['odometer_km'] : null,
            'km_driven'        => $kmDriven,
            'avg_km_per_liter' => $avgKmL,
        ];
    }
}
foreach ($monthlyKmSumTmp as $driverId => $months) {
    foreach ($months as $monthKey => $kmSum) {
        $literSum = $monthlyLiterSumTmp[$driverId][$monthKey] ?? 0;
        $monthlyAvgKmLByYear[$driverId][$monthKey] = $literSum > 0 ? $kmSum / $literSum : null;
    }
}

// Narrow the report list to drivers with data this month, unless asked to include everyone.
if (!$includeEmpty) {
    $reportDriverIds = array_values(array_filter(
        $reportDriverIds,
        fn($id) => !empty($txByDriverMonth[$id])
    ));
}

function money($n) {
    return $n === null ? '—' : '₱' . number_format((float) $n, 2);
}
function num($n, $dec = 2) {
    return $n === null ? '—' : number_format((float) $n, $dec);
}

$monthAbbr = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun',
              '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fuel Report - <?= htmlspecialchars($monthLabel) ?></title>
<style>
    :root { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --blue:#2563eb; --orange:#f97316; --bg-soft:#f8fafc; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: Arial, Helvetica, sans-serif; color: var(--ink); margin: 0; background: #e2e8f0; }

    .toolbar {
        position: sticky; top: 0; z-index: 10;
        background: #fff; border-bottom: 1px solid var(--line);
        padding: 14px 24px; display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap;
    }
    .toolbar label { display: block; font-size: 11px; color: var(--muted); margin-bottom: 4px; }
    .toolbar select, .toolbar input[type=month] {
        border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; font-size: 13px; min-width: 170px;
    }
    .toolbar .check { display:flex; align-items:center; gap:6px; font-size: 13px; color: var(--muted); padding-bottom: 6px; }
    .toolbar button, .toolbar a.btn {
        border: none; border-radius: 6px; padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
        text-decoration: none; display: inline-block;
    }
    .btn-primary { background: var(--blue); color: #fff; }
    .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line) !important; }
    .toolbar .spacer { flex: 1 1 auto; }

    .empty-note {
        max-width: 720px; margin: 40px auto; text-align: center; color: var(--muted);
        background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 32px;
    }

    .sheet {
        max-width: 900px; margin: 24px auto; background: #fff; border: 1px solid var(--line);
        border-radius: 10px; padding: 32px; page-break-after: always;
    }
    .sheet:last-child { page-break-after: auto; }

    .sheet-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--ink); padding-bottom: 14px; margin-bottom: 18px; }
    .sheet-head h1 { font-size: 20px; margin: 0 0 2px; }
    .sheet-head .sub { font-size: 12px; color: var(--muted); }
    .sheet-head .right { text-align: right; font-size: 12px; color: var(--muted); }
    .sheet-head .right .company { font-weight: 700; color: var(--ink); font-size: 13px; }

    .section-label { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin: 0 0 8px; }

    .card-grid {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
        background: var(--bg-soft); border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 22px;
    }
    .card-grid .f-label { font-size: 10px; color: var(--muted); margin: 0 0 2px; }
    .card-grid .f-value { font-size: 13px; font-weight: 600; margin: 0; }

    .chart-box { border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 22px; }
    .chart-legend { display: flex; gap: 16px; font-size: 11px; color: var(--muted); margin-bottom: 10px; }
    .legend-dot { display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:5px; vertical-align:middle; }
    .bars { display: flex; align-items: flex-end; gap: 6px; height: 100px; border-bottom: 1px solid var(--line); }
    .bar-col { flex: 1; display: flex; align-items: flex-end; justify-content: center; gap: 2px; height: 100px; }
    .bar { width: 40%; border-radius: 2px 2px 0 0; }
    .bar-liter { background: var(--blue); }
    .bar-kml { background: var(--orange); }
    .bar-labels { display: flex; gap: 6px; margin-top: 4px; }
    .bar-labels span { flex: 1; text-align: center; font-size: 9px; color: var(--muted); }
    .bar-labels span.current { color: var(--ink); font-weight: 700; }

    table.tx { width: 100%; border-collapse: collapse; font-size: 11.5px; }
    table.tx th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); border-bottom: 1px solid var(--ink); padding: 6px 6px; }
    table.tx td { padding: 7px 6px; border-bottom: 1px solid var(--line); }
    table.tx tfoot td { font-weight: 700; border-top: 2px solid var(--ink); border-bottom: none; }
    table.tx td.num, table.tx th.num { text-align: right; }
    .tx-empty { padding: 20px 6px; text-align: center; color: var(--muted); }

    .stat-row { display: flex; justify-content: flex-end; gap: 28px; margin-top: 12px; font-size: 12px; }
    .stat-row .stat b { display:block; font-size: 15px; }
    .stat-row .stat { color: var(--muted); text-align: right; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .sheet { border: none; border-radius: 0; margin: 0 auto; box-shadow: none; }
        .empty-note { display: none; }
    }
</style>
</head>
<body>

<form class="toolbar no-print" method="get">
    <div>
        <label for="driver_id">Driver</label>
        <select name="driver_id" id="driver_id">
            <option value="all" <?= $selectedDriverId === null ? 'selected' : '' ?>>All drivers</option>
            <?php foreach ($allDrivers as $id => $name): ?>
                <option value="<?= $id ?>" <?= $selectedDriverId === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="month">Month</label>
        <input type="month" name="month" id="month" value="<?= htmlspecialchars($selectedMonth) ?>">
    </div>
    <label class="check">
        <input type="checkbox" name="include_empty" value="1" <?= $includeEmpty ? 'checked' : '' ?>>
        Include drivers with no logs this month
    </label>
    <button type="submit" class="btn-primary">Apply</button>
    <div class="spacer"></div>
    <a href="../index.php#fuel" class="btn ghost btn-ghost">&larr; Back to Fuel Management</a>
    <button type="button" class="btn-primary" onclick="window.print()">🖨 Print / Save as PDF</button>
</form>

<?php if (empty($reportDriverIds)): ?>
    <div class="empty-note">
        No <?= $selectedDriverId !== null ? 'verified fuel logs' : 'drivers with verified fuel logs' ?>
        found for <?= htmlspecialchars($monthLabel) ?>.
        <?php if (!$includeEmpty): ?><br>Check "Include drivers with no logs this month" above to see the card anyway.<?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($reportDriverIds as $driverId):
    $driverName = $allDrivers[$driverId] ?? 'Unknown';
    $card       = $fleetCardByDriver[$driverId] ?? [];
    $plate      = $plateByDriver[$driverId] ?? '—';
    $rows       = $txByDriverMonth[$driverId] ?? [];

    $totalLiters = array_sum(array_column($rows, 'liters'));
    $totalAmount = array_sum(array_column($rows, 'cost'));
    $totalKm     = array_sum(array_filter(array_column($rows, 'km_driven'), fn($v) => $v !== null));
    $avgKmL      = $totalLiters > 0 && $totalKm > 0 ? $totalKm / $totalLiters : null;
    $avgPhpKm    = $totalKm > 0 ? $totalAmount / $totalKm : null;

    $litersByMonth = $monthlyLitersByYear[$driverId] ?? [];
    $kmlByMonth    = $monthlyAvgKmLByYear[$driverId] ?? [];
    $maxLiters     = max(array_merge([1], array_values($litersByMonth)));
    $maxKmL        = max(array_merge([1], array_filter(array_values($kmlByMonth), fn($v) => $v !== null)));
?>
    <div class="sheet">
        <div class="sheet-head">
            <div>
                <h1>Fleet Card Statement</h1>
                <div class="sub"><?= htmlspecialchars($monthLabel) ?> &middot; <?= htmlspecialchars($card['provider'] ?? 'Petron') ?> Fleet Card</div>
            </div>
            <div class="right">
                <div class="company"><?= htmlspecialchars($companyName) ?></div>
                Statement Date: <?= date('m/d/Y') ?><br>
                Account No: <?= htmlspecialchars($card['account_no'] ?? '—') ?>
            </div>
        </div>

        <p class="section-label">Fleet Card</p>
        <div class="card-grid">
            <div><p class="f-label">Card Number</p><p class="f-value"><?= htmlspecialchars($card['card_number'] ?? '—') ?></p></div>
            <div><p class="f-label">Cardholder</p><p class="f-value"><?= htmlspecialchars($driverName) ?></p></div>
            <div><p class="f-label">Vehicle</p><p class="f-value"><?= htmlspecialchars($plate) ?></p></div>
            <div><p class="f-label">Cost Center</p><p class="f-value"><?= htmlspecialchars($card['cost_center'] ?? '—') ?></p></div>
            <div><p class="f-label">Monthly Limit</p><p class="f-value"><?= isset($card['monthly_limit']) ? money($card['monthly_limit']) : '—' ?></p></div>
            <div><p class="f-label">Statement Period</p><p class="f-value">
                <?= ($card['statement_period_start'] ?? null) ? date('m/d/Y', strtotime($card['statement_period_start'])) . ' – ' . date('m/d/Y', strtotime($card['statement_period_end'])) : htmlspecialchars($monthLabel) ?>
            </p></div>
            <div><p class="f-label">Prev. Month Consumption</p><p class="f-value"><?= isset($card['previous_month_consumption']) ? num($card['previous_month_consumption'], 2) . ' L' : '—' ?></p></div>
            <div><p class="f-label">Provider</p><p class="f-value"><?= htmlspecialchars($card['provider'] ?? 'Petron') ?></p></div>
        </div>

        <p class="section-label">Cards Details and Analysis</p>
        <div class="chart-box">
            <div class="chart-legend">
                <span><span class="legend-dot" style="background:var(--blue)"></span>Liter</span>
                <span><span class="legend-dot" style="background:var(--orange)"></span>Km/Liter</span>
            </div>
            <div class="bars">
                <?php
                $chartPxHeight = 100; // must match .bars height in the CSS above (minus its border)
                foreach ($monthAbbr as $mm => $label):
                    $mk = $selectedYear . '-' . $mm;
                    $lv = $litersByMonth[$mk] ?? 0;
                    $kv = $kmlByMonth[$mk] ?? null;
                    $lh = $maxLiters > 0 ? max(1, round(($lv / $maxLiters) * $chartPxHeight)) : 1;
                    $kh = ($kv !== null && $maxKmL > 0) ? max(1, round(($kv / $maxKmL) * $chartPxHeight)) : 1;
                    if ($lv <= 0) $lh = 0;
                    if ($kv === null) $kh = 0;
                ?>
                <div class="bar-col" title="<?= $label ?>: <?= num($lv, 1) ?> L<?= $kv !== null ? ', ' . num($kv, 1) . ' km/L' : '' ?>">
                    <div class="bar bar-liter" style="height: <?= $lh ?>px"></div>
                    <div class="bar bar-kml" style="height: <?= $kh ?>px"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="bar-labels">
                <?php foreach ($monthAbbr as $mm => $label): ?>
                    <span class="<?= $mm === substr($selectedMonth, 5, 2) ? 'current' : '' ?>"><?= $label ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="section-label">Details of Transactions</p>
        <table class="tx">
            <thead>
                <tr>
                    <th>Posting Date</th>
                    <th>Station</th>
                    <th>Product</th>
                    <th class="num">Qty (L)</th>
                    <th class="num">Price/L</th>
                    <th class="num">Amount</th>
                    <th class="num">Odometer</th>
                    <th class="num">Km Driven</th>
                    <th class="num">Avg Km/L</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="tx-empty">No verified transactions for <?= htmlspecialchars($monthLabel) ?>.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?= date('Y-m-d', strtotime($r['log_date'])) ?></td>
                        <td><?= htmlspecialchars($r['fuel_station'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['fuel_type'] ?? '—') ?></td>
                        <td class="num"><?= num($r['liters'], 2) ?></td>
                        <td class="num"><?= $r['price_per_liter'] !== null ? money($r['price_per_liter']) : '—' ?></td>
                        <td class="num"><?= money($r['cost']) ?></td>
                        <td class="num"><?= $r['odometer_km'] !== null ? number_format($r['odometer_km']) : '—' ?></td>
                        <td class="num"><?= $r['km_driven'] !== null ? num($r['km_driven'], 0) : '—' ?></td>
                        <td class="num"><?= $r['avg_km_per_liter'] !== null ? num($r['avg_km_per_liter'], 2) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="3">Total</td>
                    <td class="num"><?= num($totalLiters, 2) ?></td>
                    <td></td>
                    <td class="num"><?= money($totalAmount) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <div class="stat-row">
            <div class="stat">Average Php/Km<br><b><?= $avgPhpKm !== null ? num($avgPhpKm, 2) : '—' ?></b></div>
            <div class="stat">Average Km/Liter<br><b><?= $avgKmL !== null ? num($avgKmL, 2) : '—' ?></b></div>
        </div>
    </div>
<?php endforeach; ?>

</body>
</html>