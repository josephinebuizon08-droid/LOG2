<?php
/**
 * GET /api/track_shipment.php?tracking_number=TRP-1234
 *
 * Public shipment-tracking lookup — no login required, same as any
 * customer-facing "track your order" page. Builds a checkpoint-style
 * timeline out of data that already exists (no schema changes):
 *   - reservations.create_at        -> "Order Received"
 *   - trips.departure_time          -> "Trip Scheduled"
 *   - trips.accepted_at             -> "Driver Assigned"
 *   - driver_locations (first row)  -> "In Transit" (+ latest ping = current position)
 *   - trips.arrival_time            -> "Delivered"
 *
 * trips.status stays the source of truth for which checkpoints are
 * actually "done" — see trips_status_check in the schema:
 *   Scheduled | Accepted | In Transit | Delayed | Completed | Cancelled | Archived
 *
 * Accepts the tracking number as "TRP-1234" or a bare "1234" (maps to
 * trip_id). Swap that lookup for a real tracking_code column later if
 * you add one — everything else here stays the same.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/ftms_db.php';

function respond(bool $success, string $message, array $extra = []): void {
    http_response_code($success ? 200 : 404);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    respond(false, 'Only GET requests are allowed.');
}

$raw = trim($_GET['tracking_number'] ?? '');
if ($raw === '') {
    http_response_code(400);
    respond(false, 'tracking_number is required.');
}

// "TRP-1234" -> 1234, or accept a bare number
$tripId = (int) preg_replace('/[^0-9]/', '', $raw);
if ($tripId <= 0) {
    respond(false, 'Shipment not found.');
}

$tripRes = pg_query_params(
    $conn,
    "SELECT
        t.trip_id, t.status, t.departure_time, t.arrival_time, t.accepted_at,
        t.created_at, t.destination_lat, t.destination_lng,
        v.plate_number,
        d.first_name, d.last_name,
        r.reservation_id, r.pickup_location, r.destination, r.create_at AS reservation_created_at
     FROM trips t
     LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN drivers d ON d.driver_id = t.driver_id
     LEFT JOIN reservations r ON r.reservation_id = t.reservation_id
     WHERE t.trip_id = $1
     LIMIT 1",
    [$tripId]
);

if (!$tripRes || pg_num_rows($tripRes) === 0) {
    respond(false, 'Shipment not found.');
}

$trip = pg_fetch_assoc($tripRes);
$status = $trip['status'];

// Order of trips_status_check values that represent forward progress.
// 'Delayed' and 'Cancelled' are reported separately (see $isDelayed/$isCancelled)
// rather than as a step in this list, since they don't move the shipment forward.
$progressOrder = ['Scheduled', 'Accepted', 'In Transit', 'Completed'];
$currentIndex  = array_search($status, $progressOrder, true);
$isCancelled   = $status === 'Cancelled';
$isDelayed     = $status === 'Delayed';
// 'Delayed'/'Archived' still count as "at least In Transit" for checkpoint purposes.
if ($currentIndex === false && in_array($status, ['Delayed', 'Archived'], true)) {
    $currentIndex = array_search('In Transit', $progressOrder, true);
}

function step_done(int $stepIndex, $currentIndex): bool {
    return $currentIndex !== false && $currentIndex >= $stepIndex;
}

// First and most recent GPS ping for this trip, if the driver has sent any.
$firstPingRes = pg_query_params(
    $conn,
    "SELECT latitude, longitude, recorded_at FROM driver_locations
     WHERE trip_id = $1 ORDER BY recorded_at ASC LIMIT 1",
    [$tripId]
);
$firstPing = $firstPingRes && pg_num_rows($firstPingRes) > 0 ? pg_fetch_assoc($firstPingRes) : null;

$lastPingRes = pg_query_params(
    $conn,
    "SELECT latitude, longitude, recorded_at FROM driver_locations
     WHERE trip_id = $1 ORDER BY recorded_at DESC LIMIT 1",
    [$tripId]
);
$lastPing = $lastPingRes && pg_num_rows($lastPingRes) > 0 ? pg_fetch_assoc($lastPingRes) : null;

$driverName = $trip['first_name'] ? trim($trip['first_name'] . ' ' . $trip['last_name']) : null;

$events = [
    [
        'label'    => 'Order Received',
        'location' => $trip['pickup_location'] ?? '—',
        'timestamp' => $trip['reservation_created_at'] ?? $trip['created_at'],
        'done'     => true, // the trip row existing means this always happened
    ],
    [
        'label'    => 'Trip Scheduled',
        'location' => $trip['pickup_location'] ?? '—',
        'timestamp' => $trip['departure_time'],
        'done'     => step_done(0, $currentIndex),
    ],
    [
        'label'    => 'Driver Assigned' . ($driverName ? " ({$driverName})" : ''),
        'location' => $trip['plate_number'] ? "Vehicle {$trip['plate_number']}" : '—',
        'timestamp' => $trip['accepted_at'],
        'done'     => step_done(1, $currentIndex),
    ],
    [
        'label'    => 'In Transit',
        'location' => $firstPing ? "{$firstPing['latitude']}, {$firstPing['longitude']}" : ($trip['pickup_location'] ?? '—'),
        'timestamp' => $firstPing['recorded_at'] ?? null,
        'done'     => step_done(2, $currentIndex),
    ],
    [
        'label'    => 'Delivered',
        'location' => $trip['destination'] ?? '—',
        'timestamp' => $trip['arrival_time'],
        'done'     => step_done(3, $currentIndex),
    ],
];

respond(true, 'Shipment found.', [
    'tracking_number' => 'TRP-' . $trip['trip_id'],
    'status'          => $status,
    'delayed'         => $isDelayed,
    'cancelled'       => $isCancelled,
    'current_location' => $lastPing ? [
        'latitude'  => (float) $lastPing['latitude'],
        'longitude' => (float) $lastPing['longitude'],
        'updated_at' => $lastPing['recorded_at'],
    ] : null,
    'events' => $events,
]);