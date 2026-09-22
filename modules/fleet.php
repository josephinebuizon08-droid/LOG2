<?php
    require_once __DIR__ . '/../config/ftms_db.php';
    require_once __DIR__ .'/UI_helpers.php';
    require_once __DIR__ . '/../auth/rbac.php';
    require_once __DIR__ . '/maintenance_helper.php';

    // ================= DELETE MAINTENANCE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_maintenance'])) {
    require_admin();

    $delete_maintenance_id = (int) ($_POST['delete_maintenance_id'] ?? 0);

    if ($delete_maintenance_id > 0) {

        $result = pg_query_params(
            $conn,
            "DELETE FROM maintenance WHERE maintenance_id = $1",
            [$delete_maintenance_id]
        );

        if (!$result) {
            $maintenance_message = 'Unable to delete maintenance record.';
        }
    }
}

// ================= EDIT MAINTENANCE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_maintenance'])) {

    $edit_maintenance_id = (int) ($_POST['edit_maintenance_id'] ?? 0);

    $edit_vehicle_id = $_POST['edit_maintenance_vehicle_id'] !== ''
        ? (int) $_POST['edit_maintenance_vehicle_id']
        : null;

    $edit_type = trim($_POST['edit_maintenance_type'] ?? '');
    $edit_date = $_POST['edit_maintenance_date'] ?? '';

    $edit_cost = $_POST['edit_maintenance_cost'] !== ''
        ? (float) $_POST['edit_maintenance_cost']
        : 0;

    $edit_status = $_POST['edit_maintenance_status'] ?? 'Scheduled';

    if (
        $edit_maintenance_id <= 0 ||
        $edit_vehicle_id === null ||
        $edit_type === '' ||
        $edit_date === ''
    ) {

        $maintenance_message = 'Please complete all required maintenance information.';

    } else {

        $sql = "
            UPDATE maintenance
            SET
                vehicle_id = $1,
                maintenance_type = $2,
                maintenance_date = $3,
                cost = $4,
                status = $5
            WHERE maintenance_id = $6
        ";

        $result = pg_query_params($conn, $sql, [
            $edit_vehicle_id,
            $edit_type,
            $edit_date,
            $edit_cost,
            $edit_status,
            $edit_maintenance_id
        ]);

        if ($result) {

            if (strcasecmp($edit_status, 'Completed') === 0) {
                ftms_reset_maintenance_baseline($conn, $edit_vehicle_id);
            }

            $maintenance_message = 'Maintenance record updated successfully!';

        } else {

            $maintenance_message = 'Unable to update maintenance record. Please try again.';

        }
    }
}


// ================= ADD DRIVER =================
$driver_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_driver'])) {

    $user_id = $_POST['user_id'] !== '' ? (int) $_POST['user_id'] : null;
    $employee_id = trim($_POST['employee_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $license_type = trim($_POST['license_type'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designated_place = trim($_POST['designated_place'] ?? '');
    $driver_status = $_POST['driver_status'] ?? 'Active';

    // Truncate phone to 11 digits
    $phone = mb_substr($phone, 0, 11);

    if ($user_id === null || $first_name === '' || $last_name === '' || $license_number === '') {

        $driver_message =
            'User Account, First Name, Last Name, and License Number are required.';

    } else {

        // Check if user already has a driver record
        $existingDriverCheck = pg_query_params(
            $conn,
            "SELECT driver_id FROM drivers WHERE user_id = $1 LIMIT 1",
            [$user_id]
        );

        if ($existingDriverCheck && pg_num_rows($existingDriverCheck) > 0) {
            $driver_message = 'This user already has a driver record.';
        } else {
            // Check for duplicate license number
            $duplicateCheck = pg_query_params(
                $conn,
                "SELECT driver_id
                 FROM drivers
                 WHERE license_number = $1
                 LIMIT 1",
                [$license_number]
            );

            if ($duplicateCheck && pg_num_rows($duplicateCheck) > 0) {

                $driver_message =
                    'A driver with this license number already exists.';

            } else {

                $sql = "
                    INSERT INTO drivers (
                        user_id,
                        employee_id,
                        first_name,
                        last_name,
                        license_number,
                        license_type,
                        phone,
                        email,
                        address,
                        designated_place,
                        status
                    )
                    VALUES (
                        $1,
                        $2,
                        $3,
                        $4,
                        $5,
                        $6,
                        $7,
                        $8,
                        $9,
                        $10,
                        $11
                    )
                ";

                $result = pg_query_params($conn, $sql, [
                    $user_id,
                    $employee_id !== '' ? $employee_id : null,
                    $first_name,
                    $last_name,
                    $license_number,
                    $license_type !== '' ? $license_type : null,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $address !== '' ? $address : null,
                    $designated_place !== '' ? $designated_place : null,
                    $driver_status
                ]);

                if ($result) {

                    $driver_message =
                        'Driver added successfully!';

                } else {

                    $driver_message =
                        'Unable to add driver. Please check the information and try again.';
                }
            }
        }
    }
}

// ================= UPDATE DRIVER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_driver'])) {

    $driver_id = (int)($_POST['driver_id'] ?? 0);

    $employee_id = trim($_POST['employee_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $license_type = trim($_POST['license_type'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designated_place = trim($_POST['designated_place'] ?? '');
    $driver_status = $_POST['driver_status'] ?? 'Active';

    // Truncate phone to 11 digits
    $phone = mb_substr($phone, 0, 11);

    if (
        $driver_id <= 0 ||
        $first_name === '' ||
        $last_name === '' ||
        $license_number === ''
    ) {

        $driver_message =
            'First Name, Last Name, and License Number are required.';

    } else {

        // Check if another driver already uses this license number
        $duplicateCheck = pg_query_params(
            $conn,
            "SELECT driver_id
             FROM drivers
             WHERE license_number = $1
             AND driver_id <> $2
             LIMIT 1",
            [$license_number, $driver_id]
        );

        if ($duplicateCheck && pg_num_rows($duplicateCheck) > 0) {

            $driver_message =
                'Another driver with this license number already exists.';

        } else {

            $sql = "
                UPDATE drivers
                SET
                    employee_id = $1,
                    first_name = $2,
                    last_name = $3,
                    license_number = $4,
                    license_type = $5,
                    phone = $6,
                    email = $7,
                    address = $8,
                    designated_place = $9,
                    status = $10
                WHERE driver_id = $11
            ";

            $result = pg_query_params($conn, $sql, [
                $employee_id !== '' ? $employee_id : null,
                $first_name,
                $last_name,
                $license_number,
                $license_type !== '' ? $license_type : null,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $address !== '' ? $address : null,
                $designated_place !== '' ? $designated_place : null,
                $driver_status,
                $driver_id
            ]);

            if ($result) {
    echo "<script>window.location.href='index.php?section=fleet';</script>";
    exit;
} else {

                $driver_message =
                    'Unable to update driver. Please try again.';
            }
        }
    }
}

// ================= DELETE DRIVER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_driver'])) {
    require_admin();

    $delete_driver_id = (int) ($_POST['delete_driver_id'] ?? 0);

    if ($delete_driver_id > 0) {

        $result = pg_query_params(
            $conn,
            "DELETE FROM drivers WHERE driver_id = $1",
            [$delete_driver_id]
        );

        if (!$result) {
            $driver_message = 'Unable to delete driver.';
        }
    }
}

// ================= DELETE VEHICLE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle'])) {
    require_admin();

    $delete_vehicle_id = (int) ($_POST['delete_vehicle_id'] ?? 0);

    if ($delete_vehicle_id > 0) {

        // Check if vehicle has maintenance records
        $check = pg_query_params(
            $conn,
            "SELECT COUNT(*) AS total
             FROM maintenance
             WHERE vehicle_id = $1",
            [$delete_vehicle_id]
        );

        $maintenance_count = (int) pg_fetch_result($check, 0, 'total');

        if ($maintenance_count > 0) {

            $vehicle_message =
                'Cannot delete this vehicle because it has maintenance records.';

        } else {

            // Safe to delete
            $result = pg_query_params(
                $conn,
                "DELETE FROM vehicles WHERE vehicle_id = $1",
                [$delete_vehicle_id]
            );

            if (!$result) {
                $vehicle_message = 'Unable to delete vehicle.';
            }
        }
    }
}

// ================= ADD MAINTENANCE =================
$maintenance_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {

    $maintenance_vehicle_id = $_POST['maintenance_vehicle_id'] !== ''
        ? (int) $_POST['maintenance_vehicle_id']
        : null;

    $maintenance_type = trim($_POST['maintenance_type'] ?? '');
    $maintenance_date = $_POST['maintenance_date'] ?? '';

    $maintenance_cost = $_POST['maintenance_cost'] !== ''
        ? (float) $_POST['maintenance_cost']
        : 0;

    $maintenance_status = $_POST['maintenance_status'] ?? 'Scheduled';

    if (
        $maintenance_vehicle_id === null ||
        $maintenance_type === '' ||
        $maintenance_date === ''
    ) {

        $maintenance_message =
            'Vehicle, Maintenance Type, and Maintenance Date are required.';

    } else {

        // Check if the exact same maintenance record already exists
        $duplicateCheck = pg_query_params(
            $conn,
            "SELECT maintenance_id
             FROM maintenance
             WHERE vehicle_id = $1
               AND maintenance_type = $2
               AND maintenance_date = $3
               AND cost = $4
               AND status = $5
             LIMIT 1",
            [
                $maintenance_vehicle_id,
                $maintenance_type,
                $maintenance_date,
                $maintenance_cost,
                $maintenance_status
            ]
        );

        if ($duplicateCheck && pg_num_rows($duplicateCheck) > 0) {

            $maintenance_message =
                'This maintenance record already exists.';

        } else {

            $sql = "
                INSERT INTO maintenance (
                    vehicle_id,
                    maintenance_type,
                    maintenance_date,
                    cost,
                    status
                )
                VALUES ($1, $2, $3, $4, $5)
            ";

            $result = pg_query_params($conn, $sql, [
                $maintenance_vehicle_id,
                $maintenance_type,
                $maintenance_date,
                $maintenance_cost,
                $maintenance_status
            ]);

            if ($result) {

                if (strcasecmp($maintenance_status, 'Completed') === 0) {
                    ftms_reset_maintenance_baseline($conn, $maintenance_vehicle_id);
                }

                $maintenance_message =
                    'Maintenance record added successfully!';

            } else {

                $maintenance_message =
                    'Unable to add maintenance record. Please check the information and try again.';
            }
        }
    }
}

    // ================= ADD VEHICLE =================
