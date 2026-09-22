<?php

require_once __DIR__ . '/../config/ftms_db.php';

$type  = $_GET['type']  ?? 'logs';
$range = $_GET['range'] ?? 'month'; // 'month' or 'all'
$monthFilter = "f.log_date >= date_trunc('month', CURRENT_DATE)";

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

    case 'summary':
        // Per-driver totals for the current month, plus their Fleet Card info —
        // mirrors the header block shown in fuel.php's per-driver view modal.
        stream_csv_headers('fuel_summary_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');

        $res = pg_query($conn, "
            SELECT
                d.driver_id,
                d.first_name,
                d.last_name,
                fc.provider,
                fc.card_number,
                fc.monthly_limit,
                COALESCE(SUM(f.liters), 0) AS total_liters,
                COALESCE(SUM(f.cost), 0)   AS total_cost
            FROM drivers d
            LEFT JOIN fleet_cards fc ON fc.driver_id = d.driver_id
            LEFT JOIN vehicles v ON v.assigned_driver_id = d.driver_id
            LEFT JOIN fuel_logs f
                   ON f.vehicle_id = v.vehicle_id
                  AND $monthFilter
                  AND COALESCE(f.validation, '') <> 'Flagged'
            WHERE LOWER(d.status) = 'active'
            GROUP BY d.driver_id, d.first_name, d.last_name, fc.provider, fc.card_number, fc.monthly_limit
            ORDER BY d.last_name, d.first_name
        ");
        write_rows($out, ['Driver', 'Card Provider', 'Card Number', 'Monthly Limit (PHP)', 'Liters (this month)', 'Total Cost (PHP)'], $res, function ($row) {
            return [
                trim($row['first_name'] . ' ' . $row['last_name']),
                $row['provider'] ?? '—',
                $row['card_number'] ?? '—',
                $row['monthly_limit'] !== null ? number_format((float) $row['monthly_limit'], 2) : '—',
                number_format((float) $row['total_liters'], 2),
                number_format((float) $row['total_cost'], 2),
            ];
        });
        fclose($out);
        break;

    case 'logs':
    default:
        stream_csv_headers('fuel_logs_report_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        $where = $range === 'all' ? '1=1' : $monthFilter;
        $res = pg_query($conn, "
            SELECT
                f.fuel_log_id, f.log_date, v.plate_number,
                d.first_name, d.last_name,
                f.fuel_type, f.fuel_station, f.liters, f.price_per_liter, f.cost,
                f.odometer_km, f.payment_method, f.validation, f.notes
            FROM fuel_logs f
            LEFT JOIN vehicles v ON v.vehicle_id = f.vehicle_id
            LEFT JOIN drivers d ON d.driver_id = v.assigned_driver_id
            WHERE $where
            ORDER BY f.log_date DESC, f.fuel_log_id DESC
        ");
        write_rows($out, [
            'Log ID', 'Date', 'Vehicle', 'Driver', 'Fuel Type', 'Station',
            'Liters', 'Price/Liter (PHP)', 'Total Cost (PHP)', 'Odometer (km)',
            'Payment Method', 'Validation', 'Notes',
        ], $res, function ($row) {
            return [
                'FUEL-' . $row['fuel_log_id'],
                $row['log_date'],
                $row['plate_number'] ?? '—',
                $row['first_name'] ? trim($row['first_name'] . ' ' . $row['last_name']) : '—',
                $row['fuel_type'] ?? '—',
                $row['fuel_station'] ?? '—',
                number_format((float) $row['liters'], 2),
                $row['price_per_liter'] !== null ? number_format((float) $row['price_per_liter'], 2) : '—',
                number_format((float) $row['cost'], 2),
                $row['odometer_km'] !== null ? number_format((float) $row['odometer_km'], 1) : '—',
                $row['payment_method'] ?? '—',
                $row['validation'] ?? '—',
                $row['notes'] ?? '',
            ];
        });
        fclose($out);
        break;
}