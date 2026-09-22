<?php
/**
 * POST /api/optimize_route.php
 *
 * Accepts route form data, asks Gemini 3.5 Flash for a route recommendation,
 * and returns structured JSON the frontend can drop straight into the
 * "AI Route Recommendation" panel.
 *
 * Expected POST fields:
 *   route_name, origin, destination, vehicle_id (optional), optimization_type
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/ftms_db.php'; // gives us $conn if you want to pull vehicle info

// ---- 1. Read & validate input -------------------------------------------------
$routeName   = trim($_POST['route_name']   ?? '');
$origin      = trim($_POST['origin']       ?? '');
$destination = trim($_POST['destination']  ?? '');
$vehicleId   = $_POST['vehicle_id']        ?? null;
$optType     = $_POST['optimization_type'] ?? 'balanced';

if ($origin === '' || $destination === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Origin and destination are required.']);
    exit;
}

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

/**
 * Real driving distance/duration between two points via OSRM's free public
 * routing API — actual road-network calculation, not an LLM estimate.
 * Returns ['distance_km' => float, 'duration_minutes' => float] or null.
 */
function fetchRoadRoute(array $origin, array $destination): ?array
{
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false',
        $origin['lng'], $origin['lat'], $destination['lng'], $destination['lat']
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);

    if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
        return null;
    }

    return [
        'distance_km'      => $data['routes'][0]['distance'] / 1000, // meters -> km
        'duration_minutes' => $data['routes'][0]['duration'] / 60,   // seconds -> minutes
    ];
}

// Geocode both addresses so we can (a) get the real road distance/duration
// from OSRM instead of relying on Gemini's guess, and (b) pass coordinates
// through to Apply Route so it doesn't have to geocode again.
// Nominatim asks for max ~1 request/sec, so we pace the two calls.
$originCoords = geocodeAddress($origin);
usleep(1100000); // ~1.1s
$destinationCoords = geocodeAddress($destination);

$roadRoute = null;
if ($originCoords && $destinationCoords) {
    $roadRoute = fetchRoadRoute($originCoords, $destinationCoords);
}

// ---- 2. Optionally pull vehicle context so Gemini can factor it in -----------
$vehicleContext = 'No specific vehicle selected.';
if (!empty($vehicleId)) {
    $vres = pg_query_params(
        $conn,
        "SELECT plate_number, vehicle_type, brand, model, capacity FROM vehicles WHERE vehicle_id = $1",
        [$vehicleId]
    );
    if ($vres && $v = pg_fetch_assoc($vres)) {
        $vehicleContext = sprintf(
            '%s %s %s (capacity: %s), plate %s',
            $v['brand'], $v['model'], $v['vehicle_type'], $v['capacity'], $v['plate_number']
        );
    }
}

// ---- 3. Build the Gemini request ----------------------------------------------
$apiKey = getenv('GEMINI_API_KEY');

if (!$apiKey) {
    // fallback: config/gemini_config.php using define('GEMINI_API_KEY', '...')
    // (ftms_db.php is already required above, so if it defines the constant
    // there, this block is not even needed)
    $geminiConfigPath = __DIR__ . '/../config/gemini_config.php';
    if (file_exists($geminiConfigPath)) {
        require_once $geminiConfigPath;
    }
    if (defined('GEMINI_API_KEY')) {
        $apiKey = GEMINI_API_KEY;
    }
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini API key is not configured.']);
    exit;
}

$prompt = <<<PROMPT
You are a fleet route optimization assistant. Recommend the best route for a
delivery/fleet vehicle given the details below. Respond with your best real-world
estimate, factoring in the optimization priority requested.

Route name: {$routeName}
Origin: {$origin}
Destination: {$destination}
Vehicle: {$vehicleContext}
Optimization priority: {$optType}
PROMPT;

if ($roadRoute) {
    $prompt .= "\n\nThe actual road distance is " . round($roadRoute['distance_km'], 1)
        . " km with an estimated driving time of " . round($roadRoute['duration_minutes'])
        . " minutes (from real routing data) — use these exact figures rather than estimating your own.";
} else {
    $prompt .= "\n\nNo route-finding tools are available, so give your best reasoned"
        . " estimate of distance_km and duration_minutes based on typical road"
        . " distances and conditions between these locations.";
}

$prompt .= "\n\nAlso estimate the total trip cost in Philippine pesos (fuel + typical toll/"
    . "incidental costs for this vehicle and distance) and a traffic level"
    . " (\"Low\", \"Moderate\", or \"High\") typical for this route.\n\nReturn ONLY the recommendation.";

// Ask Gemini for structured JSON output directly (responseSchema),
// so we don't have to parse free text on the PHP side.
$requestBody = [
    'contents' => [[
        'role'  => 'user',
        'parts' => [['text' => $prompt]],
    ]],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
        'responseSchema'   => [
            'type'       => 'OBJECT',
            'properties' => [
                'summary'          => ['type' => 'STRING'],
                'distance_km'      => ['type' => 'NUMBER'],
                'duration_minutes' => ['type' => 'NUMBER'],
                'estimated_fuel_l' => ['type' => 'NUMBER'],
                'estimated_cost'   => ['type' => 'NUMBER'],
                'traffic_level'    => ['type' => 'STRING'],
                'optimization'     => ['type' => 'STRING'],
            ],
            'required' => ['summary', 'distance_km', 'duration_minutes', 'estimated_fuel_l', 'estimated_cost', 'traffic_level', 'optimization'],
        ],
        'thinkingConfig' => [
            'thinkingLevel' => 'low', // route summaries don't need deep reasoning
        ],
    ],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($requestBody),
    CURLOPT_TIMEOUT        => 20,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach Gemini API: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Gemini API returned an error.', 'details' => $response]);
    exit;
}

$data = json_decode($response, true);
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$text) {
    http_response_code(502);
    echo json_encode(['error' => 'Gemini API returned no content.']);
    exit;
}

$recommendation = json_decode($text, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not parse Gemini response as JSON.']);
    exit;
}

// ---- 4. Return to frontend -----------------------------------------------------
// Prefer OSRM's real road distance/duration over Gemini's estimate whenever
// we have it — everything else (cost, fuel, traffic, summary) still comes
// from Gemini since OSRM has no way to know those.
$distanceKm = $roadRoute
    ? round($roadRoute['distance_km'], 1)
    : round((float) $recommendation['distance_km'], 1);

$durationMinutes = $roadRoute
    ? round($roadRoute['duration_minutes'])
    : round((float) $recommendation['duration_minutes']);

echo json_encode([
    'success'          => true,
    'route_name'       => $routeName,
    'origin'           => $origin,
    'destination'      => $destination,
    'vehicle_id'       => $vehicleId,
    'summary'          => $recommendation['summary'],
    'distance_km'      => $distanceKm,
    'duration_minutes' => $durationMinutes,
    'distance_source'  => $roadRoute ? 'osrm' : 'ai_estimate',
    'estimated_fuel_l' => round((float) $recommendation['estimated_fuel_l'], 1),
    'estimated_cost'   => round((float) $recommendation['estimated_cost'], 2),
    'traffic_level'    => $recommendation['traffic_level'],
    'optimization'     => $recommendation['optimization'],
    'origin_lat'       => $originCoords['lat'] ?? null,
    'origin_lng'       => $originCoords['lng'] ?? null,
    'destination_lat'  => $destinationCoords['lat'] ?? null,
    'destination_lng'  => $destinationCoords['lng'] ?? null,
]);