if (!isset($vehicle_message)) {
    $vehicle_message = '';
}

// Same folder api/driver/register_vehicle.php already uses, so vehicles
// added from the web form and vehicles registered from the driver app
// land in the same place with the same path format in the DB.
if (!defined('FTMS_VEHICLE_UPLOAD_DIR')) {
    define('FTMS_VEHICLE_UPLOAD_DIR', __DIR__ . '/../uploads/vehicle_documents/');
}
if (!defined('FTMS_VEHICLE_UPLOAD_URL')) {
    // No leading slash — matches the relative 'uploads/...' paths already
    // stored by register_vehicle.php and upload_pod.php.
    define('FTMS_VEHICLE_UPLOAD_URL', 'uploads/vehicle_documents/');
}

if (!function_exists('ftms_save_vehicle_file')) {
    /**
     * Validates and moves a single uploaded file, returning the
     * web-accessible path to store in the DB (or null on failure/absence).
     */
    function ftms_save_vehicle_file(?array $file, string $prefix = 'doc'): ?string {

        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // Same allowed extensions as register_vehicle.php (heic included
        // for photos straight off an iPhone camera).
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'heic'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== '' && !in_array($ext, $allowedExt, true)) {
            return null;
        }

        $maxBytes = 10 * 1024 * 1024; // 10MB, same cap as upload_pod.php
        if ($file['size'] > $maxBytes) {
            return null;
        }

        if (!is_dir(FTMS_VEHICLE_UPLOAD_DIR)) {
            mkdir(FTMS_VEHICLE_UPLOAD_DIR, 0755, true);
        }

        $filename = $prefix . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
        $destination = FTMS_VEHICLE_UPLOAD_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return null;
        }

        return FTMS_VEHICLE_UPLOAD_URL . $filename;
    }
}

