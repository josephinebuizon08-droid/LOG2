<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/auth/api_authenticate.php';

try {

    /*
     * Authenticate the currently logged-in driver.
     *
     * We do NOT trust driver_id from Flutter.
     */
    $auth = api_authenticate_driver($conn);

    if (!$auth || empty($auth['driver_id'])) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Driver authentication failed.'
        ]);

        exit;
    }

    $driverId = (int) $auth['driver_id'];

    /*
     * ---------------------------------------------------------
     * GET CURRENT ASSIGNED VEHICLE
     * ---------------------------------------------------------
     *
     * The vehicle comes directly from the database:
     *
     * vehicles.assigned_driver_id = authenticated driver_id
     */
    $vehicleSql = "
        SELECT
            vehicle_id,
            plate_number,
            vehicle_type
        FROM vehicles
        WHERE assigned_driver_id = $1
        LIMIT 1
    ";

    $vehicleResult = pg_query_params(
        $conn,
        $vehicleSql,
        [$driverId]
    );

    if (!$vehicleResult) {
        throw new Exception(pg_last_error($conn));
    }

    $vehicleRow = pg_fetch_assoc($vehicleResult);

    $assignedVehicle = null;

    if ($vehicleRow) {
        $assignedVehicle = [
            'vehicle_id' => (int) $vehicleRow['vehicle_id'],
            'plate_number' => $vehicleRow['plate_number'],
            'vehicle_type' => $vehicleRow['vehicle_type'],
        ];
    }

    /*
     * ---------------------------------------------------------
     * GET FUEL LOGS FOR AUTHENTICATED DRIVER
     * ---------------------------------------------------------
     */
    $sql = "
        SELECT
            f.fuel_log_id,
            f.vehicle_id,
            f.log_date,
            f.liters,
            f.cost,
            f.odometer_km,
            f.validation,
            f.recorded_by,
            f.created_at,
            f.fuel_type,
            f.fuel_station,
            f.price_per_liter,
            f.payment_method,
            f.receipt_path,
            f.notes,

            v.plate_number,
            v.vehicle_type,
            v.assigned_driver_id

        FROM fuel_logs f

        LEFT JOIN vehicles v
            ON v.vehicle_id = f.vehicle_id

        WHERE f.recorded_by = $1

        ORDER BY
            f.created_at DESC,
            f.fuel_log_id DESC
    ";

    $result = pg_query_params(
        $conn,
        $sql,
        [$driverId]
    );

    if (!$result) {
        throw new Exception(pg_last_error($conn));
    }

    $fuelLogs = [];

    while ($row = pg_fetch_assoc($result)) {

        $fuelLogs[] = [
            'fuel_log_id' =>
                (int) $row['fuel_log_id'],

            'vehicle_id' =>
                $row['vehicle_id'] !== null
                    ? (int) $row['vehicle_id']
                    : null,

            'plate_number' =>
                $row['plate_number'],

            'log_date' =>
                $row['log_date'],

            'liters' =>
                $row['liters'] !== null
                    ? (float) $row['liters']
                    : 0,

            'cost' =>
                $row['cost'] !== null
                    ? (float) $row['cost']
                    : 0,

            'odometer_km' =>
                $row['odometer_km'] !== null
                    ? (float) $row['odometer_km']
                    : 0,

            'validation' =>
                $row['validation'],

            'recorded_by' =>
                $row['recorded_by'] !== null
                    ? (int) $row['recorded_by']
                    : null,

            'created_at' =>
                $row['created_at'],

            'fuel_type' =>
                $row['fuel_type'],

            'fuel_station' =>
                $row['fuel_station'],

            'price_per_liter' =>
                $row['price_per_liter'] !== null
                    ? (float) $row['price_per_liter']
                    : 0,

            'payment_method' =>
                $row['payment_method'],

            'receipt_path' =>
                $row['receipt_path'],

            'notes' =>
                $row['notes'],

            'vehicle_type' =>
                $row['vehicle_type'],
        ];
    }

    /*
     * ---------------------------------------------------------
     * FINAL RESPONSE
     * ---------------------------------------------------------
     *
     * Returns BOTH:
     *
     * 1. Current assigned vehicle
     * 2. Driver's fuel logs
     */
    echo json_encode([
        'success' => true,

        'vehicle' => $assignedVehicle,

        'fuel_logs' => $fuelLogs
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to load fuel logs.',
        'error' => $e->getMessage()
    ]);
}