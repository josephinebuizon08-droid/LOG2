<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/auth/api_authenticate.php';
require_once __DIR__ . '/../modules/maintenance_helper.php'; 
try {

    /*
     * Authenticate the currently logged-in driver.
     * We do NOT trust driver_id coming from Flutter.
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
     * Find the vehicle currently assigned to this driver.
     *
     * Driver -> vehicles.assigned_driver_id
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

    $vehicle = pg_fetch_assoc($vehicleResult);

    if (!$vehicle) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'No vehicle is currently assigned to this driver.'
        ]);

        exit;
    }

    $vehicleId = (int) $vehicle['vehicle_id'];
    $plateNumber = $vehicle['plate_number'];
    $vehicleType = $vehicle['vehicle_type'];

    /*
     * Required fields from Flutter.
     */
    $dateTime = trim($_POST['date_time'] ?? '');
    $fuelType = trim($_POST['fuel_type'] ?? '');
    $liters = $_POST['liters'] ?? null;
    $pricePerLiter = $_POST['price_per_liter'] ?? null;
    $odometerReading = $_POST['odometer_reading'] ?? null;
    $fuelStation = trim($_POST['fuel_station'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    /*
     * Validate required fields.
     */
    if (
        $dateTime === '' ||
        $fuelType === '' ||
        $liters === null ||
        $pricePerLiter === null ||
        $odometerReading === null ||
        $fuelStation === '' ||
        $paymentMethod === ''
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Please complete all required fuel log fields.'
        ]);

        exit;
    }

    /*
     * Validate numeric fields.
     */
    if (
        !is_numeric($liters) ||
        !is_numeric($pricePerLiter) ||
        !is_numeric($odometerReading)
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' =>
                'Liters, price per liter, and odometer must be numeric.'
        ]);

        exit;
    }

    $liters = (float) $liters;
    $pricePerLiter = (float) $pricePerLiter;
    $odometerReading = (float) $odometerReading;

    /*
     * Validate positive values.
     */
    if ($liters <= 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Liters must be greater than zero.'
        ]);

        exit;
    }

    if ($pricePerLiter < 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Price per liter cannot be negative.'
        ]);

        exit;
    }

    if ($odometerReading < 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Odometer reading cannot be negative.'
        ]);

        exit;
    }

    /*
     * Calculate total cost on the server.
     */
    $totalCost = $liters * $pricePerLiter;

    /*
     * Convert Flutter ISO date/time to PostgreSQL date/timestamp.
     */
    try {

        $date = new DateTime($dateTime);

        $logDate = $date->format('Y-m-d');

        $createdAt = $date->format('Y-m-d H:i:s');

    } catch (Throwable $e) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid date/time.'
        ]);

        exit;
    }

    /*
     * Receipt upload.
     */
    $receiptPath = null;

    if (
        isset($_FILES['receipt']) &&
        $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Receipt upload failed.'
            ]);

            exit;
        }

        /*
         * Maximum receipt size: 5 MB.
         */
        $maxFileSize = 5 * 1024 * 1024;

        if ($_FILES['receipt']['size'] > $maxFileSize) {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' =>
                    'Receipt image must not exceed 5 MB.'
            ]);

            exit;
        }

        $tmpName = $_FILES['receipt']['tmp_name'];

        /*
         * Detect actual MIME type.
         */
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new Exception(
                'Could not validate receipt file.'
            );
        }

        $mimeType = finfo_file(
            $finfo,
            $tmpName
        );

        finfo_close($finfo);

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedTypes[$mimeType])) {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' =>
                    'Receipt must be a JPG, PNG, or WEBP image.'
            ]);

            exit;
        }

        /*
         * Create receipt directory if it doesn't exist.
         */
        $uploadDirectory =
            __DIR__ . '/../uploads/fuel_receipts';

        if (!is_dir($uploadDirectory)) {

            if (!mkdir($uploadDirectory, 0755, true)) {

                throw new Exception(
                    'Could not create receipt upload directory.'
                );
            }
        }

        /*
         * Generate unique filename.
         */
        $extension = $allowedTypes[$mimeType];

        $fileName =
            'fuel_' .
            $driverId .
            '_' .
            time() .
            '_' .
            bin2hex(random_bytes(4)) .
            '.' .
            $extension;

        $destination =
            $uploadDirectory . '/' . $fileName;

        if (
            !move_uploaded_file(
                $tmpName,
                $destination
            )
        ) {

            throw new Exception(
                'Could not save uploaded receipt.'
            );
        }

        /*
         * Relative path saved in database.
         */
        $receiptPath =
            'uploads/fuel_receipts/' . $fileName;
    }

    /*
     * Insert fuel log.
     *
     * IMPORTANT:
     *
     * vehicle_id  = vehicle assigned to authenticated driver
     * recorded_by = authenticated driver
     *
     * Nothing here comes from a manually selected vehicle.
     */
    $insertSql = "
        INSERT INTO fuel_logs (
            vehicle_id,
            log_date,
            liters,
            cost,
            odometer_km,
            validation,
            recorded_by,
            created_at,
            fuel_type,
            fuel_station,
            price_per_liter,
            payment_method,
            receipt_path,
            notes
        )
        VALUES (
            $1,
            $2,
            $3,
            $4,
            $5,
            'Pending',
            $6,
            $7,
            $8,
            $9,
            $10,
            $11,
            $12,
            $13
        )
        RETURNING
            fuel_log_id,
            vehicle_id,
            log_date,
            liters,
            cost,
            odometer_km,
            validation,
            recorded_by,
            created_at,
            fuel_type,
            fuel_station,
            price_per_liter,
            payment_method,
            receipt_path,
            notes
    ";

    $insertResult = pg_query_params(
        $conn,
        $insertSql,
        [
            $vehicleId,
            $logDate,
            $liters,
            $totalCost,
            $odometerReading,
            $driverId,
            $createdAt,
            $fuelType,
            $fuelStation,
            $pricePerLiter,
            $paymentMethod,
            $receiptPath,
            $notes
        ]
    );

    if (!$insertResult) {
        throw new Exception(pg_last_error($conn));
    }

    $fuelLog = pg_fetch_assoc($insertResult);

    /*
     * Update the vehicle's odometer with this new reading, then check
     * whether it has crossed into its maintenance warning window.
     * Only moves the odometer forward — never lets a stray/incorrect
     * lower entry make it regress.
     */
    pg_query_params(
        $conn,
        "UPDATE vehicles
         SET odometer = $1
         WHERE vehicle_id = $2 AND odometer < $1",
        [$odometerReading, $vehicleId]
    );

    ftms_check_maintenance_due($conn, $vehicleId);

    /*
     * Return the created fuel log together with
     * the assigned vehicle information.
     */
    echo json_encode([
        'success' => true,
        'message' => 'Fuel log submitted successfully.',

        'vehicle' => [
            'vehicle_id' => $vehicleId,
            'plate_number' => $plateNumber,
            'vehicle_type' => $vehicleType,
        ],

        'fuel_log' => [
            'fuel_log_id' =>
                (int) $fuelLog['fuel_log_id'],

            'vehicle_id' =>
                (int) $fuelLog['vehicle_id'],

            'plate_number' =>
                $plateNumber,

            'vehicle_type' =>
                $vehicleType,

            'log_date' =>
                $fuelLog['log_date'],

            'liters' =>
                (float) $fuelLog['liters'],

            'cost' =>
                (float) $fuelLog['cost'],

            'odometer_km' =>
                (float) $fuelLog['odometer_km'],

            'validation' =>
                $fuelLog['validation'],

            'recorded_by' =>
                (int) $fuelLog['recorded_by'],

            'created_at' =>
                $fuelLog['created_at'],

            'fuel_type' =>
                $fuelLog['fuel_type'],

            'fuel_station' =>
                $fuelLog['fuel_station'],

            'price_per_liter' =>
                (float) $fuelLog['price_per_liter'],

            'payment_method' =>
                $fuelLog['payment_method'],

            'receipt_path' =>
                $fuelLog['receipt_path'],

            'notes' =>
                $fuelLog['notes'],
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to create fuel log.',
        'error' => $e->getMessage()
    ]);
}