if (!function_exists('ftms_save_vehicle_files')) {
    /**
     * Handles a multi-file <input type="file" multiple> field and
     * returns a JSON-encoded array of saved paths (e.g. '["uploads/..","uploads/.."]').
     */
    function ftms_save_vehicle_files(?array $filesField): string {

        $paths = [];

        if ($filesField && isset($filesField['name']) && is_array($filesField['name'])) {

            $count = count($filesField['name']);

            for ($i = 0; $i < $count; $i++) {

                if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $single = [
                    'name'     => $filesField['name'][$i],
                    'type'     => $filesField['type'][$i],
                    'tmp_name' => $filesField['tmp_name'][$i],
                    'error'    => $filesField['error'][$i],
                    'size'     => $filesField['size'][$i],
                ];

                $path = ftms_save_vehicle_file($single, 'other');

                if ($path) {
                    $paths[] = $path;
                }
            }
        }

        return json_encode($paths);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {

    $plate_number = trim($_POST['plate_number'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year_model = $_POST['year_model'] !== '' ? (int) $_POST['year_model'] : null;
    $capacity = $_POST['capacity'] !== '' ? (float) $_POST['capacity'] : null;
    $fuel_type = trim($_POST['fuel_type'] ?? '');
    $odometer = $_POST['odometer'] !== '' ? (float) $_POST['odometer'] : 0;
    $maintenance_interval_km = $_POST['maintenance_interval_km'] !== ''
        ? (float) $_POST['maintenance_interval_km']
        : null;
    $status = $_POST['status'] ?? 'Available';
    $assigned_driver_id = $_POST['assigned_driver_id'] !== ''
        ? (int) $_POST['assigned_driver_id']    
        : null;
    $or_number = trim($_POST['or_number'] ?? '');
    $cr_number = trim($_POST['cr_number'] ?? '');
    $registration_expiry = $_POST['registration_expiry'] !== ''
        ? $_POST['registration_expiry']
        : null;

    // Document / picture attachments
    $license_front_path = ftms_save_vehicle_file($_FILES['license_front'] ?? null, 'license_front');
    $license_back_path  = ftms_save_vehicle_file($_FILES['license_back'] ?? null, 'license_back');
    $other_documents    = ftms_save_vehicle_files($_FILES['other_documents'] ?? null);

    if ($plate_number === '' || $vehicle_type === '') {

        $vehicle_message = 'Plate Number and Vehicle Type are required.';

    } else {

        $sql = "
            INSERT INTO vehicles (
                plate_number,
                vehicle_type,
                brand,
                model,
                year_model,
                capacity,
                fuel_type,
                odometer,
                status,
                assigned_driver_id,
                or_number,
                cr_number,
                registration_expiry,
                license_front_path,
                license_back_path,
                other_documents,
                maintenance_interval_km,
                last_maintenance_odometer
            )
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18)
        ";

        $result = @pg_query_params($conn, $sql, [
            $plate_number,
            $vehicle_type,
            $brand !== '' ? $brand : null,
            $model !== '' ? $model : null,
            $year_model,
            $capacity,
            $fuel_type !== '' ? $fuel_type : null,
            $odometer,
            $status,
            $assigned_driver_id,
            $or_number !== '' ? $or_number : null,
            $cr_number !== '' ? $cr_number : null,
            $registration_expiry,
            $license_front_path,
            $license_back_path,
            $other_documents,
            $maintenance_interval_km,
            $odometer
        ]);

        if ($result) {

    $vehicle_message = 'Vehicle added successfully!';

} else {

    $error = pg_last_error($conn);

    if (strpos($error, 'vehicles_plate_number_key') !== false) {

        $vehicle_message = 'A vehicle with plate number "' .
            htmlspecialchars($plate_number) .
            '" already exists.';

    } else {

        $vehicle_message = 'Unable to add vehicle. Please check the information and try again.';

    }
}
    }
}

// ================= EDIT VEHICLE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_vehicle'])) {

    $edit_vehicle_id = (int) ($_POST['edit_vehicle_id'] ?? 0);
    $edit_plate_number = trim($_POST['edit_plate_number'] ?? '');
    $edit_vehicle_type = trim($_POST['edit_vehicle_type'] ?? '');
    $edit_status = $_POST['edit_status'] ?? 'Available';
    $edit_plate_number = trim($_POST['edit_plate_number'] ?? '');
$edit_vehicle_type = trim($_POST['edit_vehicle_type'] ?? '');
$edit_brand = trim($_POST['edit_brand'] ?? '');
$edit_model = trim($_POST['edit_model'] ?? '');

$edit_year_model = ($_POST['edit_year_model'] ?? '') !== ''
    ? (int) $_POST['edit_year_model']
    : null;

$edit_capacity = ($_POST['edit_capacity'] ?? '') !== ''
    ? (float) $_POST['edit_capacity']
    : null;

$edit_fuel_type = trim($_POST['edit_fuel_type'] ?? '');

$edit_odometer = ($_POST['edit_odometer'] ?? '') !== ''
    ? (float) $_POST['edit_odometer']
    : 0;

$edit_status = $_POST['edit_status'] ?? 'Available';

$edit_assigned_driver_id = ($_POST['edit_assigned_driver_id'] ?? '') !== ''
    ? (int) $_POST['edit_assigned_driver_id']
    : null;

$edit_or_number = trim($_POST['edit_or_number'] ?? '');
$edit_cr_number = trim($_POST['edit_cr_number'] ?? '');
$edit_registration_expiry = ($_POST['edit_registration_expiry'] ?? '') !== ''
    ? $_POST['edit_registration_expiry']
    : null;

$edit_maintenance_interval_km = ($_POST['edit_maintenance_interval_km'] ?? '') !== ''
    ? (float) $_POST['edit_maintenance_interval_km']
    : null;

    if ($edit_vehicle_id <= 0 || $edit_plate_number === '' || $edit_vehicle_type === '') {

        $vehicle_message = 'Plate Number and Vehicle Type are required.';

    } else {

        $sql = "
    UPDATE vehicles
    SET
        plate_number = $1,
        vehicle_type = $2,
        brand = $3,
        model = $4,
        year_model = $5,
        capacity = $6,
        fuel_type = $7,
        odometer = $8,
        status = $9,
        assigned_driver_id = $10,
        or_number = $11,
        cr_number = $12,
        registration_expiry = $13,
        maintenance_interval_km = $14
    WHERE vehicle_id = $15
";

        $result = @pg_query_params($conn, $sql, [
    $edit_plate_number,
    $edit_vehicle_type,
    $edit_brand !== '' ? $edit_brand : null,
    $edit_model !== '' ? $edit_model : null,
    $edit_year_model,
    $edit_capacity,
    $edit_fuel_type !== '' ? $edit_fuel_type : null,
    $edit_odometer,
    $edit_status,
    $edit_assigned_driver_id,
    $edit_or_number !== '' ? $edit_or_number : null,
    $edit_cr_number !== '' ? $edit_cr_number : null,
    $edit_registration_expiry,
    $edit_maintenance_interval_km,
    $edit_vehicle_id
]);

        if ($result) {

            ftms_check_maintenance_due($conn, $edit_vehicle_id);

            $vehicle_message = 'Vehicle updated successfully!';

        } else {

            $error = pg_last_error($conn);

            if (strpos($error, 'vehicles_plate_number_key') !== false) {

                $vehicle_message = 'A vehicle with plate number "' .
                    htmlspecialchars($edit_plate_number) .
                    '" already exists.';

            } else {

                $vehicle_message = 'Unable to update vehicle. Please check the information and try again.';

            }
        }
    }
}

        $vehicleCount = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles"), 0, 0);
        $activeDriverCount = pg_fetch_result(
            pg_query($conn, "SELECT COUNT(*) FROM drivers WHERE LOWER(status) = 'active'"),
            0,
            0
        );
            $scheduledMaintResult = pg_query(
                $conn,
                "SELECT COUNT(*) FROM maintenance WHERE LOWER(status) = 'scheduled'"
        );

            $scheduledMaintCount = $scheduledMaintResult
                ? (int) pg_fetch_result($scheduledMaintResult, 0, 0)
                : 0;

            $inUseCount  = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles WHERE status NOT IN ('Available')"), 0, 0);
            $utilization = $vehicleCount > 0 ? round(((int) $inUseCount / (int) $vehicleCount) * 100) : 0;

    ?>

<style>
    /* Guarantees modal popups are centered on screen, independent of the
       compiled Tailwind build (fixes modals rendering top-left instead of
       centered). Works together with the existing .ftms-modal-open class
       toggled by each modal's open/close JS functions. */
    .ftms-modal.ftms-modal-open {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    /* Clickable data rows in the Vehicles / Drivers tables */
    .ftms-row-clickable {
        cursor: pointer;
    }
    .ftms-row-clickable:hover {
        background-color: rgba(59, 130, 246, 0.06);
    }
    .ftms-view-field {
        margin-bottom: 0.9rem;
    }
    .ftms-view-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #94a3b8;
        margin-bottom: 0.15rem;
    }
    .ftms-view-value {
        font-size: 0.9rem;
        color: #0f172a;
        font-weight: 500;
    }
    .dark .ftms-view-value {
        color: #f1f5f9;
    }
</style>

<section id="fleet" class="section space-y-6">
   
 
        <!-- ================= HEADER ================= -->
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900 dark:text-white">Fleet & Vehicle Management</h1>
                <span class="text-xs font-medium px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 border border-blue-500/20">
                    FVM Module
                </span>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Vehicles, drivers, maintenance & utilization tracking
            </p>
        </div>
    </div>

    <!-- ================= STAT CARDS ================= -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('fleet', 'vehicles')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('fleet', 'vehicles'); }"
             title="View vehicles">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total Vehicles</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $vehicleCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Registered</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <i class="ti ti-car text-blue-500 dark:text-blue-400 text-lg"></i>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('fleet', 'drivers')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('fleet', 'drivers'); }"
             title="View drivers">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Drivers</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeDriverCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Active</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <i class="ti ti-users text-emerald-500 dark:text-emerald-400 text-lg"></i>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('fleet', 'maintenance')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('fleet', 'maintenance'); }"
             title="View maintenance records">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Maintenance</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $scheduledMaintCount ?></p>
                    <p class="text-xs text-slate-400 mt-1">Scheduled</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                    <i class="ti ti-tool text-amber-500 dark:text-amber-400 text-lg"></i>
                </div>
            </div>
        </div>

       <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">Utilization</p>
                <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $utilization ?>%</p>
                <p class="text-xs text-slate-400 mt-1">Fleet usage</p>
            </div>
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(239 68 68 / 0.1)">
                <i class="ti ti-percentage text-red-500 dark:text-red-400 text-lg"></i>
            </div>
        </div>
       </div>
    
    </div>
 
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
    <div class="flex justify-center mb-4">
        <?php render_subtabs('fleet', ['vehicles' => 'Vehicles', 'drivers' => 'Drivers', 'maintenance' => 'Maintenance'], 'vehicles'); ?>
    </div>
 
        <!-- ================= VEHICLES PANEL ================= -->
        <div class="subtab-panel" data-module="fleet" data-subtab="vehicles">
            <?php if (!empty($vehicle_message)): ?>
                <div class="mb-3 px-4 py-2 rounded-lg text-sm border border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <?= htmlspecialchars($vehicle_message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $vehicleCount ?> vehicle(s) registered</p>
              <button
                    type="button"
                    onclick="openVehicleModal()"
                    class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors">
                    <i class="ti ti-plus"></i> Add vehicle
               </button>
    
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm dark:bg-slate-900">
               <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal ">Vehicle</th><th class="font-normal dark:text-slate-400">Type</th>
                    <th class="font-normal">Plate no.</th><th class="font-normal">Assigned driver</th>
                    <th class="font-normal">Last maintenance</th>
                    <th class="font-normal">Registration</th>
                    <th class="font-normal">Status</th>
                    <th class="font-normal text-right pr-4">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:text-slate-400">
                <?php
                $res = pg_query($conn, "SELECT v.vehicle_id, v.vehicle_type, v.plate_number, v.brand, v.model,
                        v.year_model, v.capacity, v.fuel_type, v.odometer, v.status, v.assigned_driver_id, v.last_maintenance_date, v.or_number,
                        v.cr_number, v.registration_expiry, v.license_front_path, v.license_back_path, v.other_documents,d.first_name, d.last_name,
                        v.maintenance_interval_km, v.last_maintenance_odometer
                        FROM vehicles v LEFT JOIN drivers d ON d.driver_id = v.assigned_driver_id ORDER BY v.vehicle_id");
                    
                    if (pg_num_rows($res) === 0): ?>
                            <tr><td colspan="8" class="text-center text-slate-400 py-6">No vehicles yet.</td></tr>
                        <?php else: while ($row = pg_fetch_assoc($res)): ?>
            <tr
                class="ftms-row-clickable"
                onclick="openViewVehicleModal(
                    <?= (int)$row['vehicle_id'] ?>,
                    <?= htmlspecialchars(json_encode($row['vehicle_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['plate_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= $row['year_model'] !== null ? (int)$row['year_model'] : 'null' ?>,
                    <?= $row['capacity'] !== null ? (float)$row['capacity'] : 'null' ?>,
                    <?= htmlspecialchars(json_encode($row['fuel_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= (float)($row['odometer'] ?? 0) ?>,
                    <?= htmlspecialchars(json_encode($row['status'] ?? 'Available'), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['first_name'] ? full_name($row['first_name'], $row['last_name']) : ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['or_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['cr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['registration_expiry'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['last_maintenance_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['license_front_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['license_back_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars(json_encode($row['other_documents'] ?? '[]'), ENT_QUOTES, 'UTF-8') ?>,
                    <?= $row['maintenance_interval_km'] !== null ? (float)$row['maintenance_interval_km'] : 'null' ?>,
                    <?= (float)($row['last_maintenance_odometer'] ?? 0) ?>
                )">
        <td class="py-3 px-4">
            <?= manifest_tag(code_id('VEH', $row['vehicle_id'])) ?>
        </td>

        <td>
            <?= htmlspecialchars($row['vehicle_type'] ?? '—') ?>
        </td>

        <td class="tag text-xs">
            <?= htmlspecialchars($row['plate_number']) ?>
        </td>

        <td>
            <?= $row['first_name']
                ? htmlspecialchars(full_name($row['first_name'], $row['last_name']))
                : '—' ?>
        </td>

        <td>
            <?= htmlspecialchars($row['last_maintenance_date'] ?? '—') ?>
        </td>

        <td>
            <?php
            if (empty($row['registration_expiry'])) {
                echo '<span class="tag text-xs text-slate-400">No record</span>';
            } else {
                $expiry = new DateTime($row['registration_expiry']);
                $today = new DateTime('today');
                $daysLeft = (int) $today->diff($expiry)->format('%r%a');

                if ($daysLeft < 0) {
                    $badgeClass = 'bg-red-50 text-red-600 border border-red-200';
                    $label = 'Expired ' . htmlspecialchars($expiry->format('M d, Y'));
                } elseif ($daysLeft <= 30) {
                    $badgeClass = 'bg-amber-50 text-amber-600 border border-amber-200';
                    $label = 'Expires in ' . $daysLeft . 'd';
                } else {
                    $badgeClass = 'bg-emerald-50 text-emerald-600 border border-emerald-200';
                    $label = 'Valid until ' . htmlspecialchars($expiry->format('M d, Y'));
                }
                echo '<span class="text-xs px-2 py-1 rounded-md ' . $badgeClass . '">' . $label . '</span>';
            }
            ?>
        </td>

        <td>
            <?= badge($row['status'], status_color($row['status'])) ?>
            <?php
                $maintBadge = ftms_maintenance_badge(
                    $row['maintenance_interval_km'] !== null ? (float) $row['maintenance_interval_km'] : null,
                    (float) ($row['last_maintenance_odometer'] ?? 0),
                    (float) ($row['odometer'] ?? 0)
                );
                if ($maintBadge):
            ?>
                <span class="block mt-1 text-xs px-2 py-1 rounded-md <?= $maintBadge['class'] ?>">
                    <?= htmlspecialchars($maintBadge['label']) ?>
                </span>
            <?php endif; ?>
        </td>

        <td class="text-right pr-4" onclick="event.stopPropagation();">
    <button
        type="button"
        onclick="openEditVehicleModal(
            <?= (int)$row['vehicle_id'] ?>,
            <?= htmlspecialchars(json_encode($row['vehicle_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['plate_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= $row['year_model'] !== null ? (int)$row['year_model'] : 'null' ?>,
            <?= $row['capacity'] !== null ? (float)$row['capacity'] : 'null' ?>,
            <?= htmlspecialchars(json_encode($row['fuel_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= (float)($row['odometer'] ?? 0) ?>,
            <?= htmlspecialchars(json_encode($row['status'] ?? 'Available'), ENT_QUOTES, 'UTF-8') ?>,
            <?= $row['assigned_driver_id'] !== null ? (int)$row['assigned_driver_id'] : 'null' ?>,
            <?= htmlspecialchars(json_encode($row['or_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['cr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['registration_expiry'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= $row['maintenance_interval_km'] !== null ? (float)$row['maintenance_interval_km'] : 'null' ?>
        )"
        title="Edit vehicle"
        class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors">
        <i class="ti ti-edit text-base"></i>
    </button>

    <?php if (is_admin()): ?>
        <form method="POST" style="display:inline;">
    <input type="hidden" name="delete_vehicle" value="1">

    <input
        type="hidden"
        name="delete_vehicle_id"
        value="<?= (int)$row['vehicle_id'] ?>">

    <button
        type="submit"
        onclick="return confirm('Are you sure you want to delete this vehicle?');"
        title="Delete vehicle"
        class="inline-flex items-center justify-center w-8 h-8 text-red-600 border border-red-200 rounded-md hover:bg-red-50 hover:border-red-300 transition-colors">
        <i class="ti ti-trash text-base"></i>
    </button>
</form>    
    <?php endif; ?>
</td>
    </tr>
<?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
            <!-- ================= DRIVERS PANEL ================= -->
            <div class="subtab-panel hidden" data-module="fleet" data-subtab="drivers">
                <div class="flex justify-between items-center mb-3">
        <p class="text-sm text-slate-500 ">
            <?= (int) $activeDriverCount ?> active driver(s)
        </p>

        <button
            type="button"
            onclick="openDriverModal()"
            class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors">
            <i class="ti ti-plus"></i> Add driver
        </button>
    </div>


      <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
        <table class="w-full text-sm">
             <thead>
                <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">Driver</th>
                    <th class="font-normal">Phone</th>
                    <th class="font-normal">License no.</th>
                    <th class="font-normal">Designated place</th>
                    <th class="font-normal">Status</th>
                    <th class="font-normal text-right pr-4">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100 dark:text-slate-400">

            <?php
            $res = pg_query($conn, "
                SELECT
                    driver_id,
                    employee_id,
                    first_name,
                    last_name,
                    license_number,
                    license_type,
                    phone,
                    email,
                    address,
                    designated_place,
                    status
                FROM drivers
                ORDER BY driver_id
            ");

            if (pg_num_rows($res) === 0):
            ?>

                <tr>
                    <td colspan="6" class="text-center text-slate-400 py-6">
                        No drivers yet.
                    </td>
                </tr>

            <?php else: while ($row = pg_fetch_assoc($res)): ?>

                <tr
                    class="ftms-row-clickable"
                    onclick="openViewDriverModal(
                        <?= (int)$row['driver_id'] ?>,
                        <?= htmlspecialchars(json_encode($row['employee_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['license_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['license_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['designated_place'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars(json_encode($row['status'] ?? 'Active'), ENT_QUOTES, 'UTF-8') ?>
                    )">

                    <td class="py-3 px-4">
                        <?= htmlspecialchars(
                            full_name($row['first_name'], $row['last_name'])
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($row['phone'] ?? '—') ?>
                    </td>

                    <td class="tag text-xs">
                        <?= htmlspecialchars($row['license_number'] ?? '—') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($row['designated_place'] ?? '—') ?>
                    </td>

                    <td>
                        <?= badge(
                            $row['status'],
                            status_color($row['status'])
                        ) ?>
                    </td>

                    <td class="text-right pr-4" onclick="event.stopPropagation();">

                        <button
                            type="button"
                            onclick="openEditDriverModal(

                                <?= (int)$row['driver_id'] ?>,
                                <?= htmlspecialchars(json_encode($row['employee_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['license_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['license_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['designated_place'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                <?= htmlspecialchars(json_encode($row['status'] ?? 'Active'), ENT_QUOTES, 'UTF-8') ?>

                             )"
                            title="Edit driver"
                            class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors">
                            <i class="ti ti-edit text-base"></i>
                        </button>

                        <?php if (is_admin()): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="delete_driver" value="1">
                            <input
                                type="hidden"
                                name="delete_driver_id"
                                value="<?= (int)$row['driver_id'] ?>">

                            <button
                                type="submit"
                                onclick="return confirm('Are you sure you want to delete this driver?');"
                                title="Delete driver"
                                class="inline-flex items-center justify-center w-8 h-8 text-red-600 border border-red-200 rounded-md hover:bg-red-50 hover:border-red-300 transition-colors">
                                <i class="ti ti-trash text-base"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>

            <?php endwhile; endif; ?>

            </tbody>
        </table>
    </div>
            </div>

        <!-- ================= MAINTENANCE PANEL ================= -->
     <div class="subtab-panel hidden" data-module="fleet" data-subtab="maintenance" dark:text-slate-400>

    <?php
    $totalMaintCostRow = pg_query($conn, "
        SELECT COALESCE(SUM(cost),0) AS total
        FROM maintenance
        WHERE status = 'Completed'
    ");
    $totalMaintCost = $totalMaintCostRow
        ? (float) pg_fetch_result($totalMaintCostRow, 0, 'total')
        : 0;
    ?>

    <div class="flex justify-between items-center mb-3">
        <p class="text-sm text-slate-500">
            Total maintenance cost (Completed)
        </p>
        <p class="text-lg font-semibold text-slate-800 dark:text-slate-400">
            &#8369;<?= number_format($totalMaintCost, 2) ?>
        </p>
    </div>

    <form method="get" class="flex flex-wrap items-center gap-3 mb-4 dark:text-slate-400">

        <input type="hidden" name="section" value="fleet dark:text-slate-400">

            <span class="text-xs text-slate-400 bg-slate-50 px-3 py-2.5 rounded-lg border border-slate-200 dark:text-slate-400">
                Filter
            </span>

                <input
                    type="text"
                    name="maint_q"
                    value="<?= htmlspecialchars($_GET['maint_q'] ?? '') ?>"
                    placeholder="Type / cost"
                    class="border border-slate-200 rounded-lg px-3 py-2 text-sm flex-1 min-w-40 dark:text-slate-400"
                >

                <select
                    name="maint_vehicle"
                    class="border border-slate-200 rounded-lg px-3 py-2 text-sm dark:text-slate-400"
                >
                    <option value="">All Vehicles</option>

            <?php
                $vres = pg_query($conn, "SELECT vehicle_id, plate_number FROM vehicles ORDER BY plate_number");

            while ($v = pg_fetch_assoc($vres)):
                $sel = (
                    ($_GET['maint_vehicle'] ?? '') == $v['vehicle_id']
                ) ? 'selected' : '';
            ?>

                <option
                    value="<?= (int)$v['vehicle_id'] ?>"
                    <?= $sel ?>
                >
                    <?= htmlspecialchars($v['plate_number']) ?>
                </option>

            <?php endwhile; ?>

                </select>

                <select
                    name="maint_status"
                    class="border border-slate-200 rounded-lg px-3 py-2 text-sm dark:text-slate-400"
                >
                    <option value="">All Status</option>

                    <?php
                    foreach (['Scheduled', 'In Progress', 'Completed', 'Cancelled'] as $s):
                        $sel = (
                            ($_GET['maint_status'] ?? '') === $s
                        ) ? 'selected' : '';
                    ?>

                        <option value="<?= htmlspecialchars($s) ?>" <?= $sel ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
                <button
                    type="button"
                    onclick="openMaintenanceModal()"
                    class="ml-auto bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors"
                >
                    <i class="ti ti-plus"></i>
                    New Maintenance
                </button>

            </form>


    <!-- MAINTENANCE TABLE -->
  <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
        <table class="w-full text-sm">
             <thead>
                <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">ID</th>
                    <th class="font-normal">Vehicle</th>
                    <th class="font-normal">Type</th>
                    <th class="font-normal">Date</th>
                    <th class="font-normal">Cost</th>
                    <th class="font-normal">Status</th>
                    <th class="font-normal text-right pr-4">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100 dark:text-slate-400">

            <?php

            $where = [];
            $params = [];

            if (!empty($_GET['maint_q'])) {

                $params[] = '%' . $_GET['maint_q'] . '%';

                $where[] =
                    "(m.maintenance_type ILIKE $" . count($params) .
                    " OR CAST(m.cost AS TEXT) ILIKE $" . count($params) . ")";
            }

            if (!empty($_GET['maint_vehicle'])) {

                $params[] = $_GET['maint_vehicle'];

                $where[] =
                    "m.vehicle_id = $" . count($params);
            }

            if (!empty($_GET['maint_status'])) {

                $params[] = $_GET['maint_status'];

                $where[] =
                    "m.status = $" . count($params);
            }

            $whereSql = $where
                ? 'WHERE ' . implode(' AND ', $where)
                : '';

            $sql = "
                SELECT
                    m.maintenance_id,
                    m.vehicle_id,
                    m.maintenance_type,
                    m.maintenance_date,
                    m.cost,
                    m.status,
                    v.plate_number
                FROM maintenance m
                JOIN vehicles v
                    ON v.vehicle_id = m.vehicle_id
                $whereSql
                ORDER BY m.maintenance_date DESC
            ";

            $res = $params
                ? pg_query_params($conn, $sql, $params)
                : pg_query($conn, $sql);


            if (!$res) {

                echo '
                    <tr>
                        <td colspan="7"
                            class="text-center text-red-500 py-6">
                            Unable to load maintenance records.
                        </td>
                    </tr>
                ';

            } elseif (pg_num_rows($res) === 0) {

                echo '
                    <tr>
                        <td colspan="7"
                            class="text-center text-slate-400 py-6">
                            No maintenance records found.
                        </td>
                    </tr>
                ';

            } else {

                while ($row = pg_fetch_assoc($res)):

            ?>

                <tr>

                    <td class="py-3 px-4">
                        <?= manifest_tag(
                            code_id('MNT', $row['maintenance_id'])
                        ) ?>
                    </td>

                    <td class="tag text-xs">
                        <?= htmlspecialchars(
                            $row['plate_number'] ?? '—'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $row['maintenance_type'] ?? '—'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $row['maintenance_date'] ?? '—'
                        ) ?>
                    </td>

                    <td>
                        <?= number_format(
                            (float)($row['cost'] ?? 0),
                            2
                        ) ?>
                    </td>

                    <td>
                        <?= badge(
                            $row['status'],
                            status_color($row['status'])
                        ) ?>
                    </td>

                    <td class="text-right pr-4">

    <button
        type="button"
        onclick="openEditMaintenanceModal(
            <?= (int)$row['maintenance_id'] ?>,
            <?= (int)$row['vehicle_id'] ?>,
            <?= htmlspecialchars(json_encode($row['maintenance_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars(json_encode($row['maintenance_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
            <?= (float)($row['cost'] ?? 0) ?>,
            <?= htmlspecialchars(json_encode($row['status'] ?? 'Scheduled'), ENT_QUOTES, 'UTF-8') ?>
        )"
        title="Edit maintenance record"
        class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors">
        <i class="ti ti-edit text-base"></i>
    </button>

    <?php if (is_admin()): ?>
    <form method="POST" style="display:inline;">
        <input type="hidden" name="delete_maintenance" value="1">

        <input
            type="hidden"
            name="delete_maintenance_id"
            value="<?= (int)$row['maintenance_id'] ?>">

        <button
            type="submit"
            onclick="return confirm('Are you sure you want to delete this maintenance record?');"
            title="Delete maintenance record"
            class="inline-flex items-center justify-center w-8 h-8 text-red-600 border border-red-200 rounded-md hover:bg-red-50 hover:border-red-300 transition-colors">
            <i class="ti ti-trash text-base"></i>
        </button>
    </form>
    <?php endif; ?>

</td>
                </tr>

            <?php
                endwhile;
            }

            ?>

            </tbody>

        </table>

    </div>

</div>

    <!-- ================= ADD VEHICLE MODAL ================= -->
<div id="vehicleModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Add Vehicle
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Enter the vehicle information below.
                </p>
            </div>

            <button type="button"
                    onclick="closeVehicleModal()"
                    class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" enctype="multipart/form-data" class="p-6">

            <input type="hidden" name="add_vehicle" value="1">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Plate Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Plate Number <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="plate_number"
                        required
                        maxlength="20"
                        placeholder="e.g. ABC-1234"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                </div>

                <!-- Vehicle Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Vehicle Type <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="vehicle_type"
                        required
                        maxlength="50"
                        placeholder="e.g. Van, Truck, Sedan"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                </div>

                <!-- Brand -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Brand
                    </label>

                    <input
                        type="text"
                        name="brand"
                        maxlength="50"
                        placeholder="e.g. Toyota"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Model -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Model
                    </label>

                    <input
                        type="text"
                        name="model"
                        maxlength="50"
                        placeholder="e.g. Hiace"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Year -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Year Model
                    </label>

                    <input
                        type="number"
                        name="year_model"
                        min="1900"
                        max="2100"
                        placeholder="e.g. 2025"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Capacity -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Capacity
                    </label>

                    <input
                        type="number"
                        name="capacity"
                        step="0.01"
                        min="0"
                        placeholder="e.g. 15"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Fuel Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Fuel Type
                    </label>

                    <select
                        name="fuel_type"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select fuel type</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Gasoline">Gasoline</option>
                        <option value="Electric">Electric</option>
                        <option value="Hybrid">Hybrid</option>

                    </select>
                </div>

                <!-- Odometer -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Odometer (km)
                    </label>

                    <input
                        type="number"
                        name="odometer"
                        step="0.01"
                        min="0"
                        value="0"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Maintenance Interval -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Interval (km)
                    </label>

                    <input
                        type="number"
                        name="maintenance_interval_km"
                        step="0.01"
                        min="0"
                        placeholder="e.g. 5000"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                    <p class="text-xs text-slate-400 mt-1">Driver gets notified when odometer nears this limit. Leave blank to disable.</p>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="status"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Available">Available</option>
                        <option value="Reserved">Reserved</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Out of Service">Out of Service</option>

                    </select>
                </div>

                <!-- Assigned Driver -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Assigned Driver
                    </label>

                    <select
                        name="assigned_driver_id"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">No driver assigned</option>

                        <?php
                        $driverResult = pg_query(
                            $conn,
                            "SELECT driver_id, first_name, last_name
                             FROM drivers
                             WHERE status = 'Active'
                             ORDER BY first_name, last_name"
                        );

                        if ($driverResult):
                            while ($driver = pg_fetch_assoc($driverResult)):
                        ?>

                            <option value="<?= (int)$driver['driver_id'] ?>">
                                <?= htmlspecialchars(
                                    $driver['first_name'] . ' ' . $driver['last_name']
                                ) ?>
                            </option>

                        <?php
                            endwhile;
                        endif;
                        ?>

                    </select>
                </div>

                <!-- OR Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        OR Number
                    </label>

                    <input
                        type="text"
                        name="or_number"
                        maxlength="50"
                        placeholder="Official Receipt No."
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- CR Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        CR Number
                    </label>

                    <input
                        type="text"
                        name="cr_number"
                        maxlength="50"
                        placeholder="Certificate of Registration No."
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Registration Expiry -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Registration Expiry
                    </label>

                    <input
                        type="date"
                        name="registration_expiry"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                </div>

            </div>

            <!-- Documents -->
            <div class="mt-6 pt-4 border-t border-slate-200 dark:border-slate-700">

                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">
                    Vehicle Documents
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <!-- License/OR-CR Front -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            OR/CR Front Photo
                        </label>

                        <input
                            type="file"
                            name="license_front"
                            accept="image/*,.pdf"
                            onchange="ftmsPreviewImage(this, 'add_vehicle_front_preview')"
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:text-slate-700 dark:file:text-slate-200 file:text-sm hover:file:bg-slate-200 dark:hover:file:bg-slate-600">

                        <img id="add_vehicle_front_preview" class="hidden mt-2 w-24 h-24 object-cover rounded-lg border border-slate-200 dark:border-slate-700">
                    </div>

                    <!-- License/OR-CR Back -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            OR/CR Back Photo
                        </label>

                        <input
                            type="file"
                            name="license_back"
                            accept="image/*,.pdf"
                            onchange="ftmsPreviewImage(this, 'add_vehicle_back_preview')"
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:text-slate-700 dark:file:text-slate-200 file:text-sm hover:file:bg-slate-200 dark:hover:file:bg-slate-600">

                        <img id="add_vehicle_back_preview" class="hidden mt-2 w-24 h-24 object-cover rounded-lg border border-slate-200 dark:border-slate-700">
                    </div>

                    <!-- Other Documents -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Other Documents
                        </label>

                        <input
                            type="file"
                            name="other_documents[]"
                            accept="image/*,.pdf"
                            multiple
                            onchange="ftmsPreviewMultiple(this, 'add_vehicle_other_preview')"
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:text-slate-700 dark:file:text-slate-200 file:text-sm hover:file:bg-slate-200 dark:hover:file:bg-slate-600">

                        <p class="text-xs text-slate-400 mt-1">You can select multiple files (e.g. insurance, inspection, other permits).</p>

                        <div id="add_vehicle_other_preview" class="flex flex-wrap gap-2 mt-2"></div>
                    </div>

                </div>
            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeVehicleModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
                    Save Vehicle
                </button>

            </div>

        </form>
    </div>
</div>

<!-- ================= VIEW VEHICLE MODAL ================= -->
<div id="viewVehicleModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100" id="view_vehicle_title">
                    Vehicle Details
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Full information for this vehicle.
                </p>
            </div>

            <button
                type="button"
                onclick="closeViewVehicleModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Body -->
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Plate Number</p>
                    <p class="ftms-view-value" id="view_vehicle_plate">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Vehicle Type</p>
                    <p class="ftms-view-value" id="view_vehicle_type">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Brand</p>
                    <p class="ftms-view-value" id="view_vehicle_brand">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Model</p>
                    <p class="ftms-view-value" id="view_vehicle_model">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Year Model</p>
                    <p class="ftms-view-value" id="view_vehicle_year">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Capacity</p>
                    <p class="ftms-view-value" id="view_vehicle_capacity">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Fuel Type</p>
                    <p class="ftms-view-value" id="view_vehicle_fuel">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Odometer (km)</p>
                    <p class="ftms-view-value" id="view_vehicle_odometer">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Maintenance Status</p>
                    <p class="ftms-view-value" id="view_vehicle_maint_status">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Status</p>
                    <p class="ftms-view-value" id="view_vehicle_status">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Assigned Driver</p>
                    <p class="ftms-view-value" id="view_vehicle_driver">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">OR Number</p>
                    <p class="ftms-view-value" id="view_vehicle_or">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">CR Number</p>
                    <p class="ftms-view-value" id="view_vehicle_cr">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Registration Expiry</p>
                    <p class="ftms-view-value" id="view_vehicle_expiry">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Last Maintenance</p>
                    <p class="ftms-view-value" id="view_vehicle_lastmaint">—</p>
                </div>
                
                <div class="ftms-view-field md:col-span-2">
                    <p class="ftms-view-label">Driver's License</p>
                    <div class="flex gap-3 mt-1" id="view_vehicle_license_wrap">
                        <img id="view_vehicle_license_front" src="" alt="License front"
                            class="hidden w-32 h-20 object-cover rounded-lg border cursor-pointer"
                            onclick="window.open(this.src, '_blank')">
                        <img id="view_vehicle_license_back" src="" alt="License back"
                            class="hidden w-32 h-20 object-cover rounded-lg border cursor-pointer"
                            onclick="window.open(this.src, '_blank')">
                    </div>
                </div>

                <div class="ftms-view-field md:col-span-2">
                    <p class="ftms-view-label">Other Documents</p>
                    <div class="flex flex-wrap gap-3 mt-1" id="view_vehicle_other_docs"></div>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex justify-end gap-3 px-6 pb-6 rounded-b-xl">
            <button
                type="button"
                onclick="closeViewVehicleModal()"
                class="px-4 py-2 text-sm rounded-lg border border-slate-200 bg-blue-600 dark:border-slate-700 text-white dark:text-slate-300 hover:bg-blue-700 dark:hover:bg-slate-800">
                Close
            </button>
        </div>
    </div>
</div>

<!-- ================= EDIT VEHICLE MODAL ================= -->
<div id="editVehicleModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Edit Vehicle
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Update the vehicle information below.
                </p>
            </div>

            <button
                type="button"
                onclick="closeEditVehicleModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6">

            <input type="hidden" name="edit_vehicle" value="1">
            <input type="hidden" name="edit_vehicle_id" id="edit_vehicle_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Plate Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Plate Number <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="edit_plate_number"
                        id="edit_plate_number"
                        required
                        maxlength="20"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Vehicle Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Vehicle Type <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="edit_vehicle_type"
                        id="edit_vehicle_type"
                        required
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Brand -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Brand
                    </label>

                    <input
                        type="text"
                        name="edit_brand"
                        id="edit_brand"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Model -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Model
                    </label>

                    <input
                        type="text"
                        name="edit_model"
                        id="edit_model"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Year Model -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Year Model
                    </label>

                    <input
                        type="number"
                        name="edit_year_model"
                        id="edit_year_model"
                        min="1900"
                        max="2100"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Capacity -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Capacity
                    </label>

                    <input
                        type="number"
                        name="edit_capacity"
                        id="edit_capacity"
                        step="0.01"
                        min="0"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Fuel Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Fuel Type
                    </label>

                    <select
                        name="edit_fuel_type"
                        id="edit_fuel_type"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select fuel type</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Gasoline">Gasoline</option>
                        <option value="Electric">Electric</option>
                        <option value="Hybrid">Hybrid</option>

                    </select>
                </div>

                <!-- Odometer -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Odometer (km)
                    </label>

                    <input
                        type="number"
                        name="edit_odometer"
                        id="edit_odometer"
                        step="0.01"
                        min="0"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Maintenance Interval -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Interval (km)
                    </label>

                    <input
                        type="number"
                        name="edit_maintenance_interval_km"
                        id="edit_maintenance_interval_km"
                        step="0.01"
                        min="0"
                        placeholder="e.g. 5000"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                    <p class="text-xs text-slate-400 mt-1">Driver gets notified when odometer nears this limit. Leave blank to disable.</p>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="edit_status"
                        id="edit_status"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Available">Available</option>
                        <option value="Reserved">Reserved</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Out of Service">Out of Service</option>

                    </select>
                </div>

                <!-- Assigned Driver -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Assigned Driver
                    </label>

                    <select
                        name="edit_assigned_driver_id"
                        id="edit_assigned_driver_id"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">No driver assigned</option>

                        <?php
                        $editDriverResult = pg_query(
                            $conn,
                            "SELECT driver_id, first_name, last_name
                             FROM drivers
                             WHERE status = 'Active'
                             ORDER BY first_name, last_name"
                        );

                        if ($editDriverResult):
                            while ($driver = pg_fetch_assoc($editDriverResult)):
                        ?>

                            <option value="<?= (int)$driver['driver_id'] ?>">
                                <?= htmlspecialchars(
                                    $driver['first_name'] . ' ' . $driver['last_name']
                                ) ?>
                            </option>

                        <?php
                            endwhile;
                        endif;
                        ?>

                    </select>
                </div>

                <!-- OR Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        OR Number
                    </label>

                    <input
                        type="text"
                        name="edit_or_number"
                        id="edit_or_number"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- CR Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        CR Number
                    </label>

                    <input
                        type="text"
                        name="edit_cr_number"
                        id="edit_cr_number"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Registration Expiry -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Registration Expiry
                    </label>

                    <input
                        type="date"
                        name="edit_registration_expiry"
                        id="edit_registration_expiry"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                </div>

            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeEditVehicleModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
                    Save Changes
                </button>

            </div>

        </form>
    </div>
</div>


<!-- ================= NEW MAINTENANCE MODAL ================= -->
<div id="maintenanceModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    New Maintenance
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Enter the maintenance information below.
                </p>
            </div>

            <button
                type="button"
                onclick="closeMaintenanceModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6">

            <input type="hidden" name="add_maintenance" value="1">

            <div class="space-y-4">

                <!-- Vehicle -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Vehicle <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="maintenance_vehicle_id"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select vehicle</option>

                        <?php
                        $maintenanceVehicles = pg_query(
                            $conn,
                            "SELECT vehicle_id, plate_number, vehicle_type
                             FROM vehicles
                             ORDER BY plate_number"
                        );

                        if ($maintenanceVehicles):
                            while ($vehicle = pg_fetch_assoc($maintenanceVehicles)):
                        ?>

                            <option value="<?= (int)$vehicle['vehicle_id'] ?>">
                                <?= htmlspecialchars($vehicle['plate_number']) ?>
                                - <?= htmlspecialchars($vehicle['vehicle_type']) ?>
                            </option>

                        <?php
                            endwhile;
                        endif;
                        ?>

                    </select>
                </div>

                <!-- Maintenance Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Type <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="maintenance_type"
                        required
                        maxlength="100"
                        placeholder="e.g. Oil Change, Tire Replacement"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Maintenance Date -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Date <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="date"
                        name="maintenance_date"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                </div>

                <!-- Cost -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Cost
                    </label>

                    <input
                        type="number"
                        name="maintenance_cost"
                        step="0.01"
                        min="0"
                        value="0"
                        placeholder="0.00"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="maintenance_status"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>

                    </select>
                </div>

            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeMaintenanceModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
    type="submit"
    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
    Save Maintenance
</button>

            </div>

        </form>

    </div>
</div>

<!-- ================= EDIT MAINTENANCE MODAL ================= -->
<div id="editMaintenanceModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Edit Maintenance
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Update the maintenance information.
                </p>
            </div>

            <button
                type="button"
                onclick="closeEditMaintenanceModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6">

            <input type="hidden" name="edit_maintenance" value="1">

            <input
                type="hidden"
                name="edit_maintenance_id"
                id="editMaintenanceId">

            <div class="space-y-4">

                <!-- Vehicle -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Vehicle <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="edit_maintenance_vehicle_id"
                        id="editMaintenanceVehicle"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select vehicle</option>

                        <?php
                        $editMaintenanceVehicles = pg_query(
                            $conn,
                            "SELECT vehicle_id, plate_number, vehicle_type
                             FROM vehicles
                             ORDER BY plate_number"
                        );

                        if ($editMaintenanceVehicles):
                            while ($vehicle = pg_fetch_assoc($editMaintenanceVehicles)):
                        ?>

                            <option value="<?= (int)$vehicle['vehicle_id'] ?>">
                                <?= htmlspecialchars($vehicle['plate_number']) ?>
                                - <?= htmlspecialchars($vehicle['vehicle_type']) ?>
                            </option>

                        <?php
                            endwhile;
                        endif;
                        ?>

                    </select>
                </div>

                <!-- Maintenance Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Type <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="edit_maintenance_type"
                        id="editMaintenanceType"
                        required
                        maxlength="100"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Date -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Maintenance Date <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="date"
                        name="edit_maintenance_date"
                        id="editMaintenanceDate"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                </div>

                <!-- Cost -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Cost
                    </label>

                    <input
                        type="number"
                        name="edit_maintenance_cost"
                        id="editMaintenanceCost"
                        step="0.01"
                        min="0"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="edit_maintenance_status"
                        id="editMaintenanceStatus"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>

                    </select>
                </div>

            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeEditMaintenanceModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
                    Save Changes
                </button>

            </div>

        </form>
    </div>
</div>

<!-- ================= ADD DRIVER MODAL ================= -->
<div id="driverModal"
     class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/50 p-4">


   <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Add Driver
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Enter the driver information below.
                </p>
                <p class="text-xs text-amber-200 dark:text-amber-400 mt-1">
                    <i class="ti ti-info-circle"></i> Note: User accounts must be created first in User Management with 'driver' role.
                </p>
            </div>

            <button
                type="button"
                onclick="closeDriverModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6">

            <input type="hidden" name="add_driver" value="1">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- User Account -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        User Account <span class="text-red-500">*</span>
                    </label>
                    <select
                        name="user_id"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                        <option value="">Select a user account</option>
                        <?php
                        // Get users with driver role who don't have driver records yet
                        $availableUsers = pg_query($conn, "
                            SELECT u.user_id, u.username, u.first_name, u.last_name, u.email
                            FROM users u
                            WHERE LOWER(u.role) = 'driver'
                            AND u.status = 'active'
                            AND u.user_id NOT IN (SELECT user_id FROM drivers WHERE user_id IS NOT NULL)
                            ORDER BY u.first_name, u.last_name
                        ");
                        if ($availableUsers) {
                            while ($user = pg_fetch_assoc($availableUsers)) {
                                echo '<option value="' . htmlspecialchars($user['user_id']) . '">' .
                                     htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['username'] . ')') .
                                     '</option>';
                            }
                        }
                        ?>
                    </select>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                        Select a user account with driver role. The user must be created first in User Management.
                    </p>
                </div>

                <!-- Employee ID -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Employee ID
                    </label>

                    <input
                        type="text"
                        name="employee_id"
                        maxlength="50"
                        placeholder="e.g. EMP-001"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- First Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        First Name <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="first_name"
                        required
                        maxlength="100"
                        placeholder="e.g. Juan"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Last Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Last Name <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="last_name"
                        required
                        maxlength="100"
                        placeholder="e.g. Dela Cruz"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- License Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        License Number <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="license_number"
                        required
                        maxlength="50"
                        placeholder="e.g. N01-12-345678"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- License Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        License Type
                    </label>

                    <input
                        type="text"
                        name="license_type"
                        maxlength="50"
                        placeholder="e.g. Professional"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Phone
                    </label>

                    <input
                        type="text"
                        name="phone"
                        maxlength="11"
                        inputmode="numeric"
                        pattern="[0-9]{11}"
                        placeholder="e.g. 09123456789"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        maxlength="150"
                        placeholder="e.g. driver@email.com"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Address -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Address
                    </label>

                    <textarea
                        name="address"
                        rows="3"
                        placeholder="Driver's address"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500"></textarea>
                </div>

                <!-- Designated Place -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Designated Place
                    </label>

                    <select
                        name="designated_place"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select a location</option>
                        <option value="Las Piñas">Las Piñas</option>
                        <option value="Makati">Makati</option>
                        <option value="Mandaluyong">Mandaluyong</option>
                        <option value="Manila">Manila</option>
                        <option value="Parañaque">Parañaque</option>
                        <option value="Pasay">Pasay</option>
                        <option value="Pasig">Pasig</option>
                        <option value="Pateros">Pateros</option>
                        <option value="Quezon City">Quezon City</option>
                        <option value="San Juan">San Juan</option>
                        <option value="Taguig">Taguig</option>

                    </select>

                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                        Excludes North Caloocan, Malabon, Muntinlupa, Navotas,
                        Valenzuela, Marikina, Coastal Road, and Pasig-Marcos
                        Hiway — Central Luzon rates apply for these areas.
                    </p>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="driver_status"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>

                    </select>
                </div>

            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeDriverModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
                    Save Driver
                </button>

            </div>

        </form>
    </div>
</div>

<!-- ================= VIEW DRIVER MODAL ================= -->
<div id="viewDriverModal"
     class="ftms-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100" id="view_driver_title">
                    Driver Details
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Full information for this driver.
                </p>
            </div>

            <button
                type="button"
                onclick="closeViewDriverModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Body -->
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Employee ID</p>
                    <p class="ftms-view-value" id="view_driver_employee_id">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Full Name</p>
                    <p class="ftms-view-value" id="view_driver_name">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">License Number</p>
                    <p class="ftms-view-value" id="view_driver_license_number">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">License Type</p>
                    <p class="ftms-view-value" id="view_driver_license_type">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Phone</p>
                    <p class="ftms-view-value" id="view_driver_phone">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Email</p>
                    <p class="ftms-view-value" id="view_driver_email">—</p>
                </div>

                <div class="ftms-view-field md:col-span-2">
                    <p class="ftms-view-label">Address</p>
                    <p class="ftms-view-value" id="view_driver_address">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Designated Place</p>
                    <p class="ftms-view-value" id="view_driver_place">—</p>
                </div>

                <div class="ftms-view-field">
                    <p class="ftms-view-label">Status</p>
                    <p class="ftms-view-value" id="view_driver_status">—</p>
                </div>

            </div>
        </div>

        <!-- Buttons -->
        <div class="flex justify-end gap-3 px-6 pb-6 rounded-b-xl">
            <button
                type="button"
                onclick="closeViewDriverModal()"
                class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                Close
            </button>
        </div>
    </div>
</div>


<!-- ================= EDIT DRIVER MODAL ================= -->
<div id="editDriverModal"
     class="ftms-modal fixed inset-0 bg-black/50 hidden items-center justify-center z-50">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Edit Driver
                </h2>
                <p class="text-sm text-white/80 dark:text-slate-400">
                    Update the driver information below.
                </p>
            </div>

            <button
                type="button"
                onclick="closeEditDriverModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6">

            <input type="hidden" name="update_driver" value="1">
            <input type="hidden" name="driver_id" id="edit_driver_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Employee ID -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Employee ID
                    </label>

                    <input
                        type="text"
                        name="employee_id"
                        id="edit_employee_id"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- First Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        First Name <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="first_name"
                        id="edit_first_name"
                        required
                        maxlength="100"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Last Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Last Name <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="last_name"
                        id="edit_last_name"
                        required
                        maxlength="100"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- License Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        License Number <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="license_number"
                        id="edit_license_number"
                        required
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- License Type -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        License Type
                    </label>

                    <input
                        type="text"
                        name="license_type"
                        id="edit_license_type"
                        maxlength="50"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Phone
                    </label>

                    <input
                        type="text"
                        name="phone"
                        id="edit_phone"
                        maxlength="11"
                        inputmode="numeric"
                        pattern="[0-9]{11}"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        id="edit_email"
                        maxlength="150"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                </div>

                <!-- Address -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Address
                    </label>

                    <textarea
                        name="address"
                        id="edit_address"
                        rows="3"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500"></textarea>
                </div>

                <!-- Designated Place -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Designated Place
                    </label>

                    <select
                        name="designated_place"
                        id="edit_designated_place"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="">Select a location</option>
                        <option value="Las Piñas">Las Piñas</option>
                        <option value="Makati">Makati</option>
                        <option value="Mandaluyong">Mandaluyong</option>
                        <option value="Manila">Manila</option>
                        <option value="Parañaque">Parañaque</option>
                        <option value="Pasay">Pasay</option>
                        <option value="Pasig">Pasig</option>
                        <option value="Pateros">Pateros</option>
                        <option value="Quezon City">Quezon City</option>
                        <option value="San Juan">San Juan</option>
                        <option value="Taguig">Taguig</option>

                    </select>

                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                        Excludes North Caloocan, Malabon, Muntinlupa, Navotas,
                        Valenzuela, Marikina, Coastal Road, and Pasig-Marcos
                        Hiway — Central Luzon rates apply for these areas.
                    </p>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Status
                    </label>

                    <select
                        name="driver_status"
                        id="edit_driver_status"
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">

                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>

                    </select>
                </div>

            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeEditDriverModal()"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-ink-800">
                    Save Changes
                </button>

            </div>

        </form>
    </div>
</div>

<script>
    function openEditDriverModal(
        driverId,
        employeeId,
        firstName,
        lastName,
        licenseNumber,
        licenseType,
        phone,
        email,
        address,
        designatedPlace,
        status
    ) 
{
    document.getElementById('edit_driver_id').value = driverId;
    document.getElementById('edit_employee_id').value = employeeId;
    document.getElementById('edit_first_name').value = firstName;
    document.getElementById('edit_last_name').value = lastName;
    document.getElementById('edit_license_number').value = licenseNumber;
    document.getElementById('edit_license_type').value = licenseType;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_designated_place').value = designatedPlace;
    document.getElementById('edit_driver_status').value = status;

    document.getElementById('editDriverModal').classList.remove('hidden');
    document.getElementById('editDriverModal').classList.add('flex');
    document.getElementById('editDriverModal').classList.add('ftms-modal-open');
}

function closeEditDriverModal() {
    const modal = document.getElementById('editDriverModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>

<script>
function openDriverModal() {
    const modal = document.getElementById('driverModal');
   
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeDriverModal() {
    const modal = document.getElementById('driverModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>

<script>
function openEditMaintenanceModal(
    maintenanceId,
    vehicleId,
    maintenanceType,
    maintenanceDate,
    cost,
    status
) {
    document.getElementById('editMaintenanceId').value = maintenanceId;
    document.getElementById('editMaintenanceVehicle').value = vehicleId;
    document.getElementById('editMaintenanceType').value = maintenanceType;
    document.getElementById('editMaintenanceDate').value = maintenanceDate;
    document.getElementById('editMaintenanceCost').value = cost;
    document.getElementById('editMaintenanceStatus').value = status;

    const modal = document.getElementById('editMaintenanceModal');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeEditMaintenanceModal() {
    const modal = document.getElementById('editMaintenanceModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>

<script>
function openMaintenanceModal() {
    const modal = document.getElementById('maintenanceModal');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeMaintenanceModal() {
    const modal = document.getElementById('maintenanceModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>

<script>
function deleteMaintenance(maintenanceId) {

    const confirmed = confirm(
        'Are you sure you want to delete this maintenance record?'
    );

    if (!confirmed) {
        return;
    }

    const form = document.createElement('form');

    form.method = 'POST';
    form.action = '';

    const action = document.createElement('input');
    action.type = 'hidden';
    action.name = 'delete_maintenance';
    action.value = '1';

    const id = document.createElement('input');
    id.type = 'hidden';
    id.name = 'delete_maintenance_id';
    id.value = maintenanceId;

    form.appendChild(action);
    form.appendChild(id);

    document.body.appendChild(form);
    form.submit();
}
</script>

<script>
function openEditVehicleModal(
    vehicleId,
    vehicleType,
    plateNumber,
    brand,
    model,
    yearModel,
    capacity,
    fuelType,
    odometer,
    status,
    assignedDriverId,
    orNumber,
    crNumber,
    registrationExpiry,
    maintenanceIntervalKm
) {

    document.getElementById('edit_vehicle_id').value = vehicleId;
    document.getElementById('edit_vehicle_type').value = vehicleType;
    document.getElementById('edit_plate_number').value = plateNumber;
    document.getElementById('edit_brand').value = brand;
    document.getElementById('edit_model').value = model;

    document.getElementById('edit_year_model').value =
        yearModel !== null ? yearModel : '';

    document.getElementById('edit_capacity').value =
        capacity !== null ? capacity : '';

    document.getElementById('edit_fuel_type').value = fuelType;

    document.getElementById('edit_odometer').value =
        odometer !== null ? odometer : 0;

    document.getElementById('edit_status').value = status;

    document.getElementById('edit_assigned_driver_id').value =
        assignedDriverId !== null ? assignedDriverId : '';

    document.getElementById('edit_or_number').value = orNumber ?? '';
    document.getElementById('edit_cr_number').value = crNumber ?? '';
    document.getElementById('edit_registration_expiry').value = registrationExpiry ?? '';

    document.getElementById('edit_maintenance_interval_km').value =
        maintenanceIntervalKm !== null && maintenanceIntervalKm !== undefined
            ? maintenanceIntervalKm
            : '';

    const modal = document.getElementById('editVehicleModal');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeEditVehicleModal() {

    const modal = document.getElementById('editVehicleModal');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>  

<script>
    function openVehicleModal() {
        const modal = document.getElementById('vehicleModal');

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.classList.add('ftms-modal-open');
    }

    function closeVehicleModal() {
        const modal = document.getElementById('vehicleModal');

        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.classList.remove('ftms-modal-open');
    }

    // Close when clicking outside the modal
    document.getElementById('vehicleModal').addEventListener('click', function(event) {
        if (event.target === this) {
            closeVehicleModal();
        }
    });

    // ---- File input previews (Add Vehicle documents) ----
    function ftmsPreviewImage(input, previewId) {
        const preview = document.getElementById(previewId);
        const file = input.files && input.files[0];

        if (!file) {
            preview.classList.add('hidden');
            preview.removeAttribute('src');
            return;
        }

        // PDFs don't render as <img>; just show a placeholder icon-free hint.
        if (file.type === 'application/pdf') {
            preview.classList.add('hidden');
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    }

    function ftmsPreviewMultiple(input, containerId) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        Array.from(input.files || []).forEach(file => {
            if (file.type === 'application/pdf') {
                const badge = document.createElement('div');
                badge.className = 'w-16 h-16 flex items-center justify-center text-xs text-slate-500 border border-slate-200 dark:border-slate-700 rounded-lg';
                badge.textContent = 'PDF';
                container.appendChild(badge);
                return;
            }

            const reader = new FileReader();
            reader.onload = e => {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'w-16 h-16 object-cover rounded-lg border border-slate-200 dark:border-slate-700';
                container.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    }
</script>

<!-- ================= VIEW MODAL HELPERS (Vehicle / Driver) ================= -->
<script>
function ftmsFallback(val) {
    if (val === null || val === undefined || val === '') return '—';
    return val;
}

function openViewVehicleModal(
    vehicleId, vehicleType, plateNumber, brand, model, yearModel, capacity, fuelType, odometer,
    status, driverName, orNumber, crNumber, registrationExpiry, lastMaintenanceDate, licenseFrontPath, licenseBackPath, otherDocuments,
    maintenanceIntervalKm, lastMaintenanceOdometer
) {
    document.getElementById('view_vehicle_title').textContent =
        plateNumber ? plateNumber + ' — Vehicle Details' : 'Vehicle Details';

    document.getElementById('view_vehicle_plate').textContent = ftmsFallback(plateNumber);
    document.getElementById('view_vehicle_type').textContent = ftmsFallback(vehicleType);
    document.getElementById('view_vehicle_brand').textContent = ftmsFallback(brand);
    document.getElementById('view_vehicle_model').textContent = ftmsFallback(model);
    document.getElementById('view_vehicle_year').textContent = ftmsFallback(yearModel);
    document.getElementById('view_vehicle_capacity').textContent = ftmsFallback(capacity);
    document.getElementById('view_vehicle_fuel').textContent = ftmsFallback(fuelType);
    document.getElementById('view_vehicle_odometer').textContent = ftmsFallback(odometer);
    document.getElementById('view_vehicle_status').textContent = ftmsFallback(status);
    document.getElementById('view_vehicle_driver').textContent = ftmsFallback(driverName) === '—' ? 'Unassigned' : driverName;
    document.getElementById('view_vehicle_or').textContent = ftmsFallback(orNumber);
    document.getElementById('view_vehicle_cr').textContent = ftmsFallback(crNumber);
    document.getElementById('view_vehicle_expiry').textContent = ftmsFallback(registrationExpiry);
    document.getElementById('view_vehicle_lastmaint').textContent = ftmsFallback(lastMaintenanceDate);

    const maintStatusEl = document.getElementById('view_vehicle_maint_status');
    if (maintenanceIntervalKm === null || maintenanceIntervalKm === undefined) {
        maintStatusEl.textContent = 'No interval set';
    } else {
        const nextDue = (lastMaintenanceOdometer || 0) + maintenanceIntervalKm;
        const remaining = nextDue - (odometer || 0);
        if (remaining <= 0) {
            maintStatusEl.textContent = 'Overdue (limit: ' + nextDue.toLocaleString() + ' km)';
        } else {
            maintStatusEl.textContent = remaining.toLocaleString() + ' km left (limit: ' + nextDue.toLocaleString() + ' km)';
        }
    }

    const frontImg = document.getElementById('view_vehicle_license_front');
    const backImg  = document.getElementById('view_vehicle_license_back');

    if (licenseFrontPath) {
        frontImg.src = licenseFrontPath;
        frontImg.classList.remove('hidden');
    } else {
        frontImg.classList.add('hidden');
    }

    if (licenseBackPath) {
        backImg.src = licenseBackPath;
        backImg.classList.remove('hidden');
    } else {
        backImg.classList.add('hidden');
    }

    const otherWrap = document.getElementById('view_vehicle_other_docs');
    otherWrap.innerHTML = '';
    let docs = [];
    try { docs = JSON.parse(otherDocuments || '[]'); } catch (e) {}
    docs.forEach(path => {
        const img = document.createElement('img');
        img.src = path;
        img.className = 'w-24 h-24 object-cover rounded-lg border cursor-pointer';
        img.onclick = () => window.open(path, '_blank');
        otherWrap.appendChild(img);
    });

    const modal = document.getElementById('viewVehicleModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeViewVehicleModal() {
    const modal = document.getElementById('viewVehicleModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}

function openViewDriverModal(
    driverId,
    employeeId,
    firstName,
    lastName,
    licenseNumber,
    licenseType,
    phone,
    email,
    address,
    designatedPlace,
    status
) {
    const fullName = [firstName, lastName].filter(Boolean).join(' ');

    document.getElementById('view_driver_title').textContent =
        fullName ? fullName + ' — Driver Details' : 'Driver Details';

    document.getElementById('view_driver_employee_id').textContent = ftmsFallback(employeeId);
    document.getElementById('view_driver_name').textContent = ftmsFallback(fullName);
    document.getElementById('view_driver_license_number').textContent = ftmsFallback(licenseNumber);
    document.getElementById('view_driver_license_type').textContent = ftmsFallback(licenseType);
    document.getElementById('view_driver_phone').textContent = ftmsFallback(phone);
    document.getElementById('view_driver_email').textContent = ftmsFallback(email);
    document.getElementById('view_driver_address').textContent = ftmsFallback(address);
    document.getElementById('view_driver_place').textContent = ftmsFallback(designatedPlace);
    document.getElementById('view_driver_status').textContent = ftmsFallback(status);

    const modal = document.getElementById('viewDriverModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.classList.add('ftms-modal-open');
}

function closeViewDriverModal() {
    const modal = document.getElementById('viewDriverModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.classList.remove('ftms-modal-open');
}
</script>
</section>