<?php

require_once __DIR__ . '/../config/ftms_db.php';

    $type  = $_GET['type']  ?? 'costs';
    $range = $_GET['range'] ?? 'month'; // 'month' or 'all'
    $monthFilter = "cost_month >= date_trunc('month', CURRENT_DATE)";
    $tripFilter  = "departure_time >= date_trunc('month', CURRENT_DATE)";
    $maintFilter = "maintenance_date >= date_trunc('month', CURRENT_DATE)";

    function stream_csv_headers(string $filename): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // BOM so Excel renders UTF-8 (₱ sign etc.) correctly instead of mangling it
        echo "\xEF\xBB\xBF";
    }

    function write_rows($out, array $header, $pgResult, callable $rowMapper): void {
        fputcsv($out, $header);
        while ($row = pg_fetch_assoc($pgResult)) {
            fputcsv($out, $rowMapper($row));
        }
}

switch ($type) {

    case 'trips':
        stream_csv_headers('trips_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $where = $range === 'all' ? '1=1' : $tripFilter;
        $res = pg_query($conn, "
            SELECT t.trip_id, r.route_name, r.estimated_distance, t.departure_time
            FROM trips t
            LEFT JOIN routes r ON r.route_id = t.route_id
            WHERE $where
            ORDER BY t.trip_id DESC
        ");
        write_rows($out, ['Trip ID', 'Route', 'Distance (km)', 'Departure Time'], $res, function ($row) {
            return [
                'TRP-' . $row['trip_id'],
                $row['route_name'] ?? '—',
                $row['estimated_distance'] !== null ? number_format((float) $row['estimated_distance'], 1) : '—',
                $row['departure_time'],
            ];
        });
        fclose($out);
        break;

    case 'categories':
        stream_csv_headers('cost_categories_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $res = pg_query($conn, "
            SELECT cc.category_id, cc.category_name, COALESCE(SUM(c.amount),0) AS total
            FROM cost_categories cc
            LEFT JOIN transport_costs c ON c.category_id = cc.category_id
            GROUP BY cc.category_id, cc.category_name
            ORDER BY cc.category_name
        ");
        write_rows($out, ['Category ID', 'Name', 'Total Spent (PHP)'], $res, function ($row) {
            return [$row['category_id'], $row['category_name'], number_format((float) $row['total'], 2)];
        });
        fclose($out);
        break;

    case 'metrics':
        stream_csv_headers('metrics_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $res = pg_query($conn, "SELECT metric_id, metric_name, target_value, actual_value FROM metrics ORDER BY metric_id");
        write_rows($out, ['Metric ID', 'Name', 'Target', 'Actual', 'Variance'], $res, function ($row) {
            $variance = ($row['actual_value'] !== null && $row['target_value'] !== null)
                ? ((float) $row['actual_value'] - (float) $row['target_value']) : null;
            return [
                'MET-' . $row['metric_id'],
                $row['metric_name'],
                $row['target_value'] !== null ? number_format((float) $row['target_value'], 2) : '—',
                $row['actual_value'] !== null ? number_format((float) $row['actual_value'], 2) : '—',
                $variance !== null ? number_format($variance, 2) : '—',
            ];
        });
        fclose($out);
        break;

    case 'maintenance':
        stream_csv_headers('maintenance_cost_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $where = $range === 'all' ? '1=1' : $maintFilter;
        $res = pg_query($conn, "
            SELECT m.maintenance_id, m.maintenance_type, m.maintenance_date, m.cost, m.status, v.plate_number
            FROM maintenance m
            LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
            WHERE $where
            ORDER BY m.maintenance_date DESC, m.maintenance_id DESC
        ");
        write_rows($out, ['Maintenance ID', 'Vehicle', 'Type', 'Date', 'Cost (PHP)', 'Status'], $res, function ($row) {
            return [
                'MNT-' . $row['maintenance_id'],
                $row['plate_number'] ?? '—',
                $row['maintenance_type'] ?? '—',
                $row['maintenance_date'] ?? '—',
                number_format((float) $row['cost'], 2),
                $row['status'] ?? '—',
            ];
        });
        fclose($out);
        break;

    case 'summary':
        // One CSV with all sections stacked — opens as a single Excel file with clear section breaks
        stream_csv_headers('transport_cost_summary_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');

        fputcsv($out, ['TRANSPORT COST ANALYSIS & OPTIMIZATION — SUMMARY REPORT']);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);

        fputcsv($out, ['-- COSTS (this month) --']);
        $res = pg_query($conn, "
            SELECT c.cost_id, cc.category_name, c.amount, c.cost_month, v.plate_number
            FROM transport_costs c
            LEFT JOIN cost_categories cc ON cc.category_id = c.category_id
            LEFT JOIN vehicles v ON v.vehicle_id = c.vehicle_id
            WHERE $monthFilter
            ORDER BY c.cost_month DESC, c.cost_id DESC
        ");
        write_rows($out, ['ID', 'Category', 'Vehicle', 'Amount', 'Month'], $res, function ($row) {
            return ['CST-' . $row['cost_id'], $row['category_name'] ?? '—', $row['plate_number'] ?? '—',
                     number_format((float) $row['amount'], 2), $row['cost_month']];
        });
        fputcsv($out, []);

        fputcsv($out, ['-- MAINTENANCE COST (this month, Completed) --']);
        $res = pg_query($conn, "
            SELECT m.maintenance_id, m.maintenance_type, m.maintenance_date, m.cost, v.plate_number
            FROM maintenance m
            LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
            WHERE m.status = 'Completed' AND $maintFilter
            ORDER BY m.maintenance_date DESC, m.maintenance_id DESC
        ");
        write_rows($out, ['ID', 'Vehicle', 'Type', 'Date', 'Cost'], $res, function ($row) {
            return ['MNT-' . $row['maintenance_id'], $row['plate_number'] ?? '—', $row['maintenance_type'] ?? '—',
                     $row['maintenance_date'] ?? '—', number_format((float) $row['cost'], 2)];
        });
        fputcsv($out, []);

        fputcsv($out, ['-- CATEGORIES --']);
        $res = pg_query($conn, "
            SELECT cc.category_id, cc.category_name, COALESCE(SUM(c.amount),0) AS total
            FROM cost_categories cc
            LEFT JOIN transport_costs c ON c.category_id = cc.category_id
            GROUP BY cc.category_id, cc.category_name ORDER BY cc.category_name
        ");
        write_rows($out, ['ID', 'Name', 'Total Spent'], $res, function ($row) {
            return [$row['category_id'], $row['category_name'], number_format((float) $row['total'], 2)];
        });
        fputcsv($out, []);

        fputcsv($out, ['-- METRICS --']);
        $res = pg_query($conn, "SELECT metric_id, metric_name, target_value, actual_value FROM metrics ORDER BY metric_id");
        write_rows($out, ['ID', 'Name', 'Target', 'Actual', 'Variance'], $res, function ($row) {
            $variance = ($row['actual_value'] !== null && $row['target_value'] !== null)
                ? ((float) $row['actual_value'] - (float) $row['target_value']) : null;
            return ['MET-' . $row['metric_id'], $row['metric_name'],
                     $row['target_value'] !== null ? number_format((float) $row['target_value'], 2) : '—',
                     $row['actual_value'] !== null ? number_format((float) $row['actual_value'], 2) : '—',
                     $variance !== null ? number_format($variance, 2) : '—'];
        });
        fclose($out);
        break;

    case 'costs':
    default:
        stream_csv_headers('costs_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $where = $range === 'all' ? '1=1' : $monthFilter;
        $res = pg_query($conn, "
            SELECT c.cost_id, cc.category_name, c.amount, c.cost_month, v.plate_number
            FROM transport_costs c
            LEFT JOIN cost_categories cc ON cc.category_id = c.category_id
            LEFT JOIN vehicles v ON v.vehicle_id = c.vehicle_id
            WHERE $where
            ORDER BY c.cost_month DESC, c.cost_id DESC
        ");
        write_rows($out, ['Cost ID', 'Category', 'Vehicle', 'Amount (PHP)', 'Month'], $res, function ($row) {
            return [
                'CST-' . $row['cost_id'],
                $row['category_name'] ?? '—',
                $row['plate_number'] ?? '—',
                number_format((float) $row['amount'], 2),
                $row['cost_month'],
            ];
        });
        fclose($out);
        break;
}