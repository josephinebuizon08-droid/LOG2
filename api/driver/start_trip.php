<?php

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
     SET status = 'In Transit'
     WHERE trip_id = $1
       AND driver_id = $2
       AND status IN ('Scheduled', 'Accepted')
     RETURNING trip_id, vehicle_id",
    [$tripId, $driverId]
);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to start trip.']);
    exit;
}

if (pg_num_rows($result) === 0) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'This trip could not be started. It may not be assigned to you or is already in progress/completed.'
    ]);
    exit;
}

$trip = pg_fetch_assoc($result);

if ($trip['vehicle_id'] !== null) {
        pg_query_params($conn, "UPDATE vehicles SET status = 'In Transit', assigned_driver_id = $1 WHERE vehicle_id = $2",
        [$driverId, (int) $trip['vehicle_id']] 
    );
} 

echo json_encode(['success' => true, 'message' => 'Trip started.', 'trip_id' => $tripId, 'status' => 'In Transit']);
