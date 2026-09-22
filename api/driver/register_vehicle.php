<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);


header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

function respond(bool $success, string $message, array $extra = []): void {
    http_response_code($success ? 200 : 400);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Only POST requests are allowed.');
}

// Same check accept_trip.php uses: resolves (and validates) the driver
// from the Authorization: Bearer token. api_authenticate_driver() exits
// with its own 401 response if the token is missing/invalid, so by the
// time we get here $auth['driver_id'] is trustworthy.
$auth = api_authenticate_driver($conn);
$driver_id = $auth['driver_id'];

// --- Read fields -----------------------------------------------------
$plate_number = trim($_POST['plate_number'] ?? '');
$vehicle_type = trim($_POST['vehicle_type'] ?? '');
$brand        = trim($_POST['brand'] ?? '');
$model        = trim($_POST['model'] ?? '');
$year_model   = ($_POST['year_model'] ?? '') !== '' ? (int) $_POST['year_model'] : null;
$capacity     = ($_POST['capacity'] ?? '') !== '' ? (float) $_POST['capacity'] : null;
$fuel_type    = trim($_POST['fuel_type'] ?? '');
$odometer     = ($_POST['odometer'] ?? '') !== '' ? (float) $_POST['odometer'] : 0;
$status       = trim($_POST['status'] ?? '') !== '' ? trim($_POST['status']) : 'Available';
$or_number    = trim($_POST['or_number'] ?? '');
$cr_number    = trim($_POST['cr_number'] ?? '');
$registration_expiry = trim($_POST['registration_expiry'] ?? '');

if ($plate_number === '' || $vehicle_type === '') {
    respond(false, 'Plate Number and Vehicle Type are required.');
}

if (empty($_FILES['license_front']) || empty($_FILES['license_back'])) {
    respond(false, "Both sides of the driver's license are required.");
}

// Same duplicate-plate check the web Add Vehicle form relies on.
$dup = pg_query_params(
    $conn,
    "SELECT vehicle_id FROM vehicles WHERE plate_number = $1 LIMIT 1",
    [$plate_number]
);
if ($dup && pg_num_rows($dup) > 0) {
    respond(false, 'A vehicle with plate number "' . htmlspecialchars($plate_number) . '" already exists.');
}

// --- Save uploaded files ---------------------------------------------
$uploadDir = __DIR__ . '/../../uploads/vehicle_documents/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    respond(false, 'Server storage is not writable. Please try again later.');
}

function save_upload(
    array $file,
    string $uploadDir,
    string $prefix,
    array $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'heic'],
    bool $requireExt = false
): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (($requireExt && $ext === '') || ($ext !== '' && !in_array($ext, $allowed, true))) {
        return null;
    }
    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return null;
    }
    // Stored relative to the public uploads root; adjust to match
    // wherever your app actually serves /uploads from.
    return 'uploads/vehicle_documents/' . $filename;
}

$licenseFrontPath = save_upload($_FILES['license_front'], $uploadDir, 'license_front');
$licenseBackPath  = save_upload($_FILES['license_back'], $uploadDir, 'license_back');

if (!$licenseFrontPath || !$licenseBackPath) {
    respond(false, 'Unable to save the license photos. Please try again.');
}

// "Other documents" are files only (no images). Keep in sync with
// _otherDocExtensions in vehicle_registration_screen.dart.
$otherDocAllowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

$otherDocumentPaths = [];
if (!empty($_FILES['other_documents']['name'])) {
    $count = count($_FILES['other_documents']['name']);
    for ($i = 0; $i < $count; $i++) {
        $file = [
            'name'     => $_FILES['other_documents']['name'][$i],
            'tmp_name' => $_FILES['other_documents']['tmp_name'][$i],
            'error'    => $_FILES['other_documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        ];
        $path = save_upload($file, $uploadDir, 'other', $otherDocAllowed, true);
        if ($path) {
            $otherDocumentPaths[] = $path;
        }
    }
}

// --- Insert, tied to the authenticated driver -------------------------
$sql = "
    INSERT INTO vehicles (
        plate_number, vehicle_type, brand, model, year_model, capacity,
        fuel_type, odometer, status, assigned_driver_id, or_number,
        cr_number, registration_expiry, license_front_path,
        license_back_path, other_documents
    )
    VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)
    RETURNING vehicle_id
";

$result = pg_query_params($conn, $sql, [
    $plate_number,
    $vehicle_type,
    $brand !== '' ? $brand : null,
    $model !== '' ? $model : null,
    $year_model,
    $capacity,
    $fuel_type !== '' ? $fuel_type : null,
    $odometer,
    $status,
    $driver_id,
    $or_number !== '' ? $or_number : null,
    $cr_number !== '' ? $cr_number : null,
    $registration_expiry !== '' ? $registration_expiry : null,
    $licenseFrontPath,
    $licenseBackPath,
    json_encode($otherDocumentPaths),
]);

if (!$result) {
    $error = pg_last_error($conn);

    if (strpos($error, 'vehicles_plate_number_key') !== false) {
        respond(false, 'A vehicle with plate number "' . htmlspecialchars($plate_number) . '" already exists.');
    }

    respond(false, 'Unable to register vehicle. Please check the information and try again.');
}

$vehicleId = (int) pg_fetch_result($result, 0, 'vehicle_id');

respond(true, 'Vehicle registered successfully.', ['vehicle_id' => $vehicleId]);