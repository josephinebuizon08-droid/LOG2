<?php

require_once __DIR__ . '/UI_helpers.php';
require_once __DIR__ . '/../config/ftms_db.php';

/* =========================================================
   SUMMARY VARIABLES
   ========================================================= */

$tripsThisPeriod = 0;
$totalCost = 0.0;
$totalKm = 0.0;
$avgCostPerKm = 0.0;
$totalMaintCost = 0.0;


/* =========================================================
   SUMMARY
   ========================================================= */

$tripsQuery = pg_query($conn, "
    SELECT COUNT(*) AS total
    FROM trips
    WHERE departure_time >= date_trunc('month', CURRENT_DATE)
");

if ($tripsQuery) {

    $row = pg_fetch_assoc($tripsQuery);

    $tripsThisPeriod =
        (int) ($row['total'] ?? 0);
}


$costQuery = pg_query($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transport_costs
    WHERE cost_month >= date_trunc('month', CURRENT_DATE)
");

if ($costQuery) {

    $row = pg_fetch_assoc($costQuery);

    $totalCost =
        (float) ($row['total'] ?? 0);
}


$distanceQuery = pg_query($conn, "
    SELECT COALESCE(SUM(r.estimated_distance), 0) AS total_km
    FROM routes r
    JOIN trips t
        ON t.route_id = r.route_id
    WHERE t.departure_time >= date_trunc('month', CURRENT_DATE)
");

if ($distanceQuery) {

    $row = pg_fetch_assoc($distanceQuery);

    $totalKm =
        (float) ($row['total_km'] ?? 0);
}


$avgCostPerKm =
    $totalKm > 0
        ? $totalCost / $totalKm
        : 0;


$maintenanceQuery = pg_query($conn, "
    SELECT COALESCE(SUM(cost), 0) AS total
    FROM maintenance
    WHERE status = 'Completed'
      AND maintenance_date >= date_trunc('month', CURRENT_DATE)
");

if ($maintenanceQuery) {

    $row = pg_fetch_assoc($maintenanceQuery);

    $totalMaintCost =
        (float) ($row['total'] ?? 0);
}


/* =========================================================
   TRIPS PER DAY
   ========================================================= */

$tripsPerDayLabels = [];
$tripsPerDayValues = [];

$tripsPerDayQuery = pg_query($conn, "
    SELECT
        d::date AS day,
        COUNT(t.trip_id) AS total
    FROM generate_series(
        CURRENT_DATE - INTERVAL '13 days',
        CURRENT_DATE,
        INTERVAL '1 day'
    ) d
    LEFT JOIN trips t
        ON t.departure_time::date = d::date
    GROUP BY d::date
    ORDER BY d::date
");

if ($tripsPerDayQuery) {

    while ($row = pg_fetch_assoc($tripsPerDayQuery)) {

        $tripsPerDayLabels[] =
            date(
                'M j',
                strtotime($row['day'])
            );

        $tripsPerDayValues[] =
            (int) $row['total'];
    }
}


/* =========================================================
   TRIPS BY ROUTE
   ========================================================= */

$tripsByRouteLabels = [];
$tripsByRouteValues = [];

$tripsByRouteQuery = pg_query($conn, "
    SELECT
        COALESCE(
            r.route_name,
            'Unknown Route'
        ) AS route_name,
        COUNT(t.trip_id) AS total
    FROM trips t
    LEFT JOIN routes r
        ON r.route_id = t.route_id
    GROUP BY r.route_name
    ORDER BY total DESC
    LIMIT 5
");

if ($tripsByRouteQuery) {

    while ($row = pg_fetch_assoc($tripsByRouteQuery)) {

        $tripsByRouteLabels[] =
            $row['route_name'];

        $tripsByRouteValues[] =
            (int) $row['total'];
    }
}


/* =========================================================
   COSTS BY CATEGORY
   ========================================================= */

$costsByCategoryLabels = [];
$costsByCategoryValues = [];

$costsByCategoryQuery = pg_query($conn, "
    SELECT
        COALESCE(
            cc.category_name,
            c.category,
            'Uncategorized'
        ) AS category_name,
        COALESCE(SUM(c.amount), 0) AS total
    FROM transport_costs c
    LEFT JOIN cost_categories cc
        ON cc.category_id = c.category_id
    GROUP BY
        COALESCE(
            cc.category_name,
            c.category,
            'Uncategorized'
        )
    ORDER BY total DESC
");

if ($costsByCategoryQuery) {

    while ($row = pg_fetch_assoc(
        $costsByCategoryQuery
    )) {

        $costsByCategoryLabels[] =
            $row['category_name'];

        $costsByCategoryValues[] =
            (float) $row['total'];
    }
}


/* =========================================================
   COST TREND
   ========================================================= */

$costsTrendLabels = [];
$costsTrendValues = [];

$costsTrendQuery = pg_query($conn, "
    SELECT
        m::date AS month_start,
        COALESCE(SUM(c.amount), 0) AS total
    FROM generate_series(
        date_trunc('month', CURRENT_DATE) - INTERVAL '5 months',
        date_trunc('month', CURRENT_DATE),
        INTERVAL '1 month'
    ) m
    LEFT JOIN transport_costs c
        ON date_trunc('month', c.cost_month)
            = m::date
    GROUP BY m::date
    ORDER BY m::date
");

if ($costsTrendQuery) {

    while ($row = pg_fetch_assoc(
        $costsTrendQuery
    )) {

        $costsTrendLabels[] =
            date(
                'M',
                strtotime($row['month_start'])
            );

        $costsTrendValues[] =
            (float) $row['total'];
    }
}


$categorySpendLabels =
    $costsByCategoryLabels;

$categorySpendValues =
    $costsByCategoryValues;


/* =========================================================
   METRICS
   ========================================================= */

$metricsLabels = [];
$metricsTargetValues = [];
$metricsActualValues = [];

$metricsChartQuery = pg_query($conn, "
    SELECT
        metric_name,
        target_value,
        actual_value
    FROM metrics
    ORDER BY metric_id
");

if ($metricsChartQuery) {

    while ($row = pg_fetch_assoc(
        $metricsChartQuery
    )) {

        $metricsLabels[] =
            $row['metric_name'];

        $metricsTargetValues[] =
            $row['target_value'] !== null
                ? (float) $row['target_value']
                : 0;

        $metricsActualValues[] =
            $row['actual_value'] !== null
                ? (float) $row['actual_value']
                : 0;
    }
}


/* =========================================================
   CHART DATA
   ========================================================= */

$chartData = json_encode([

    'tripsPerDay' => [
        'labels' => $tripsPerDayLabels,
        'values' => $tripsPerDayValues
    ],

    'tripsByRoute' => [
        'labels' => $tripsByRouteLabels,
        'values' => $tripsByRouteValues
    ],

    'costsByCategory' => [
        'labels' => $costsByCategoryLabels,
        'values' => $costsByCategoryValues
    ],

    'costsTrend' => [
        'labels' => $costsTrendLabels,
        'values' => $costsTrendValues
    ],

    'categorySpend' => [
        'labels' => $categorySpendLabels,
        'values' => $categorySpendValues
    ],

    'metrics' => [
        'labels' => $metricsLabels,
        'target' => $metricsTargetValues,
        'actual' => $metricsActualValues
    ]

]);

?>

<?php if (!defined('CHARTJS_LOADED')): define('CHARTJS_LOADED', true); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<?php endif; ?>


<section
    id="transport"
    class="section space-y-6 dark:text-slate-400"
>


    <!-- =====================================================
         HEADER + SUMMARY
         ===================================================== -->

    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900 dark:text-white">Transport Cost Analysis & Optimization</h1>
                <span class="text-xs font-medium px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 border border-blue-500/20">
                    TCAO Module
                </span>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Trips, costs, categories, metrics
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="modules/export_transport.php?type=summary"
                class="inline-flex items-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
            >
                <i class="ti ti-download"></i>
                Download Full Report
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('tcao', 'trips')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('tcao', 'trips'); }"
             title="View trips">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Trips</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= number_format($tripsThisPeriod) ?></p>
                    <p class="text-xs text-slate-400 mt-1">This period</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <i class="ti ti-route text-blue-500 dark:text-blue-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('tcao', 'costs')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('tcao', 'costs'); }"
             title="View costs">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total Cost</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white">&#8369;<?= number_format($totalCost, 2) ?></p>
                    <p class="text-xs text-slate-400 mt-1">Transport</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <i class="ti ti-currency-peso text-emerald-500 dark:text-emerald-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('tcao', 'metrics')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('tcao', 'metrics'); }"
             title="View metrics">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Avg Cost/Km</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white">&#8369;<?= number_format($avgCostPerKm, 2) ?></p>
                    <p class="text-xs text-slate-400 mt-1">Average</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                    <i class="ti ti-calculator text-amber-500 dark:text-amber-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('tcao', 'categories')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('tcao', 'categories'); }"
             title="View cost categories">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Maintenance Cost</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white">&#8369;<?= number_format($totalMaintCost, 2) ?></p>
                    <p class="text-xs text-slate-400 mt-1">This period</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(168 85 247 / 0.1)">
                    <i class="ti ti-tool text-purple-500 dark:text-purple-400 text-lg"></i>
                </div>
            </div>
        </div>
    </div>


    <!-- =====================================================
         SUBTABS
         ===================================================== -->

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
        <div class="flex justify-center mb-4">
            <?php render_subtabs('tcao',['trips' => 'Trips','costs' => 'Costs', 'categories' => 'Categories', 'metrics' => 'Metrics',
                ], 'metrics');
            ?>
        </div>
    </div>


    <!-- =====================================================
         TRIPS
         ===================================================== -->

    <div
        class="subtab-panel hidden space-y-6"
        data-module="tcao"
        data-subtab="trips"
    >

        <div
            class="tcao-chart-grid grid grid-cols-1 lg:grid-cols-2 gap-5"
        >

            <div class="card p-5 min-w-0">

                <div class="mb-4">

                    <p class="text-sm font-medium">

                        Trips per day

                        <span
                            class="text-xs text-slate-400 font-normal"
                        >
                            (last 14 days)
                        </span>

                    </p>

                </div>

                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="tripsPerDayChart"></canvas>
                </div>

            </div>


            <div class="card p-5 min-w-0">

                <div class="mb-4">

                    <p class="text-sm font-medium">
                        Trips by route
                    </p>

                </div>

                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="tripsByRouteChart"></canvas>
                </div>

            </div>

        </div>


        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">All trips</p>
                <a
                    href="modules/export_transport.php?type=trips&range=all"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <i class="ti ti-file-spreadsheet"></i>
                    Export CSV
                </a>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">

                <table class="w-full text-sm">

                    <thead>

                        <tr
                            class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200"
                        >

                            <th class="py-3 px-4 font-normal">
                                Trip
                            </th>

                            <th class="font-normal">
                                Route
                            </th>

                            <th class="font-normal">
                                Distance
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        class="divide-y divide-slate-100"
                    >

                    <?php

                    $res = pg_query($conn, "
                        SELECT
                            t.trip_id,
                            r.route_name,
                            r.estimated_distance
                        FROM trips t
                        LEFT JOIN routes r
                            ON r.route_id = t.route_id
                        ORDER BY t.trip_id DESC
                    ");

                    ?>


                    <?php if (
                        !$res ||
                        pg_num_rows($res) === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="3"
                                class="text-center text-slate-400 py-6"
                            >
                                No trips yet.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php while (
                            $row = pg_fetch_assoc($res)
                        ): ?>

                            <tr>

                                <td class="py-3 px-4">

                                    <?= manifest_tag(
                                        code_id(
                                            'TRP',
                                            $row['trip_id']
                                        )
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row['route_name'] ?? '—'
                                    ) ?>

                                </td>

                                <td>

                                    <?= $row['estimated_distance'] !== null

                                        ? number_format(
                                            (float) $row[
                                                'estimated_distance'
                                            ],
                                            1
                                        ) . ' km'

                                        : '—'
                                    ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </div>


    <!-- =====================================================
         COSTS
         ===================================================== -->

    <div
        class="subtab-panel hidden space-y-6"
        data-module="tcao"
        data-subtab="costs"
    >

        <div
            class="tcao-chart-grid grid grid-cols-1 lg:grid-cols-2 gap-5"
        >

            <div class="card p-5 min-w-0">

                <div class="mb-4">

                    <p class="text-sm font-medium">

                        Cost trend

                        <span
                            class="text-xs text-slate-400 font-normal"
                        >
                            (last 6 months)
                        </span>

                    </p>

                </div>

                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="costsTrendChart"></canvas>
                </div>

            </div>


            <div class="card p-5 min-w-0">

                <div class="mb-4">

                    <p class="text-sm font-medium">
                        Costs by category
                    </p>

                </div>

                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="costsByCategoryChart"></canvas>
                </div>

            </div>

        </div>


        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Cost entries, this month</p>
                <a
                    href="modules/export_transport.php?type=costs"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <i class="ti ti-file-spreadsheet"></i>
                    Export CSV
                </a>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">

                <table class="w-full text-sm">

                    <thead>

                        <tr
                            class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200"
                        >

                            <th class="py-3 px-4 font-normal">
                                ID
                            </th>

                            <th class="font-normal">
                                Category
                            </th>

                            <th class="font-normal">
                                Vehicle
                            </th>

                            <th class="font-normal">
                                Amount
                            </th>

                            <th class="font-normal">
                                Month
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        class="divide-y divide-slate-100"
                    >

                    <?php

                    $res = pg_query($conn, "
                        SELECT
                            c.cost_id,
                            c.category,
                            cc.category_name,
                            c.amount,
                            c.cost_month,
                            v.plate_number
                        FROM transport_costs c
                        LEFT JOIN cost_categories cc
                            ON cc.category_id = c.category_id
                        LEFT JOIN vehicles v
                            ON v.vehicle_id = c.vehicle_id
                        ORDER BY
                            c.cost_month DESC,
                            c.cost_id DESC
                    ");

                    ?>


                    <?php if (
                        !$res ||
                        pg_num_rows($res) === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="text-center text-slate-400 py-6"
                            >
                                No cost entries yet.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php while (
                            $row = pg_fetch_assoc($res)
                        ): ?>

                            <tr>

                                <td class="py-3 px-4">

                                    <?= manifest_tag(
                                        code_id(
                                            'CST',
                                            $row['cost_id']
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $row['category_name']
                                        ?? $row['category']
                                        ?? '—'
                                    ) ?>

                                </td>


                                <td class="tag text-xs">

                                    <?= htmlspecialchars(
                                        $row['plate_number'] ?? '—'
                                    ) ?>

                                </td>


                                <td>

                                    &#8369;<?= number_format(
                                        (float) (
                                            $row['amount'] ?? 0
                                        ),
                                        2
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $row['cost_month'] ?? '—'
                                    ) ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </div>


    <!-- =====================================================
         CATEGORIES
         ===================================================== -->

    <div
        class="subtab-panel hidden space-y-6"
        data-module="tcao"
        data-subtab="categories"
    >

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <div class="mb-4">
                <p class="text-sm font-medium">Spend distribution by category</p>
            </div>
            <div class="chart-wrap chart-wrap-wide">
                <canvas id="categorySpendChart"></canvas>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Cost categories</p>
                <a
                    href="modules/export_transport.php?type=categories"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <i class="ti ti-file-spreadsheet"></i>
                    Export CSV
                </a>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">

                <table class="w-full text-sm">

                    <thead>

                        <tr
                            class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200"
                        >

                            <th class="py-3 px-4 font-normal">
                                ID
                            </th>

                            <th class="font-normal">
                                Name
                            </th>

                            <th class="font-normal">
                                Total spent
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        class="divide-y divide-slate-100"
                    >

                    <?php

                    $res = pg_query($conn, "
                        SELECT
                            cc.category_id,
                            cc.category_name,
                            COALESCE(
                                SUM(c.amount),
                                0
                            ) AS total
                        FROM cost_categories cc
                        LEFT JOIN transport_costs c
                            ON c.category_id =
                               cc.category_id
                        GROUP BY
                            cc.category_id,
                            cc.category_name
                        ORDER BY cc.category_name
                    ");

                    ?>


                    <?php if (
                        !$res ||
                        pg_num_rows($res) === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="3"
                                class="text-center text-slate-400 py-6"
                            >
                                No categories yet.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php while (
                            $row = pg_fetch_assoc($res)
                        ): ?>

                            <tr>

                                <td class="py-3 px-4">

                                    <?= (int) $row[
                                        'category_id'
                                    ] ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $row[
                                            'category_name'
                                        ] ?? '—'
                                    ) ?>

                                </td>


                                <td>

                                    &#8369;<?= number_format(
                                        (float) (
                                            $row['total'] ?? 0
                                        ),
                                        2
                                    ) ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </div>


    <!-- =====================================================
         METRICS
         ===================================================== -->

    <div
        class="subtab-panel space-y-6"
        data-module="tcao"
        data-subtab="metrics"
    >

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <div class="mb-4">
                <p class="text-sm font-medium">Target vs Actual by metric</p>
            </div>
            <div class="chart-wrap chart-wrap-wide">

                <canvas id="metricsChart"></canvas>

            </div>

        </div>


        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
            <form
                method="get"
                class="flex flex-wrap items-center gap-3 mb-4"
            >

                <input
                    type="hidden"
                    name="section"
                    value="transport"
                >


                <input
                    type="text"
                    name="metric_q"
                    value="<?= htmlspecialchars(
                        $_GET['metric_q'] ?? ''
                    ) ?>"
                    placeholder="Metric name"
                    class="border border-slate-200 rounded-lg px-3 py-2 text-sm flex-1"
                >


                <a
                    href="modules/export_transport.php?type=metrics"
                    class="tcao-action-btn inline-flex items-center justify-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap"
                >

                    <i class="ti ti-file-spreadsheet"></i>

                    Export CSV

                </a>


                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap"
                >
                    <i class="ti ti-search"></i>
                    Search
                </button>
            </form>

            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">

                <table class="w-full text-sm">

                    <thead>

                        <tr
                            class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200"
                        >

                            <th class="py-3 px-4 font-normal">
                                Metric ID
                            </th>

                            <th class="font-normal">
                                Name
                            </th>

                            <th class="font-normal">
                                Target Value
                            </th>

                            <th class="font-normal">
                                Actual Value
                            </th>

                            <th class="font-normal">
                                Variance
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        class="divide-y divide-slate-100"
                    >

                    <?php

                    $q = trim(
                        $_GET['metric_q'] ?? ''
                    );

                    if ($q !== '') {

                        $like = '%' . $q . '%';

                        $res = pg_query_params(
                            $conn,
                            "
                            SELECT
                                metric_id,
                                metric_name,
                                target_value,
                                actual_value
                            FROM metrics
                            WHERE metric_name ILIKE $1
                            ORDER BY metric_id
                            ",
                            [$like]
                        );

                    } else {

                        $res = pg_query(
                            $conn,
                            "
                            SELECT
                                metric_id,
                                metric_name,
                                target_value,
                                actual_value
                            FROM metrics
                            ORDER BY metric_id
                            "
                        );
                    }

                    ?>


                    <?php if (
                        !$res ||
                        pg_num_rows($res) === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="text-center text-slate-400 py-6"
                            >
                                No matching results
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php while (
                            $row = pg_fetch_assoc($res)
                        ): ?>

                            <?php

                            $variance = (
                                $row['actual_value'] !== null &&
                                $row['target_value'] !== null
                            )
                                ? (
                                    (float) $row[
                                        'actual_value'
                                    ] -
                                    (float) $row[
                                        'target_value'
                                    ]
                                )
                                : null;


                            $varClass =
                                $variance === null
                                    ? 'text-slate-400'
                                    : (
                                        $variance >= 0
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    );

                            ?>


                            <tr>

                                <td class="py-3 px-4">

                                    <?= manifest_tag(
                                        code_id(
                                            'MET',
                                            $row['metric_id']
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $row[
                                            'metric_name'
                                        ] ?? '—'
                                    ) ?>

                                </td>


                                <td>

                                    <?= $row[
                                        'target_value'
                                    ] !== null

                                        ? number_format(
                                            (float) $row[
                                                'target_value'
                                            ],
                                            2
                                        )

                                        : '—'
                                    ?>

                                </td>


                                <td>

                                    <?= $row[
                                        'actual_value'
                                    ] !== null

                                        ? number_format(
                                            (float) $row[
                                                'actual_value'
                                            ],
                                            2
                                        )

                                        : '—'
                                    ?>

                                </td>


                                <td
                                    class="<?= $varClass ?> font-medium"
                                >

                                    <?= $variance !== null

                                        ? number_format(
                                            $variance,
                                            2
                                        )

                                        : '—'
                                    ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </div>

</section>


<!-- =========================================================
     STYLE
     ========================================================= -->

<style>
    .chart-wrap {
        position: relative;
        width: 100%;
        height: 220px;
    }

    .chart-wrap-wide {
        height: 260px;
    }

    .chart-wrap-compact {
        height: 180px;
    }

    .tcao-metric-form {
        width: 100%;
    }

    .subtab-panel table {
        min-width: 700px;
    }

    @media (max-width: 640px) {
        .chart-wrap {
            height: 200px;
        }

        .chart-wrap-wide {
            height: 230px;
        }

        .chart-wrap-compact {
            height: 200px;
        }
    }
</style>


<!-- =========================================================
     CHARTS
     ========================================================= -->

<script>

(function () {

    const chartData = <?= $chartData ?>;


    const palette = {

        blue: '#2563eb',

        indigo: '#818cf8',

        orange: '#f59e0b',

        red: '#ef4444',

        teal: '#14b8a6',

        purple: '#8b5cf6',

        grid: 'rgba(148, 163, 184, 0.15)',

        text: '#94a3b8'

    };


    Chart.defaults.color =
        palette.text;


    Chart.defaults.font.family =
        getComputedStyle(
            document.body
        ).fontFamily || 'inherit';


    const baseGrid = {
        color: palette.grid
    };


    function makeChart(id, config) {

        const el =
            document.getElementById(id);

        if (!el) {
            return;
        }

        new Chart(
            el.getContext('2d'),
            config
        );
    }


    /* =====================================================
       TRIPS PER DAY
       ===================================================== */

    makeChart(
        'tripsPerDayChart',
        {

            type: 'line',

            data: {

                labels:
                    chartData.tripsPerDay.labels,

                datasets: [{

                    label: 'Trips',

                    data:
                        chartData.tripsPerDay.values,

                    borderColor:
                        palette.blue,

                    backgroundColor:
                        'rgba(37, 99, 235, 0.15)',

                    tension: 0.35,

                    fill: true,

                    pointRadius: 2

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {
                        display: false
                    }

                },


                scales: {

                    x: {

                        grid: {
                            display: false
                        }

                    },


                    y: {

                        grid: baseGrid,

                        beginAtZero: true

                    }

                }

            }

        }
    );


    /* =====================================================
       TRIPS BY ROUTE
       ===================================================== */

    makeChart(
        'tripsByRouteChart',
        {

            type: 'doughnut',

            data: {

                labels:
                    chartData.tripsByRoute.labels,

                datasets: [{

                    data:
                        chartData.tripsByRoute.values,

                    backgroundColor: [

                        palette.blue,

                        palette.indigo,

                        palette.orange,

                        palette.teal,

                        palette.purple

                    ],

                    borderWidth: 0

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {

                            boxWidth: 10,

                            font: {
                                size: 10
                            }

                        }

                    }

                }

            }

        }
    );


    /* =====================================================
       COST TREND
       ===================================================== */

    makeChart(
        'costsTrendChart',
        {

            type: 'line',

            data: {

                labels:
                    chartData.costsTrend.labels,

                datasets: [{

                    label: 'Total Cost (₱)',

                    data:
                        chartData.costsTrend.values,

                    borderColor:
                        palette.orange,

                    backgroundColor:
                        'rgba(245, 158, 11, 0.15)',

                    tension: 0.35,

                    fill: true,

                    pointRadius: 2

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {
                        display: false
                    }

                },


                scales: {

                    x: {

                        grid: {
                            display: false
                        }

                    },


                    y: {

                        grid: baseGrid,

                        beginAtZero: true

                    }

                }

            }

        }
    );


    /* =====================================================
       COSTS BY CATEGORY
       ===================================================== */

    makeChart(
        'costsByCategoryChart',
        {

            type: 'bar',

            data: {

                labels:
                    chartData.costsByCategory.labels,

                datasets: [{

                    label: 'Cost (₱)',

                    data:
                        chartData.costsByCategory.values,

                    backgroundColor:
                        palette.blue,

                    borderRadius: 4

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {
                        display: false
                    }

                },


                scales: {

                    x: {

                        grid: {
                            display: false
                        }

                    },


                    y: {

                        grid: baseGrid,

                        beginAtZero: true

                    }

                }

            }

        }
    );


    /* =====================================================
       CATEGORY SPEND
       ===================================================== */

    makeChart(
        'categorySpendChart',
        {

            type: 'doughnut',

            data: {

                labels:
                    chartData.categorySpend.labels,

                datasets: [{

                    data:
                        chartData.categorySpend.values,

                    backgroundColor: [

                        palette.blue,

                        palette.indigo,

                        palette.orange,

                        palette.teal,

                        palette.purple

                    ],

                    borderWidth: 0

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {

                        position: 'right',

                        labels: {

                            boxWidth: 10,

                            font: {
                                size: 11
                            }

                        }

                    }

                }

            }

        }
    );


    /* =====================================================
       METRICS
       ===================================================== */

    makeChart(
        'metricsChart',
        {

            type: 'bar',

            data: {

                labels:
                    chartData.metrics.labels,

                datasets: [

                    {

                        label: 'Target',

                        data:
                            chartData.metrics.target,

                        backgroundColor:
                            palette.indigo,

                        borderRadius: 4

                    },


                    {

                        label: 'Actual',

                        data:
                            chartData.metrics.actual,

                        backgroundColor:
                            palette.blue,

                        borderRadius: 4

                    }

                ]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {
                        position: 'bottom'
                    }

                },


                scales: {

                    x: {

                        grid: {
                            display: false
                        }

                    },


                    y: {

                        grid: baseGrid,

                        beginAtZero: true

                    }

                }

            }

        }
    );

})();

</script>