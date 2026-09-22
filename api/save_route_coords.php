<?php
/**
 * api/save_route_coords.php
 *
 * Called by js/route_focus.js when a clicked route had no saved coordinates
 * and the address lookup found them. It fills in the missing origin OR
 * destination coordinates so the next click is instant and exact.
 *
 * It only ever fills empty (NULL) coordinates, it never overwrites existing ones.
 *
 * NOTE: add the same login/session check you use in api/create_route.php
 * if that file has one.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/ftms_db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT);
$side    = $input['side'] ?? '';
$lat     = filter_var($input['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng     = filter_var($input['lng'] ?? null, FILTER_VALIDATE_FLOAT);

if (
    !$routeId
    || !in_array($side, ['origin', 'destination'], true)
    || $lat === false || $lat === null || $lat < -90  || $lat > 90
    || $lng === false || $lng === null || $lng < -180 || $lng > 180
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid route id, side or coordinates.']);
    exit;
}

// $side is whitelisted above, so these column names are safe to use directly.
$latCol = $side . '_lat';
$lngCol = $side . '_lng';

$result = pg_query_params(
    $conn,
    "UPDATE routes
        SET $latCol = $1, $lngCol = $2
      WHERE route_id = $3
        AND ($latCol IS NULL OR $lngCol IS NULL)",
    [$lat, $lng, $routeId]
);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
}

echo json_encode(['success' => true, 'updated' => pg_affected_rows($result)]);