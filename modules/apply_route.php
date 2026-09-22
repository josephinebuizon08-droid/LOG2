<?php
/**
 * POST /modules/apply_route.php
 *
 * Saves an AI-recommended (or manually planned) route into the `routes`
 * table. If the plan was built from a pending trip (Reservation & Dispatch),
 * links that trip to the new route so it drops off the "awaiting a route"
 * list automatically.
 *
 * Expected POST fields:
 *   route_name, origin, destination, vehicle_id (optional), trip_id (optional),
 *   distance_km, duration_minutes, estimated_fuel_l, estimated_cost,
 *   traffic_level, optimization
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config/ftms_db.php';

$routeName   = trim($_POST['route_name']   ?? '');
$origin      = trim($_POST['origin']       ?? '');
$destination = trim($_POST['destination']  ?? '');
$tripId      = !empty($_POST['trip_id']) ? (int) $_POST['trip_id'] : null;

$distanceKm  = isset($_POST['distance_km'])       && $_POST['distance_km'] !== ''       ? (float) $_POST['distance_km']       : null;
$durationMin = isset($_POST['duration_minutes'])  && $_POST['duration_minutes'] !== ''  ? (int)   $_POST['duration_minutes']  : null;
$estCost     = isset($_POST['estimated_cost'])    && $_POST['estimated_cost'] !== ''    ? (float) $_POST['estimated_cost']    : null;
$traffic     = trim($_POST['traffic_level'] ?? '') ?: null;

if ($routeName === '' || $origin === '' || $destination === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Route name, origin, and destination are required.']);
    exit;
}

// ---- Save the route -------------------------------------------------------
$insert = pg_query_params(
    $conn,
    "INSERT INTO routes
        (route_name, origin, destination, estimated_distance, estimated_duration,
         estimated_cost, traffic_level, status)
     VALUES ($1, $2, $3, $4, $5, $6, $7, 'active')
     RETURNING route_id",
    [$routeName, $origin, $destination, $distanceKm, $durationMin, $estCost, $traffic]
);

if (!$insert || !($row = pg_fetch_assoc($insert))) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save the route.', 'details' => pg_last_error($conn)]);
    exit;
}

$routeId = (int) $row['route_id'];

// ---- Link the route to its source trip, if any -----------------------------
// This is what makes the trip drop off "Trips awaiting a route" in the
// Build Route panel, and is what the Reservation & Dispatch module needs
// to know this trip now has a route.
if ($tripId) {
    pg_query_params($conn, "UPDATE trips SET route_id = $1 WHERE trip_id = $2", [$routeId, $tripId]);
}

// ---- Respond with everything the table row needs ----------------------------
echo json_encode([
    'success'          => true,
    'route_id'         => $routeId,
    'route_name'       => $routeName,
    'origin'           => $origin,
    'destination'      => $destination,
    'distance_km'      => $distanceKm,
    'duration_minutes' => $durationMin,
    'estimated_cost'   => $estCost,
    'traffic_level'    => $traffic,
    'status'           => 'active',
]);