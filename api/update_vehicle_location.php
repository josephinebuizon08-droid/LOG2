<?php
/**
 * POST /api/update_vehicle_location.php
 *
 * Legacy server-to-server location endpoint, kept for external tracking
 * hardware/subsystems that authenticate with FTMS_API_KEY instead of a
 * per-driver token (see api/driver/location.php for the mobile-app path).
 *
 * FIX: previously this endpoint would write current_lat/current_lng to
 * ANY vehicle_id/plate_number as long as the caller had the shared API
 * key — with no check that the vehicle actually had an active trip. That
 * let a stale/mistaken/test call permanently "place" a vehicle on the
 * live map (e.g. showing a driver's location on a vehicle that is only
 * Reserved and has no one actually driving it yet). This now requires
 * the target vehicle to have a trip with status = 'In Transit' before
 * accepting a location write, matching the same rule api/driver/location.php
 * already enforces for the mobile app.
 */
require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/../config/api_config.php';

header('Content-Type: application/json');

function respond(int $httpStatus, array $body): void {
    http_response_code($httpStatus);
    echo json_encode($body);
    exit;
}

// ---- Method check ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Only POST is allowed.']);
}

// ---- Auth check ----
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($providedKey === '' || !hash_equals(FTMS_API_KEY, $providedKey)) {
    respond(401, ['success' => false, 'error' => 'Missing or invalid API key.']);
}

// ---- Parse body (accept JSON or standard form POST) ----
$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}

// ---- Validate lat/lng ----
if (!isset($input['lat']) || !isset($input['lng']) ||
    !is_numeric($input['lat']) || !is_numeric($input['lng'])) {
    respond(400, ['success' => false, 'error' => 'lat and lng are required and must be numeric.']);
}

$lat = (float) $input['lat'];
$lng = (float) $input['lng'];

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond(400, ['success' => false, 'error' => 'lat must be between -90 and 90, lng between -180 and 180.']);
}

// ---- Identify the vehicle (vehicle_id OR plate_number) ----
$vehicleId = isset($input['vehicle_id']) && $input['vehicle_id'] !== '' ? (int) $input['vehicle_id'] : null;
$plateNumber = isset($input['plate_number']) ? trim((string) $input['plate_number']) : '';

if ($vehicleId === null && $plateNumber === '') {
    respond(400, ['success' => false, 'error' => 'Provide either vehicle_id or plate_number.']);
}

// ---- Resolve the target vehicle first (by id or plate) ----
if ($vehicleId !== null) {
    $vehicleRes = pg_query_params(
        $conn,
        "SELECT vehicle_id FROM vehicles WHERE vehicle_id = $1",
        [$vehicleId]
    );
} else {
    $vehicleRes = pg_query_params(
        $conn,
        "SELECT vehicle_id FROM vehicles WHERE UPPER(plate_number) = UPPER($1)",
        [$plateNumber]
    );
}

if ($vehicleRes === false) {
    respond(500, ['success' => false, 'error' => 'Database error while looking up vehicle.']);
}

if (pg_num_rows($vehicleRes) === 0) {
    respond(404, ['success' => false, 'error' => 'Vehicle not found.']);
}

$vehicleId = (int) pg_fetch_assoc($vehicleRes)['vehicle_id'];

// ---- Require an active (In Transit) trip on this vehicle ----
// Without this, a stray/old/mistaken call to this endpoint can permanently
// stamp coordinates onto a vehicle that is only Reserved/Available, which
// then shows up on the dashboard's live map as if someone were driving it.
$tripCheck = pg_query_params(
    $conn,
    "SELECT 1 FROM trips WHERE vehicle_id = $1 AND status = 'In Transit' LIMIT 1",
    [$vehicleId]
);

if ($tripCheck === false) {
    respond(500, ['success' => false, 'error' => 'Database error while checking trip status.']);
}

if (pg_num_rows($tripCheck) === 0) {
    respond(409, [
        'success' => false,
        'error'   => 'This vehicle has no trip currently In Transit. Location updates are only accepted for vehicles actively on a trip.',
    ]);
}

// ---- Update ----
$sql = "
    UPDATE vehicles
    SET current_lat = $1, current_lng = $2, last_location_update = CURRENT_TIMESTAMP
    WHERE vehicle_id = $3
    RETURNING vehicle_id, plate_number, current_lat, current_lng, last_location_update
";
$result = @pg_query_params($conn, $sql, [$lat, $lng, $vehicleId]);

if ($result === false) {
    respond(500, ['success' => false, 'error' => 'Database error while updating location.']);
}

if (pg_num_rows($result) === 0) {
    respond(404, ['success' => false, 'error' => 'Vehicle not found.']);
}

$row = pg_fetch_assoc($result);

respond(200, [
    'success'              => true,
    'vehicle_id'           => (int) $row['vehicle_id'],
    'plate_number'         => $row['plate_number'],
    'current_lat'          => (float) $row['current_lat'],
    'current_lng'          => (float) $row['current_lng'],
    'last_location_update' => $row['last_location_update'],
]);