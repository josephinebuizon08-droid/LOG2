<?php
/**
 * POST /api/driver/complete_trip.php
 * Body: { "trip_id": 1001 }
 *
 * In Transit -> Completed. Mirrors modules/reservation.php's "Complete
 * Dispatch" action exactly: sets arrival_time, frees the vehicle back to
 * Available, and marks the linked reservation Completed. After this
 * returns success, the Flutter app should stop sending location updates
 * for this trip (see Part 7 / trip status flow).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$tripId = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;

if ($tripId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'trip_id is required.']);
    exit;
}

$result = pg_query_params(
    $conn,
    "UPDATE trips
     SET status = 'Completed', arrival_time = CURRENT_TIMESTAMP
     WHERE trip_id = $1
       AND driver_id = $2
       AND status = 'In Transit'
     RETURNING trip_id, vehicle_id, reservation_id",
    [$tripId, $driverId]
);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to complete trip.']);
    exit;
}

if (pg_num_rows($result) === 0) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'This trip could not be completed. It may not be assigned to you or is not currently in progress.'
    ]);
    exit;
}

$trip = pg_fetch_assoc($result);

if ($trip['vehicle_id'] !== null) {
    pg_query_params(
        $conn,
        "UPDATE vehicles SET status = 'Available' WHERE vehicle_id = $1",
        [(int) $trip['vehicle_id']]
    );
}

if ($trip['reservation_id'] !== null) {
    pg_query_params(
        $conn,
        "UPDATE reservations SET status = 'Completed' WHERE reservation_id = $1",
        [(int) $trip['reservation_id']]
    );
}

echo json_encode(['success' => true, 'message' => 'Trip completed.', 'trip_id' => $tripId, 'status' => 'Completed']);
