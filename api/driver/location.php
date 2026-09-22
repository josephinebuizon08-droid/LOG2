<?php
/**
 * POST /api/driver/location.php
 * Body: {
 *   "trip_id": 1001,
 *   "latitude": 14.6760,
 *   "longitude": 121.0437,
 *   "accuracy": 8.5,
 *   "speed": 32.4,
 *   "heading": 180
 * }
 *
 * Validates, in order:
 *   1. Authenticated driver (token -> driver_id, never trusted from body)
 *   2. trip_id belongs to that driver
 *   3. trip is currently 'In Transit' (no updates accepted before Start or
 *      after Complete)
 *   4. latitude/longitude are numeric and in valid range
 *
 * On success: inserts a row into driver_locations (history, for route
 * replay / distance traveled / reports) AND updates
 * vehicles.current_lat/current_lng/last_location_update (the existing
 * "latest location" fields already read by modules/dashboard.php's fleet
 * map and by the new /api/live/drivers.php endpoint) in the same request,
 * so both the history table and the fast "latest" lookup stay in sync.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

function respond(int $httpStatus, array $body): void {
    http_response_code($httpStatus);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$tripId = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;
if ($tripId <= 0) {
    respond(400, ['success' => false, 'message' => 'trip_id is required.']);
}

if (!isset($input['latitude']) || !isset($input['longitude']) ||
    !is_numeric($input['latitude']) || !is_numeric($input['longitude'])) {
    respond(400, ['success' => false, 'message' => 'latitude and longitude are required and must be numeric.']);
}

$lat = (float) $input['latitude'];
$lng = (float) $input['longitude'];

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond(400, ['success' => false, 'message' => 'latitude must be between -90 and 90, longitude between -180 and 180.']);
}

$accuracy = isset($input['accuracy']) && is_numeric($input['accuracy']) ? (float) $input['accuracy'] : null;
$speed    = isset($input['speed'])    && is_numeric($input['speed'])    ? (float) $input['speed']    : null;
$heading  = isset($input['heading'])  && is_numeric($input['heading'])  ? (float) $input['heading']  : null;

// Confirm this trip is (a) actually assigned to the authenticated driver
// and (b) currently in progress. This is the check that stops a driver
// from sending location for a trip that hasn't started yet, has already
// been completed, or belongs to someone else.
$tripCheck = pg_query_params(
    $conn,
    "SELECT trip_id, vehicle_id, status FROM trips WHERE trip_id = $1 AND driver_id = $2 LIMIT 1",
    [$tripId, $driverId]
);

if (!$tripCheck || pg_num_rows($tripCheck) === 0) {
    respond(403, ['success' => false, 'message' => 'This trip is not assigned to you.']);
}

$trip = pg_fetch_assoc($tripCheck);

if ($trip['status'] !== 'In Transit') {
    respond(409, ['success' => false, 'message' => 'Location updates are only accepted while a trip is In Transit. Current status: ' . $trip['status']]);
}

// ---- Insert history record ----
$insert = pg_query_params(
    $conn,
    "INSERT INTO driver_locations (driver_id, trip_id, latitude, longitude, accuracy, speed, heading)
     VALUES ($1, $2, $3, $4, $5, $6, $7)",
    [$driverId, $tripId, $lat, $lng, $accuracy, $speed, $heading]
);

if (!$insert) {
    respond(500, ['success' => false, 'message' => 'Failed to save location.']);
}

// ---- Keep the vehicle's "latest location" fields in sync ----
// Reuses the exact columns update_vehicle_location.php already writes, so
// the existing fleet map query in modules/dashboard.php picks this up
// without any change on that side.
if ($trip['vehicle_id'] !== null) {
    pg_query_params(
        $conn,
        "UPDATE vehicles
         SET current_lat = $1, current_lng = $2, last_location_update = CURRENT_TIMESTAMP
         WHERE vehicle_id = $3",
        [$lat, $lng, (int) $trip['vehicle_id']]
    );
}

respond(200, ['success' => true, 'message' => 'Location updated successfully']);
