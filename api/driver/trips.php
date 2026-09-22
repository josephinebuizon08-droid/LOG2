<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);
    exit;
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

$singleTripId = isset($_GET['id']) && $_GET['id'] !== ''
    ? (int) $_GET['id']
    : null;

$historyMode = isset($_GET['history'])
    && $_GET['history'] === '1';

$baseSelect = "
    SELECT
        t.trip_id,
        t.reservation_id AS shipment_id,
        t.driver_id,
        t.vehicle_id,
        t.route_id,
        t.departure_time,
        t.arrival_time,
        t.status,
        t.accepted_at,

        COALESCE(rt.origin, res.pickup_location) AS origin,
        COALESCE(rt.destination, res.destination) AS destination,

        rt.origin_lat,
        rt.origin_lng,
        rt.destination_lat,
        rt.destination_lng,
        rt.estimated_distance,
        rt.estimated_duration,

        v.plate_number,
        v.vehicle_type,
        v.brand AS vehicle_brand,
        v.model AS vehicle_model,

        res.requestor,
        res.consignor_address AS department,
        res.purpose

    FROM trips t

    LEFT JOIN routes rt
        ON rt.route_id = t.route_id

    LEFT JOIN reservations res
        ON res.reservation_id = t.reservation_id

    LEFT JOIN vehicles v
        ON v.vehicle_id = t.vehicle_id
";

if ($singleTripId !== null) {

    $sql = $baseSelect . "
        WHERE t.trip_id = $1
          AND t.driver_id = $2
        LIMIT 1
    ";

    $result = pg_query_params(
        $conn,
        $sql,
        [$singleTripId, $driverId]
    );

} elseif ($historyMode) {

    /*
     * RIDING LOGS
     *
     * Only completed trips of the
     * currently authenticated driver.
     */
    $sql = $baseSelect . "
        WHERE t.driver_id = $1
          AND t.status = 'Completed'
        ORDER BY
            t.arrival_time DESC NULLS LAST,
            t.trip_id DESC
    ";

    $result = pg_query_params(
        $conn,
        $sql,
        [$driverId]
    );

} else {

    /*
     * ACTIVE / ASSIGNED TRIPS
     *
     * Completed trips are excluded here because
     * they belong in Riding Logs.
     */
    $sql = $baseSelect . "
        WHERE t.driver_id = $1
          AND t.status NOT IN (
              'Completed',
              'Cancelled',
              'Archived'
          )
        ORDER BY
            t.departure_time ASC NULLS LAST,
            t.trip_id DESC
    ";

    $result = pg_query_params(
        $conn,
        $sql,
        [$driverId]
    );
}

if (!$result) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to load trips.'
    ]);

    exit;
}

function format_trip_row(array $row): array
{
    return [
        'trip_id' => (int) $row['trip_id'],

        'shipment_id' =>
            $row['shipment_id'] !== null
                ? (int) $row['shipment_id']
                : null,

        'driver_id' => (int) $row['driver_id'],

        'vehicle_id' =>
            $row['vehicle_id'] !== null
                ? (int) $row['vehicle_id']
                : null,

        'origin' => $row['origin'],

        'destination' => $row['destination'],

        'origin_lat' =>
            $row['origin_lat'] !== null
                ? (float) $row['origin_lat']
                : null,

        'origin_lng' =>
            $row['origin_lng'] !== null
                ? (float) $row['origin_lng']
                : null,

        'destination_lat' =>
            $row['destination_lat'] !== null
                ? (float) $row['destination_lat']
                : null,

        'destination_lng' =>
            $row['destination_lng'] !== null
                ? (float) $row['destination_lng']
                : null,

        'scheduled_at' => $row['departure_time'],

        'arrival_time' => $row['arrival_time'],

        'status' => $row['status'],

        'accepted_at' => $row['accepted_at'],

        'distance_km' =>
            $row['estimated_distance'] !== null
                ? (float) $row['estimated_distance']
                : null,

        'estimated_minutes' =>
            $row['estimated_duration'] !== null
                ? (int) $row['estimated_duration']
                : null,

        'vehicle' =>
            $row['vehicle_id'] !== null
                ? [
                    'vehicle_id' => (int) $row['vehicle_id'],
                    'plate_number' => $row['plate_number'],
                    'vehicle_type' => $row['vehicle_type'],
                    'brand' => $row['vehicle_brand'],
                    'model' => $row['vehicle_model'],
                ]
                : null,

        'shipment' =>
            $row['shipment_id'] !== null
                ? [
                    'shipment_id' => (int) $row['shipment_id'],
                    'requestor' => $row['requestor'],
                    'department' => $row['department'],
                    'purpose' => $row['purpose'],
                ]
                : null,
    ];
}

if ($singleTripId !== null) {

    if (pg_num_rows($result) === 0) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Trip not found.'
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'trip' => format_trip_row(
            pg_fetch_assoc($result)
        )
    ]);

    exit;
}

$trips = [];

while ($row = pg_fetch_assoc($result)) {
    $trips[] = format_trip_row($row);
}

echo json_encode([
    'success' => true,
    'trips' => $trips
]);

exit;