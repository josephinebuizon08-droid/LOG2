<?php
/**
 * POST /api/create_route.php
 *
 * Persists an applied AI route recommendation into `routes`, and — if the
 * request came from a trip in the Trip picker — links it back by setting
 * trips.route_id so the reservation/dispatch module sees it as routed.
 *
 * Expected JSON body (matches what optimize_route.php returns, plus trip_id):
 *   route_name, origin, destination, distance_km, estimated_fuel_l,
 *   duration_minutes, optimization, trip_id (optional)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/ftms_db.php';

/**
 * Geocode a free-text address via OSM Nominatim.
 * Nominatim usage policy: max ~1 req/sec, and a real User-Agent is required.
 * Returns ['lat' => float, 'lng' => float] or null if not found.
 */
function geocodeAddress(string $address): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $address,
        'format' => 'json',
        'limit'  => 1,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            // Required by Nominatim's usage policy — replace with your app name/contact.
            'User-Agent: FleetTMS/1.0 (contact@yourdomain.com)',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $results = json_decode($response, true);

    if (empty($results) || !isset($results[0]['lat'], $results[0]['lon'])) {
        return null;
    }

    return [
        'lat' => (float) $results[0]['lat'],
        'lng' => (float) $results[0]['lon'],
    ];
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$routeName   = trim($input['route_name']   ?? '');
$origin      = trim($input['origin']       ?? '');
$destination = trim($input['destination']  ?? '');
$distanceKm  = $input['distance_km']       ?? null;
$fuelL       = $input['estimated_fuel_l']  ?? null;
$durationMin = $input['duration_minutes']  ?? null;
$estCost     = $input['estimated_cost']    ?? null;
$traffic     = $input['traffic_level']     ?? null;
$tripId      = !empty($input['trip_id']) ? (int) $input['trip_id'] : null;
$vehicleId   = !empty($input['vehicle_id']) ? (int) $input['vehicle_id'] : null;

// Coordinates from Optimize Route (already geocoded there for the OSRM
// road-distance lookup) — reuse them here instead of geocoding again.
$passedOriginLat = is_numeric($input['origin_lat'] ?? null) ? (float) $input['origin_lat'] : null;
$passedOriginLng = is_numeric($input['origin_lng'] ?? null) ? (float) $input['origin_lng'] : null;
$passedDestLat   = is_numeric($input['destination_lat'] ?? null) ? (float) $input['destination_lat'] : null;
$passedDestLng   = is_numeric($input['destination_lng'] ?? null) ? (float) $input['destination_lng'] : null;

if ($origin === '' || $destination === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Origin and destination are required.']);
    exit;
}

if ($routeName === '') {
    $routeName = "$origin to $destination";
}

// Safety cap: route_name is a bounded column, and origin/destination can be
// full street addresses now, so never let this overflow regardless of what
// the client sent.
if (mb_strlen($routeName) > 200) {
    $routeName = mb_substr($routeName, 0, 197) . '...';
}

// Geocode origin and destination so the route can be plotted on the map —
// but only if Optimize Route didn't already hand us coordinates (routes
// applied straight from a trip with no Optimize step still need this).
// Nominatim asks for max ~1 request/sec, so we pace calls we actually make.
$haveOrigin = $passedOriginLat !== null && $passedOriginLng !== null;
$haveDest   = $passedDestLat !== null && $passedDestLng !== null;

$originCoords = $haveOrigin
    ? ['lat' => $passedOriginLat, 'lng' => $passedOriginLng]
    : geocodeAddress($origin);

if (!$haveOrigin && !$haveDest) {
    usleep(1100000); // ~1.1s between our own two Nominatim calls
}

$destinationCoords = $haveDest
    ? ['lat' => $passedDestLat, 'lng' => $passedDestLng]
    : geocodeAddress($destination);

pg_query($conn, 'BEGIN');

try {
    $routeRes = pg_query_params(
        $conn,
        "INSERT INTO routes
            (route_name, origin, destination, estimated_distance, estimated_duration,
             estimated_fuel_saved, estimated_cost, traffic_level,
             origin_lat, origin_lng, destination_lat, destination_lng, status)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, 'active')
         RETURNING route_id",
        [
            $routeName,
            $origin,
            $destination,
            $distanceKm,
            $durationMin,
            $fuelL,
            $estCost,
            $traffic,
            $originCoords['lat'] ?? null,
            $originCoords['lng'] ?? null,
            $destinationCoords['lat'] ?? null,
            $destinationCoords['lng'] ?? null,
        ]
    );

    if (!$routeRes) {
        throw new Exception('Failed to insert route: ' . pg_last_error($conn));
    }

    $route = pg_fetch_assoc($routeRes);
    $routeId = (int) $route['route_id'];

    // Link back to the trip, if this route was built from the Trip picker.
    if ($tripId) {
        $tripRes = pg_query_params(
            $conn,
            "UPDATE trips SET route_id = $1 WHERE trip_id = $2",
            [$routeId, $tripId]
        );

        if (!$tripRes) {
            throw new Exception('Failed to link route to trip: ' . pg_last_error($conn));
        }
    }

    pg_query($conn, 'COMMIT');

    echo json_encode([
        'success'  => true,
        'route_id' => $routeId,
        'route_name' => $routeName,
        'origin' => $origin,
        'destination' => $destination,
        'estimated_distance' => $distanceKm,
        'estimated_duration' => $durationMin,
        'estimated_cost' => $estCost,
        'traffic_level' => $traffic,
        'status' => 'active',
        'origin_lat' => $originCoords['lat'] ?? null,
        'origin_lng' => $originCoords['lng'] ?? null,
        'destination_lat' => $destinationCoords['lat'] ?? null,
        'destination_lng' => $destinationCoords['lng'] ?? null,
    ]);
} catch (Exception $e) {
    pg_query($conn, 'ROLLBACK');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}