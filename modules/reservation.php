<?php

require_once __DIR__ . '/UI_helpers.php';
require_once __DIR__ . '/../config/ftms_db.php';
 
 
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
 
// Guarded with function_exists() because this module can end up required
// alongside other modules (e.g. fleet.php) that declare the same
// session-token helpers — without the guard, loading both in one request
// throws a fatal "Cannot redeclare" error.
if (!function_exists('vrds_new_form_token')) {
    function vrds_new_form_token(): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['vrds_tokens'][$token] = true;
        return $token;
    }
}

if (!function_exists('vrds_consume_form_token')) {
    function vrds_consume_form_token(?string $token): bool
    {
        if (!$token || empty($_SESSION['vrds_tokens'][$token])) {
            return false;
        }
        unset($_SESSION['vrds_tokens'][$token]);
        return true;
    }
}

if (!function_exists('vrds_redirect_with_message')) {
    function vrds_redirect_with_message(string $message, string $type = 'info'): void
    {
        $_SESSION['vrds_flash_message'] = $message;
        $_SESSION['vrds_flash_type'] = $type;

        $path = strtok($_SERVER['REQUEST_URI'], '?');
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $location = $queryString !== '' ? $path . '?' . $queryString : $path;

        if (!headers_sent()) {
            // Normal, clean redirect.
            header('Location: ' . $location);
            exit;
        }

        // Fallback: something upstream (e.g. sidebar.php) already echoed output
        // before this ran, so a real HTTP redirect is no longer possible.
        // A meta-refresh + JS redirect still achieves the same PRG effect —
        // the next request is a GET, so refreshing THAT page is safe.
        $safeLocation = htmlspecialchars($location, ENT_QUOTES);
        echo '<meta http-equiv="refresh" content="0;url=' . $safeLocation . '">';
        echo '<script>window.location.replace(' . json_encode($location) . ');</script>';
        echo '<p>Redirecting… <a href="' . $safeLocation . '">Click here if you are not redirected.</a></p>';
        exit;
    }
}

if (!function_exists('vrds_format_address')) {
    // Joins the Consignee's separate address fields (street, city, state,
    // zip) into one line, so it can be dropped straight into the Trip
    // section's "Destination" the same way Consignor's single Address
    // field is used directly as "Pickup Location". Empty parts are
    // skipped so you don't get stray ", ," when city/state/zip are blank.
    function vrds_format_address(string $address, string $city = '', string $state = '', string $zip = ''): string
    {
        $line2 = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state . ' ' . $zip);
        $parts = array_filter([trim($address), $line2], fn($p) => $p !== '');
        return implode(', ', $parts);
    }
}

if (!function_exists('vrds_geocode_address')) {
    // Geocodes a free-text address via OSM Nominatim (same approach already
    // used by api/optimize_route.php for route planning). Best-effort only:
    // returns null on any failure so callers can just leave lat/lng blank
    // rather than blocking the reservation from being saved.
    function vrds_geocode_address(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

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
            CURLOPT_TIMEOUT => 8,
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
}

if (!function_exists('vrds_haversine_km')) {
    // Straight-line ("as the crow flies") distance in km between two
    // lat/lng points. Good enough for ranking "who's closest" without the
    // cost/rate-limit of an extra road-routing API call per driver.
    function vrds_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }
}

if (!function_exists('vrds_nearby_active_drivers')) {
    // "Available" drivers: active status AND not already tied up on a
    // scheduled/in-transit trip (previously the driver dropdowns only
    // checked status='active', so a driver already out on another trip
    // could still be picked here).
    //
    // "Designated to this shipment": when a pickup or destination address
    // is passed in, drivers are further restricted to those whose
    // drivers.designated_place is a match against either address (simple
    // case-insensitive substring match, since designated_place is a
    // freeform city/area name like "Pasig" or "Quezon City" that normally
    // appears somewhere inside the full pickup/destination text). Drivers
    // with no designated_place set, or whose designated_place matches
    // neither address, are excluded entirely -- this is intentionally
    // strict per how dispatch is meant to work: only drivers designated to
    // a location should ever be assignable to a shipment going to/from it.
    // Pass both $pickupLocation and $destination as null to skip this
    // filter (used for the "no reservation chosen yet" default list).
    //
    // "Near": each driver's last known position, taken from the vehicle
    // they're normally assigned to (vehicles.current_lat/current_lng --
    // the same "latest location" columns the fleet map already reads).
    // A driver with no assigned vehicle or no location fix yet still shows
    // up, just without a distance, sorted to the end of the list.
    //
    // Returns rows ordered nearest-first: ['driver_id', 'name', 'distance_km' (nullable)]
    function vrds_nearby_active_drivers(
        $conn,
        ?float $pickupLat,
        ?float $pickupLng,
        ?string $pickupLocation = null,
        ?string $destination = null
    ): array {
        $params = [];
        $locationFilter = '';

        $pickupLocation = trim((string) $pickupLocation);
        $destination    = trim((string) $destination);

        if ($pickupLocation !== '' || $destination !== '') {
            // designated_place must be set AND match at least one of the
            // two addresses. Matching is normalized (strip the word "city"
            // and extra spaces, case-insensitive) and bidirectional, so
            // "Pasig" as a designated_place matches an address containing
            // "Pasig City" and vice versa -- either side can be the one
            // with "City" attached.
            $conditions = [];

            $normalizedPlace = "TRIM(REGEXP_REPLACE(LOWER(d.designated_place), '\\s*city\\s*', ' ', 'g'))";

            if ($pickupLocation !== '') {
                $params[] = $pickupLocation;
                $n = count($params);
                $normalizedAddr = "TRIM(REGEXP_REPLACE(LOWER(\${$n}), '\\s*city\\s*', ' ', 'g'))";
                $conditions[] = "(POSITION({$normalizedPlace} IN {$normalizedAddr}) > 0 OR POSITION({$normalizedAddr} IN {$normalizedPlace}) > 0)";
            }
            if ($destination !== '') {
                $params[] = $destination;
                $n = count($params);
                $normalizedAddr = "TRIM(REGEXP_REPLACE(LOWER(\${$n}), '\\s*city\\s*', ' ', 'g'))";
                $conditions[] = "(POSITION({$normalizedPlace} IN {$normalizedAddr}) > 0 OR POSITION({$normalizedAddr} IN {$normalizedPlace}) > 0)";
            }

            $locationFilter = "
              AND d.designated_place IS NOT NULL
              AND TRIM(d.designated_place) <> ''
              AND (" . implode(' OR ', $conditions) . ")
            ";
        }

        $sql = "
            SELECT
                d.driver_id,
                d.first_name,
                d.last_name,
                d.designated_place,
                (
                    SELECT v.current_lat FROM vehicles v
                    WHERE v.assigned_driver_id = d.driver_id
                    ORDER BY v.last_location_update DESC NULLS LAST
                    LIMIT 1
                ) AS current_lat,
                (
                    SELECT v.current_lng FROM vehicles v
                    WHERE v.assigned_driver_id = d.driver_id
                    ORDER BY v.last_location_update DESC NULLS LAST
                    LIMIT 1
                ) AS current_lng
            FROM drivers d
            WHERE LOWER(d.status) = 'active'
              AND NOT EXISTS (
                  SELECT 1 FROM trips t
                  WHERE t.driver_id = d.driver_id
                  AND LOWER(t.status) IN ('scheduled', 'in transit')
              )
              {$locationFilter}
        ";

        $res = empty($params) ? pg_query($conn, $sql) : pg_query_params($conn, $sql, $params);

        $drivers = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $distanceKm = null;
                if (
                    $pickupLat !== null && $pickupLng !== null &&
                    $row['current_lat'] !== null && $row['current_lng'] !== null
                ) {
                    $distanceKm = vrds_haversine_km(
                        $pickupLat,
                        $pickupLng,
                        (float) $row['current_lat'],
                        (float) $row['current_lng']
                    );
                }

                $drivers[] = [
                    'driver_id'        => (int) $row['driver_id'],
                    'name'             => trim($row['first_name'] . ' ' . $row['last_name']),
                    'designated_place' => $row['designated_place'],
                    'distance_km'      => $distanceKm,
                ];
            }
        }

        usort($drivers, function ($a, $b) {
            // Known distances first (nearest first), unknowns pushed to the
            // end and just alphabetized among themselves.
            if ($a['distance_km'] === null && $b['distance_km'] === null) {
                return strcasecmp($a['name'], $b['name']);
            }
            if ($a['distance_km'] === null) return 1;
            if ($b['distance_km'] === null) return -1;
            return $a['distance_km'] <=> $b['distance_km'];
        });

        return $drivers;
    }
}

// Builds the JSON payload used by the "click a row to view full shipment
// details" modal. Pass the driver's full name (or null/empty if unassigned).
// Returns an HTML-attribute-safe string, ready to drop straight into
// onclick="openShipmentDetailsModal(...)".
if (!function_exists('vrds_shipment_detail_json')) {
    function vrds_shipment_detail_json(array $row, ?string $driverName): string
    {
        $payload = [
            'code'                 => code_id('REQ', $row['reservation_id']),
            'requestor'            => $row['requestor'] ?? '',
            'consignor_address'    => $row['consignor_address'] ?? '',
            'purpose'              => $row['purpose'] ?? '',
            'pickup_location'      => $row['pickup_location'] ?? '',
            'destination'          => $row['destination'] ?? '',
            'departure_date'       => !empty($row['departure_date'])
                ? date('M d, Y', strtotime($row['departure_date']))
                : '',
            'return_date'          => !empty($row['return_date'])
                ? date('M d, Y', strtotime($row['return_date']))
                : '',
            'status'               => $row['status'] ?? '',
            'driver_name'          => $driverName ?: '',
            'consignee_company'    => $row['consignee_company'] ?? '',
            'consignee_address'    => $row['consignee_address'] ?? '',
            'consignee_city'       => $row['consignee_city'] ?? '',
            'consignee_state'      => $row['consignee_state'] ?? '',
            'consignee_zip'        => $row['consignee_zip'] ?? '',
            'consignee_telephone'  => $row['consignee_telephone'] ?? '',
        ];

        return htmlspecialchars(json_encode($payload), ENT_QUOTES);
    }
}

// Same idea as vrds_shipment_detail_json, but for a dispatched trip row
// (trips + vehicles/drivers/reservations joined). Reuses the same modal,
// mapping trip departure/arrival times onto the modal's Departure/Return
// fields.
if (!function_exists('vrds_trip_detail_json')) {
    function vrds_trip_detail_json(array $row, ?string $driverName): string
    {
        $payload = [
            'code'                 => code_id('TRP', $row['trip_id']),
            'requestor'            => $row['requestor'] ?? '',
            'consignor_address'    => $row['consignor_address'] ?? '',
            'purpose'              => $row['purpose'] ?? '',
            'pickup_location'      => $row['pickup_location'] ?? '',
            'destination'          => $row['destination'] ?? '',
            'departure_date'       => !empty($row['departure_time'])
                ? date('M d, Y • g:i A', strtotime($row['departure_time']))
                : '',
            'return_date'          => !empty($row['arrival_time'])
                ? date('M d, Y • g:i A', strtotime($row['arrival_time']))
                : '',
            'status'               => $row['status'] ?? '',
            'driver_name'          => $driverName ?: '',
            'consignee_company'    => $row['consignee_company'] ?? '',
            'consignee_address'    => $row['consignee_address'] ?? '',
            'consignee_city'       => $row['consignee_city'] ?? '',
            'consignee_state'      => $row['consignee_state'] ?? '',
            'consignee_zip'        => $row['consignee_zip'] ?? '',
            'consignee_telephone'  => $row['consignee_telephone'] ?? '',
        ];

        return htmlspecialchars(json_encode($payload), ENT_QUOTES);
    }
}

// ================= CREATE DISPATCH =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_reservation'])) {

    if (!vrds_consume_form_token($_POST['form_token'] ?? null)) {
        vrds_redirect_with_message(
            'That reservation was already submitted. Check the list below — refreshing this page will not create another one.',
            'error'
        );
    }

    $requestor = trim($_POST['requestor'] ?? '');
    $consignorAddress = trim($_POST['consignor_address'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $departure_date = $_POST['departure_date'] ?? '';
    $return_date = $_POST['return_date'] ?? '';

    // Consignee Company Information
    $consigneeCompany   = trim($_POST['consignee_company'] ?? '');
    $consigneeAddress   = trim($_POST['consignee_address'] ?? '');
    $consigneeCity      = trim($_POST['consignee_city'] ?? '');
    $consigneeState     = trim($_POST['consignee_state'] ?? '');
    $consigneeZip       = trim($_POST['consignee_zip'] ?? '');
    $consigneeTelephone = trim($_POST['consignee_telephone'] ?? '');

    // Truncate values to match database column limits
    $requestor = mb_substr($requestor, 0, 100);
    $consignorAddress = mb_substr($consignorAddress, 0, 100);
    $consigneeCompany = mb_substr($consigneeCompany, 0, 150);
    $consigneeAddress = mb_substr($consigneeAddress, 0, 255);
    $consigneeCity = mb_substr($consigneeCity, 0, 100);
    $consigneeState = mb_substr($consigneeState, 0, 100);
    $consigneeZip = mb_substr($consigneeZip, 0, 20);
    $consigneeTelephone = mb_substr($consigneeTelephone, 0, 11);

    // Pickup Location / Destination are no longer typed separately — the
    // Consignor's Address IS the pickup point, and the Consignee's Address
    // (+ city/state/zip) IS the destination.
    $pickup_location = $consignorAddress;
    $destination = vrds_format_address($consigneeAddress, $consigneeCity, $consigneeState, $consigneeZip);
    
    // Truncate destination to prevent any length issues
    $destination = mb_substr($destination, 0, 500);

    if (
        $requestor === '' ||
        $consignorAddress === '' ||
        $purpose === '' ||
        $destination === '' ||
        $departure_date === ''
    ) {
        vrds_redirect_with_message('Please complete all required fields.', 'error');

    } elseif ($return_date !== '' && $return_date < $departure_date) {
        vrds_redirect_with_message('Return date cannot be earlier than departure date.', 'error');

    } else {

        $createdBy = $_SESSION['user_id'] ?? null;

        // Best-effort geocode of the pickup address so the driver-assignment
        // dropdowns can rank drivers by distance later. If this fails (or
        // is slow/unreachable), the reservation still saves fine — the
        // driver list just falls back to no-distance/alphabetical for it.
        $pickupCoords = vrds_geocode_address($pickup_location);

        $sql = "
            INSERT INTO reservations (
                requestor,
                consignor_address,
                purpose,
                pickup_location,
                destination,
                departure_date,
                return_date,
                consignee_company,
                consignee_address,
                consignee_city,
                consignee_state,
                consignee_zip,
                consignee_telephone,
                status,
                created_by,
                pickup_lat,
                pickup_lng
            )
            VALUES (
                $1, $2, $3, $4, $5, $6, $7,
                $8, $9, $10, $11, $12, $13,
                'Pending',
                $14, $15, $16
            )
        ";

        $result = pg_query_params($conn, $sql, [
            $requestor,
            $consignorAddress,
            $purpose,
            $pickup_location,
            $destination,
            $departure_date,
            $return_date !== '' ? $return_date : null,

            // Consignee information
            $consigneeCompany,
            $consigneeAddress,
            $consigneeCity,
            $consigneeState,
            $consigneeZip,
            $consigneeTelephone,

            $createdBy,
            $pickupCoords['lat'] ?? null,
            $pickupCoords['lng'] ?? null,
        ]);

        if ($result) {
            vrds_redirect_with_message(
                'Reservation created successfully.',
                'success'
            );
        } else {
            vrds_redirect_with_message(
                'Unable to create reservation: ' . pg_last_error($conn),
                'error'
            );
        }
    }
}
 
// ================= START DISPATCH =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_dispatch'])) {
 
    $tripId = (int) ($_POST['trip_id'] ?? 0);
 
    if ($tripId > 0) {
 
        $result = pg_query_params(
            $conn,
            "UPDATE trips
             SET status = 'In Transit'
             WHERE trip_id = $1
             AND LOWER(status) = 'scheduled'
             RETURNING vehicle_id, route_id",
            [$tripId]
        );
 
        if (!$result) {
            vrds_redirect_with_message('Unable to start dispatch: ' . pg_last_error($conn), 'error');
        }
 
        if (pg_num_rows($result) > 0) {
 
            $trip = pg_fetch_assoc($result);
 
            $vehicleId = (int) $trip['vehicle_id'];
            $routeId = !empty($trip['route_id']) ? (int) $trip['route_id'] : null;
 
            pg_query_params(
                $conn,
                "UPDATE vehicles
                 SET status = 'In Transit'
                 WHERE vehicle_id = $1",
                [$vehicleId]
            );
 
            // Real-Time Trip Monitoring snapshot: place the vehicle at the
            // route's origin the moment the trip actually starts (not when
            // the route was planned, which could've been hours/days earlier).
            // This is a starting-point snapshot, not live GPS tracking — once
            // a mobile app is built, it can call update_vehicle_location.php
            // to keep current_lat/current_lng moving from here.
            if ($routeId) {
                $routeRes = pg_query_params(
                    $conn,
                    "SELECT origin_lat, origin_lng FROM routes WHERE route_id = $1",
                    [$routeId]
                );
 
                if ($routeRes && ($route = pg_fetch_assoc($routeRes))) {
                    if ($route['origin_lat'] !== null && $route['origin_lng'] !== null) {
                        pg_query_params(
                            $conn,
                            "UPDATE vehicles
                                SET current_lat = $1, current_lng = $2, last_location_update = CURRENT_TIMESTAMP
                             WHERE vehicle_id = $3",
                            [$route['origin_lat'], $route['origin_lng'], $vehicleId]
                        );
 
                        // Flag this vehicle so the Dashboard map can
                        // auto-open its popup on next load — a "just
                        // started" highlight, read once then cleared.
                        $_SESSION['just_started_vehicle_id'] = $vehicleId;
                    }
                }
            }
 
            vrds_redirect_with_message('Trip started.', 'success');
        }
    }
 
    vrds_redirect_with_message('That trip could not be started (it may already be in transit).', 'error');
}
 
// ================= COMPLETE DISPATCH =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_dispatch'])) {
 
    $tripId = (int) ($_POST['trip_id'] ?? 0);
 
    if ($tripId > 0) {
 
        $result = pg_query_params(
            $conn,
            "UPDATE trips
             SET
                 status = 'Completed',
                 arrival_time = CURRENT_TIMESTAMP
             WHERE trip_id = $1
             AND LOWER(status) = 'in transit'
             RETURNING vehicle_id, reservation_id",
            [$tripId]
        );
 
        if (!$result) {
            vrds_redirect_with_message('Unable to complete dispatch: ' . pg_last_error($conn), 'error');
        }
 
        if (pg_num_rows($result) > 0) {
 
            $trip = pg_fetch_assoc($result);
 
            $vehicleId = (int) $trip['vehicle_id'];
            $reservationId = (int) $trip['reservation_id'];
 
            // Return vehicle to Available
            pg_query_params(
                $conn,
                "UPDATE vehicles
                 SET status = 'Available'
                 WHERE vehicle_id = $1",
                [$vehicleId]
            );
 
            // Mark related reservation as Completed
            pg_query_params(
                $conn,
                "UPDATE reservations
                 SET status = 'Completed'
                 WHERE reservation_id = $1",
                [$reservationId]
            );
 
            vrds_redirect_with_message('Trip completed.', 'success');
        }
    }
 
    vrds_redirect_with_message('That trip could not be completed (it may not be in transit).', 'error');
}
 
if (!function_exists('auto_dispatch_reservation')) {
function auto_dispatch_reservation($conn, int $reservationId): string
{
    $reservationRes = pg_query_params(
        $conn,
        "SELECT reservation_id, pickup_location, destination, departure_date, assigned_driver_id
         FROM reservations
         WHERE reservation_id = $1
         AND LOWER(status) = 'approved'",
        [$reservationId]
    );
 
    if (!$reservationRes || pg_num_rows($reservationRes) === 0) {
        return 'Reservation approved, but could not be found for dispatch.';
    }
 
    $reservation = pg_fetch_assoc($reservationRes);
 
 
    $existingTrip = pg_query_params(
        $conn,
        "SELECT trip_id FROM trips WHERE reservation_id = $1 LIMIT 1",
        [$reservationId]
    );
    if ($existingTrip && pg_num_rows($existingTrip) > 0) {
        return 'Reservation approved. A trip already exists for it.';
    }
 
    // --- Preference 0: the driver manually assigned on the reservation, if active and free ---
    $pair = null;

    if (!empty($reservation['assigned_driver_id'])) {
        $assignedDriverId = (int) $reservation['assigned_driver_id'];

        $assignedDriverRes = pg_query_params(
            $conn,
            "SELECT driver_id FROM drivers d
             WHERE driver_id = $1
               AND LOWER(status) = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM trips t
                   WHERE t.driver_id = d.driver_id
                   AND LOWER(t.status) IN ('scheduled', 'in transit')
               )",
            [$assignedDriverId]
        );

        if ($assignedDriverRes && pg_num_rows($assignedDriverRes) > 0) {
            // Prefer the vehicle this driver is normally assigned to, if it's available;
            // otherwise fall back to any available vehicle.
            $vehicleForDriverRes = pg_query_params(
                $conn,
                "SELECT vehicle_id FROM vehicles
                 WHERE assigned_driver_id = $1 AND LOWER(status) = 'available'
                 LIMIT 1",
                [$assignedDriverId]
            );

            $vehicleId = null;
            if ($vehicleForDriverRes && pg_num_rows($vehicleForDriverRes) > 0) {
                $vehicleId = pg_fetch_assoc($vehicleForDriverRes)['vehicle_id'];
            } else {
                $anyVehicleRes = pg_query(
                    $conn,
                    "SELECT vehicle_id FROM vehicles
                     WHERE LOWER(status) = 'available'
                     ORDER BY vehicle_id
                     LIMIT 1"
                );
                if ($anyVehicleRes && pg_num_rows($anyVehicleRes) > 0) {
                    $vehicleId = pg_fetch_assoc($anyVehicleRes)['vehicle_id'];
                }
            }

            if ($vehicleId !== null) {
                $pair = [
                    'vehicle_id' => $vehicleId,
                    'driver_id'  => $assignedDriverId,
                ];
            }
        }
    }

    // --- Preference 1: vehicle's own assigned driver, if free AND designated to this location ---
    if (!$pair) {
        // Get nearby drivers that are designated to this pickup/destination location
        $nearbyDrivers = vrds_nearby_active_drivers(
            $conn,
            $reservation['pickup_lat'] !== null ? (float) $reservation['pickup_lat'] : null,
            $reservation['pickup_lng'] !== null ? (float) $reservation['pickup_lng'] : null,
            $reservation['pickup_location'] ?? null,
            $reservation['destination'] ?? null
        );

        if (!empty($nearbyDrivers)) {
            // Try to find a vehicle assigned to one of these nearby drivers
            foreach ($nearbyDrivers as $driver) {
                $driverId = (int) $driver['driver_id'];
                $vehicleRes = pg_query_params(
                    $conn,
                    "SELECT vehicle_id FROM vehicles
                     WHERE assigned_driver_id = $1 AND LOWER(status) = 'available'
                     LIMIT 1",
                    [$driverId]
                );

                if ($vehicleRes && pg_num_rows($vehicleRes) > 0) {
                    $pair = [
                        'vehicle_id' => (int) pg_fetch_assoc($vehicleRes)['vehicle_id'],
                        'driver_id'  => $driverId,
                    ];
                    break;
                }
            }
        }
    }
 
    // --- Preference 2: any available vehicle + any free active driver ---
    if (!$pair) {
        $vehicleRes = pg_query(
            $conn,
            "SELECT vehicle_id FROM vehicles
             WHERE LOWER(status) = 'available'
             ORDER BY vehicle_id
             LIMIT 1"
        );

        $driverRes = pg_query(
            $conn,
            "SELECT driver_id FROM drivers d
             WHERE LOWER(d.status) = 'active'
             AND NOT EXISTS (
                 SELECT 1 FROM trips t
                 WHERE t.driver_id = d.driver_id
                 AND LOWER(t.status) IN ('scheduled', 'in transit')
             )
             ORDER BY driver_id
             LIMIT 1"
        );

        if ($vehicleRes && pg_num_rows($vehicleRes) > 0 && $driverRes && pg_num_rows($driverRes) > 0) {
            $pair = [
                'vehicle_id' => pg_fetch_assoc($vehicleRes)['vehicle_id'],
                'driver_id'  => pg_fetch_assoc($driverRes)['driver_id'],
            ];
        }
    }

    // --- Debug logging to help identify why auto-dispatch failed ---
    if (!$pair) {
        // Check what's missing
        $availableVehicles = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles WHERE LOWER(status) = 'available'"), 0, 0);
        $freeDrivers = pg_fetch_result(pg_query($conn, "
            SELECT COUNT(*) FROM drivers d
            WHERE LOWER(d.status) = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM trips t
                WHERE t.driver_id = d.driver_id
                AND LOWER(t.status) IN ('scheduled', 'in transit')
            )
        "), 0, 0);

        // Check for drivers with matching designated places
        $nearbyDrivers = vrds_nearby_active_drivers(
            $conn,
            $reservation['pickup_lat'] !== null ? (float) $reservation['pickup_lat'] : null,
            $reservation['pickup_lng'] !== null ? (float) $reservation['pickup_lng'] : null,
            $reservation['pickup_location'] ?? null,
            $reservation['destination'] ?? null
        );
        $matchingDriversCount = count($nearbyDrivers);

        error_log("Auto-dispatch failed for reservation $reservationId: Available vehicles=$availableVehicles, Free drivers=$freeDrivers, Drivers with matching designated place=$matchingDriversCount");
    }
 
    if (!$pair) {
        return 'Reservation approved, but no available vehicle/driver right now. Use "New Dispatch" once one frees up.';
    }
 
    $vehicleId = (int) $pair['vehicle_id'];
    $driverId  = (int) $pair['driver_id'];
 
    $departureTime = date('Y-m-d H:i:s', strtotime($reservation['departure_date']));
 
    // Match an existing route by origin/destination, same as manual dispatch does.
 
    $routeId = null;
    $routeResult = pg_query_params(
        $conn,
        "SELECT route_id FROM routes
         WHERE LOWER(origin) = LOWER($1) AND LOWER(destination) = LOWER($2)
         LIMIT 1",
        [$reservation['pickup_location'], $reservation['destination']]
    );
    if ($routeResult && pg_num_rows($routeResult) > 0) {
        $routeId = (int) pg_fetch_assoc($routeResult)['route_id'];
    }
 
    $tripResult = pg_query_params(
        $conn,
        "INSERT INTO trips (reservation_id, vehicle_id, driver_id, route_id, departure_time, status)
         VALUES ($1, $2, $3, $4, $5, 'Scheduled')",
        [$reservationId, $vehicleId, $driverId, $routeId, $departureTime]
    );
 
    if (!$tripResult) {
        return 'Reservation approved, but the trip could not be created: ' . pg_last_error($conn);
    }
 
    pg_query_params(
        $conn,
        "UPDATE vehicles SET status = 'Reserved' WHERE vehicle_id = $1",
        [$vehicleId]
    );
 
    return 'Reservation approved and automatically dispatched'
        . ($routeId ? '.' : ' — no matching route yet, plan one in Route Planning.');
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reservation_status'])) {
 
    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $newStatus = strtolower(trim($_POST['new_status'] ?? ''));
 
    // Only allow these two status changes
    if (
        $reservationId > 0 &&
        in_array($newStatus, ['approved', 'rejected'], true)
    ) {
 
        $result = pg_query_params(
            $conn,
            "UPDATE reservations
             SET status = $1
             WHERE reservation_id = $2",
            [
                $newStatus,
                $reservationId
            ]
        );
 
        if (!$result) {
            vrds_redirect_with_message('Unable to update reservation status: ' . pg_last_error($conn), 'error');
        }
 
        // When approving, automatically attempt to create a trip using the
        // intelligent auto_dispatch_reservation function. This respects
        // manual driver assignments and only dispatches when resources are
        // available, falling back to manual dispatch when needed.
        if ($newStatus === 'approved') {
            $dispatchResult = auto_dispatch_reservation($conn, $reservationId);
            
            if (str_contains($dispatchResult, 'automatically dispatched')) {
                vrds_redirect_with_message($dispatchResult, 'success');
            } else {
                // Auto-dispatch failed (no available resources), but approval succeeded
                vrds_redirect_with_message('Reservation approved. ' . $dispatchResult, 'info');
            }
        } else {
            // For rejected status, just show the status change message
            $message = 'Reservation ' . $newStatus . '.';
            vrds_redirect_with_message($message, 'success');
        }
    }
 
    vrds_redirect_with_message('Invalid reservation status update.', 'error');
}
 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_reservation_driver'])) {

    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $driverId = (int) ($_POST['driver_id'] ?? 0);

    if ($reservationId <= 0) {
        vrds_redirect_with_message('Invalid reservation for driver assignment.', 'error');
    }

    $result = pg_query_params(
        $conn,
        "UPDATE reservations
         SET assigned_driver_id = $1
         WHERE reservation_id = $2",
        [$driverId > 0 ? $driverId : null, $reservationId]
    );

    if (!$result) {
        vrds_redirect_with_message('Unable to assign driver: ' . pg_last_error($conn), 'error');
    }

    vrds_redirect_with_message(
        $driverId > 0 ? 'Driver assigned to reservation.' : 'Driver unassigned from reservation.',
        'success'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_dispatch_all'])) {
 
    $undispatchedRes = pg_query($conn, "
        SELECT r.reservation_id
        FROM reservations r
        LEFT JOIN trips t ON t.reservation_id = r.reservation_id
        WHERE LOWER(r.status) = 'approved'
          AND t.trip_id IS NULL
        ORDER BY r.departure_date ASC
    ");
 
    $assigned = 0;
    $skipped  = 0;
 
    if ($undispatchedRes) {
        while ($row = pg_fetch_assoc($undispatchedRes)) {
            $result = auto_dispatch_reservation($conn, (int) $row['reservation_id']);
            if (str_contains($result, 'automatically dispatched')) {
                $assigned++;
            } else {
                $skipped++;
            }
        }
    }
 
    if ($assigned === 0 && $skipped === 0) {
        vrds_redirect_with_message('No approved reservations are waiting on a trip.', 'info');
    }
 
    $message = "Dispatched {$assigned} trip(s)."
        . ($skipped > 0 ? " {$skipped} reservation(s) still waiting on an available vehicle/driver." : '');
 
    vrds_redirect_with_message($message, 'success');
}
 
// ================= CREATE DISPATCH (MANUAL) =================
// Backs the "New Dispatch" modal's form (name="create_dispatch"). Lets
// staff manually pair an approved-but-undispatched reservation with a
// specific vehicle + driver instead of waiting on auto_dispatch_reservation.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_dispatch'])) {

    if (!vrds_consume_form_token($_POST['form_token'] ?? null)) {
        vrds_redirect_with_message(
            'That dispatch was already submitted. Check the list below — refreshing this page will not create another one.',
            'error'
        );
    }

    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $vehicleId     = (int) ($_POST['vehicle_id'] ?? 0);
    $driverId      = (int) ($_POST['driver_id'] ?? 0);

    if ($reservationId <= 0 || $vehicleId <= 0 || $driverId <= 0) {
        vrds_redirect_with_message('Please select a reservation, vehicle, and driver.', 'error');
    }

    $reservationRes = pg_query_params(
        $conn,
        "SELECT reservation_id, pickup_location, destination, departure_date
         FROM reservations
         WHERE reservation_id = $1 AND LOWER(status) = 'approved'",
        [$reservationId]
    );

    if (!$reservationRes || pg_num_rows($reservationRes) === 0) {
        vrds_redirect_with_message('That reservation is not approved or no longer exists.', 'error');
    }

    $existingTrip = pg_query_params(
        $conn,
        "SELECT trip_id FROM trips WHERE reservation_id = $1 LIMIT 1",
        [$reservationId]
    );
    if ($existingTrip && pg_num_rows($existingTrip) > 0) {
        vrds_redirect_with_message('A trip already exists for that reservation.', 'error');
    }

    $reservation = pg_fetch_assoc($reservationRes);

    // Keep this in sync with the "assign driver" dropdown on the
    // Reservations list, so the driver shows up consistently everywhere
    // (including the Shipment Details modal) even before the trip exists.
    pg_query_params(
        $conn,
        "UPDATE reservations SET assigned_driver_id = $1 WHERE reservation_id = $2",
        [$driverId, $reservationId]
    );

    $departureTime = date('Y-m-d H:i:s', strtotime($reservation['departure_date']));

    $routeId = null;
    $routeResult = pg_query_params(
        $conn,
        "SELECT route_id FROM routes
         WHERE LOWER(origin) = LOWER($1) AND LOWER(destination) = LOWER($2)
         LIMIT 1",
        [$reservation['pickup_location'], $reservation['destination']]
    );
    if ($routeResult && pg_num_rows($routeResult) > 0) {
        $routeId = (int) pg_fetch_assoc($routeResult)['route_id'];
    }

    $tripResult = pg_query_params(
        $conn,
        "INSERT INTO trips (reservation_id, vehicle_id, driver_id, route_id, departure_time, status)
         VALUES ($1, $2, $3, $4, $5, 'Scheduled')",
        [$reservationId, $vehicleId, $driverId, $routeId, $departureTime]
    );

    if (!$tripResult) {
        vrds_redirect_with_message('Unable to create dispatch: ' . pg_last_error($conn), 'error');
    }

    pg_query_params(
        $conn,
        "UPDATE vehicles SET status = 'Reserved' WHERE vehicle_id = $1",
        [$vehicleId]
    );

    vrds_redirect_with_message('Dispatch created and driver assigned.', 'success');
}
 
// UPDATE RESERVATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reservation'])) {
 
    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
 
    $requestor = trim($_POST['requestor'] ?? '');
    $consignorAddress = trim($_POST['consignor_address'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $departure_date = $_POST['departure_date'] ?? '';
    $return_date = $_POST['return_date'] ?? '';

    // NEW: consignee company info (stored in reservation_shipment_details)
    $consigneeCompany   = trim($_POST['consignee_company'] ?? '');
    $consigneeAddress   = trim($_POST['consignee_address'] ?? '');
    $consigneeCity      = trim($_POST['consignee_city'] ?? '');
    $consigneeState     = trim($_POST['consignee_state'] ?? '');
    $consigneeZip       = trim($_POST['consignee_zip'] ?? '');
    $consigneeTelephone = trim($_POST['consignee_telephone'] ?? '');

    // Pickup Location / Destination are derived, same as on create.
    $pickup_location = $consignorAddress;
    $destination = vrds_format_address($consigneeAddress, $consigneeCity, $consigneeState, $consigneeZip);
 
    if ($reservationId > 0) {
 
        // Only re-geocode when the pickup address text actually changed —
        // no need to hit Nominatim again on every edit of unrelated fields.
        $existingRes = pg_query_params(
            $conn,
            "SELECT pickup_location, pickup_lat, pickup_lng FROM reservations WHERE reservation_id = $1",
            [$reservationId]
        );
        $existing = $existingRes ? pg_fetch_assoc($existingRes) : null;

        if ($existing && $existing['pickup_location'] === $pickup_location) {
            $pickupLat = $existing['pickup_lat'];
            $pickupLng = $existing['pickup_lng'];
        } else {
            $pickupCoords = vrds_geocode_address($pickup_location);
            $pickupLat = $pickupCoords['lat'] ?? null;
            $pickupLng = $pickupCoords['lng'] ?? null;
        }

        $sql = "
            UPDATE reservations
            SET
                requestor = $1,
                consignor_address = $2,
                purpose = $3,
                pickup_location = $4,
                destination = $5,
                departure_date = $6,
                return_date = $7,
                consignee_company = $8,
                consignee_address = $9,
                consignee_city = $10,
                consignee_state = $11,
                consignee_zip = $12,
                consignee_telephone = $13,
                pickup_lat = $14,
                pickup_lng = $15
            WHERE reservation_id = $16
        ";
 
        $result = pg_query_params($conn, $sql, [ $requestor, $consignorAddress, $purpose, $pickup_location, $destination,
            $departure_date, $return_date !== '' ? $return_date : null,
            $consigneeCompany, $consigneeAddress, $consigneeCity, $consigneeState, $consigneeZip, $consigneeTelephone,
            $pickupLat, $pickupLng,
            $reservationId
        ]);
 
        if (!$result) {
            vrds_redirect_with_message('Unable to update reservation: ' . pg_last_error($conn), 'error');
        }
 
        vrds_redirect_with_message('Reservation updated.', 'success');
    }
 
    vrds_redirect_with_message('Invalid reservation update request.', 'error');
}
 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_reservation'])) {
 
    if (!vrds_consume_form_token($_POST['form_token'] ?? null)) {
        vrds_redirect_with_message(
            'That reservation was already submitted. Check the list below — refreshing this page will not create another one.',
            'error'
        );
    }
 
    $requestor = trim($_POST['requestor'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $pickup_location = trim($_POST['pickup_location'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $departure_date = $_POST['departure_date'] ?? '';
    $return_date = $_POST['return_date'] ?? '';

    // Truncate values to match database column limits
    $requestor = mb_substr($requestor, 0, 100);
    $department = mb_substr($department, 0, 100);
    $pickup_location = mb_substr($pickup_location, 0, 100);
    $destination = mb_substr($destination, 0, 500);
 
    if (
        $requestor === '' ||
        $department === '' ||
        $purpose === '' ||
        $pickup_location === '' ||
        $destination === '' ||
        $departure_date === ''
    ) {
        vrds_redirect_with_message('Please complete all required fields.', 'error');
 
    } elseif ($return_date !== '' && $return_date < $departure_date) {
        vrds_redirect_with_message('Return date cannot be earlier than departure date.', 'error');
 
    } else {
 
        $createdBy = $_SESSION['user_id'] ?? null;
 
        $sql = "
            INSERT INTO reservations ( requestor, department, purpose, pickup_location, destination, departure_date,
                return_date, status, created_by) VALUES ($1, $2, $3, $4, $5, $6, $7, 'Pending', $8)";
 
        $result = pg_query_params($conn, $sql, [
            $requestor,
            $department,
            $purpose,
            $pickup_location,
            $destination,
            $departure_date,
            $return_date !== '' ? $return_date : null,
            $createdBy
        ]);
 
        if ($result) {
            vrds_redirect_with_message('Reservation created successfully.', 'success');
        } else {
            vrds_redirect_with_message('Unable to create reservation: ' . pg_last_error($conn), 'error');
        }
    }
}
 
$dispatch_message = '';
$reservationMessage = '';
$reservationMessageType = '';
 
if (isset($_SESSION['vrds_flash_message'])) {
    $dispatch_message = $_SESSION['vrds_flash_message'];
    $reservationMessage = $_SESSION['vrds_flash_message'];
    $reservationMessageType = $_SESSION['vrds_flash_type'] ?? 'info';
 
    unset($_SESSION['vrds_flash_message'], $_SESSION['vrds_flash_type']);
}
 
// Fresh one-time tokens for this page load — embedded as hidden fields in
// the "New Reservation" and "New Dispatch" forms below.
$newReservationFormToken = vrds_new_form_token();
$newDispatchFormToken = vrds_new_form_token();
 
        // "Active" now reflects reservations still awaiting a decision (Pending),
        // since Approved ones move to the Archived tab automatically.
        $activeReservations = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM reservations WHERE LOWER(status) = 'pending'"), 0, 0);
        $archivedReservations = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM reservations WHERE LOWER(status) IN ('completed','rejected')"), 0, 0);
        $inProgressDispatches = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM trips WHERE status = 'In Transit'"), 0, 0);
        $availableVehicles = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM vehicles WHERE status = 'Available'"), 0, 0);
        $activeDrivers = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM drivers WHERE LOWER(status) = 'active'"),0,0);
    ?>
 
<!-- VRDS -->
<section id="reservation" class="section space-y-6 dark:text-slate-400">

    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900 dark:text-white">Vehicle Reservation & Dispatch</h1>
                <span class="text-xs font-medium px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 border border-blue-500/20">
                    VRDS Module
                </span>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Reservations, dispatches, vehicles, drivers
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('vrds', 'reservations')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('vrds', 'reservations'); }"
             title="View reservations">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Reservations</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeReservations ?></p>
                    <p class="text-xs text-slate-400 mt-1">Pending</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                    <i class="ti ti-calendar text-blue-500 dark:text-blue-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('vrds', 'dispatches')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('vrds', 'dispatches'); }"
             title="View dispatches">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Dispatches</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $inProgressDispatches ?></p>
                    <p class="text-xs text-slate-400 mt-1">In progress</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                    <i class="ti ti-truck text-emerald-500 dark:text-emerald-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('vrds', 'vehicles')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('vrds', 'vehicles'); }"
             title="View vehicles">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Vehicles</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $availableVehicles ?></p>
                    <p class="text-xs text-slate-400 mt-1">Available</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                    <i class="ti ti-car text-amber-500 dark:text-amber-400 text-lg"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 cursor-pointer transition-all hover:border-blue-300 dark:hover:border-blue-500/30 hover:shadow-md"
             role="button" tabindex="0"
             onclick="goToSubtab('vrds', 'drivers')"
             onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); goToSubtab('vrds', 'drivers'); }"
             title="View drivers">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Drivers</p>
                    <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeDrivers ?></p>
                    <p class="text-xs text-slate-400 mt-1">Active</p>
                </div>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(168 85 247 / 0.1)">
                    <i class="ti ti-users text-purple-500 dark:text-purple-400 text-lg"></i>
                </div>
            </div>
        </div>
    </div>
 
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6">
        <div class="flex justify-center mb-4">
            <?php render_subtabs('vrds', [
                'reservations' => 'Reservations', 'vehicles' => 'Vehicles', 'drivers' => 'Drivers',
                'dispatches' => 'Dispatches', 'archived' => 'Archived',
            ], 'reservations'); ?>
        </div>
 
        <div class="subtab-panel" data-module="vrds" data-subtab="reservations">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $activeReservations ?> pending</p>
                <button
                    type="button"
                    onclick="openReservationModal()"
                    class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors">
                    <i class="ti ti-plus"></i> New reservation
                </button>
            </div>
 
            <?php if (!empty($dispatch_message)): ?>
                <div class="mb-3 px-4 py-2 text-sm rounded-lg <?= $reservationMessageType === 'error' ? 'bg-red-50 text-red-700 border border-red-100' : 'bg-blue-50 border-blue-100' ?>">
                    <?= htmlspecialchars($dispatch_message) ?>
                </div>
            <?php endif; ?>
 
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                        <th class="py-3 px-4 font-normal">Shipment</th>
                        <th class="font-normal">Consignor</th>
                        <th class="font-normal">Consignee</th>
                        <th class="font-normal">Driver</th>
                        <th class="font-normal">Pickup Date</th>
                        <th class="font-normal">Status</th>
                        <th class="font-normal text-right pr-4">Actions</th>
                    </tr>
                </thead>
 
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php
                $res = pg_query($conn, "
                    SELECT r.reservation_id, r.requestor, r.consignor_address, r.purpose,
                           r.pickup_location, r.destination, r.departure_date, r.return_date, r.status,
                           r.consignee_company, r.consignee_address, r.consignee_city,
                           r.consignee_state, r.consignee_zip, r.consignee_telephone,
                           r.assigned_driver_id, r.pickup_lat, r.pickup_lng,
                           d.first_name AS assigned_driver_first_name,
                           d.last_name AS assigned_driver_last_name
                    FROM reservations r
                    LEFT JOIN drivers d ON d.driver_id = r.assigned_driver_id
                    WHERE LOWER(r.status) = 'pending'
                    ORDER BY r.reservation_id DESC
                ");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="7" class="text-center text-slate-400 py-6">No pending reservations.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)):
                    $driverName = $row['assigned_driver_first_name']
                        ? trim($row['assigned_driver_first_name'] . ' ' . $row['assigned_driver_last_name'])
                        : null;
                ?>
                    <tr
                        class="cursor-pointer !bg-white dark:!bg-slate-900 hover:!bg-slate-50 dark:hover:!bg-slate-800/60 transition-colors"
                        onclick="openShipmentDetailsModal(<?= vrds_shipment_detail_json($row, $driverName) ?>)">
                        <td class="py-3 px-4"><?= manifest_tag(code_id('REQ', $row['reservation_id'])) ?></td>
                        <td><?= htmlspecialchars($row['requestor'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['consignee_company'] ?? '—') ?></td>
                        <td class="whitespace-nowrap" onclick="event.stopPropagation()">
                            <form method="POST" class="inline-flex items-center gap-1"
                                onsubmit="return vrdsLockSubmit(this);">
                                <input type="hidden" name="reservation_id" value="<?= (int) $row['reservation_id'] ?>">
                                <!--
                                    NOTE: this used to be a hidden <button type="submit"
                                    name="assign_reservation_driver">, relying on
                                    `this.form.requestSubmit()` (called below, with no
                                    argument) to "click" it. Per spec, requestSubmit()
                                    with no argument makes the FORM itself the submitter
                                    -- not the button -- so a submit button's name=value
                                    pair is excluded from the submitted data in that case.
                                    That silently dropped assign_reservation_driver from
                                    $_POST, so the PHP handler below never ran and the
                                    assignment never saved. A plain hidden input's
                                    name=value pair is always included regardless of
                                    what triggered the submission, so it isn't affected
                                    by this at all.
                                -->
                                <input type="hidden" name="assign_reservation_driver" value="1">
                                <select
                                    name="driver_id"
                                    onchange="this.form.requestSubmit()"
                                    class="!bg-white dark:!bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-600 rounded-md text-xs px-1.5 py-1 max-w-[140px]">
                                    <option value="" class="bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">— Unassigned —</option>
                                    <?php
                                    // Only active drivers who aren't already tied up on
                                    // another trip AND are designated to this shipment's
                                    // pickup or destination location, nearest-to-pickup
                                    // first (see vrds_nearby_active_drivers() near the
                                    // top of this file).
                                    $nearbyDrivers = vrds_nearby_active_drivers(
                                        $conn,
                                        $row['pickup_lat'] !== null ? (float) $row['pickup_lat'] : null,
                                        $row['pickup_lng'] !== null ? (float) $row['pickup_lng'] : null,
                                        $row['pickup_location'] ?? null,
                                        $row['destination'] ?? null
                                    );

                                    if (empty($nearbyDrivers)):
                                    ?>
                                        <option value="" disabled class="bg-white dark:bg-slate-800 text-slate-400">No driver designated for this location</option>
                                    <?php
                                    endif;

                                    foreach ($nearbyDrivers as $d):
                                        $label = $d['name']
                                            . ($d['designated_place'] ? ' — ' . $d['designated_place'] : '')
                                            . ($d['distance_km'] !== null
                                                ? ' (' . number_format($d['distance_km'], 1) . ' km)'
                                                : '');
                                    ?>
                                        <option
                                            value="<?= (int) $d['driver_id'] ?>"
                                            class="bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200"
                                            <?= ((int) $row['assigned_driver_id'] === (int) $d['driver_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?= !empty($row['departure_date'])
                                ? htmlspecialchars(date('Y-m-d', strtotime($row['departure_date'])))
                                : '—' ?>
 
                        </td>
 
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
 
 
 
                        <td class="text-right pr-4 whitespace-nowrap" onclick="event.stopPropagation()">
 
                    <!-- APPROVE -->
                    <form method="POST"
                        class="inline-block"
                        onsubmit="return vrdsConfirmAndLock(this, 'Approve this reservation?');">
 
                        <input
                            type="hidden"
                            name="reservation_id"
                            value="<?= (int) $row['reservation_id'] ?>">
 
                        <input
                            type="hidden"
                            name="new_status"
                            value="approved">
 
                        <button
                            type="submit"
                            name="update_reservation_status"
                            value="1"
                             class="inline-flex items-center justify-center w-8 h-8 text-green-600 border border-green-200 rounded-md hover:bg-green-50 hover:border-green-300 transition-colors">
                             <i class="ti ti-check text-base"></i>
                        </button>
 
                    </form>
 
        <!-- REJECT -->
        <form method="POST"
              class="inline-block"
              onsubmit="return vrdsConfirmAndLock(this, 'Reject this reservation?');">
 
            <input
                type="hidden"
                name="reservation_id"
                value="<?= (int) $row['reservation_id'] ?>">
 
            <input
                type="hidden"
                name="new_status"
                value="rejected">
 
            <button
                type="submit"
                name="update_reservation_status"
                value="1"
                  class="inline-flex items-center justify-center w-8 h-8 text-red-600 border border-red-200 rounded-md hover:bg-red-50 hover:border-red-300 transition-colors">
                 <i class="ti ti-x text-base"></i>
            </button>
 
        </form>
 
 
    <!-- EDIT -->
<button
    type="button"
    onclick="openEditReservationModal(
        <?= (int) $row['reservation_id'] ?>,
        <?= htmlspecialchars(json_encode($row['requestor'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignor_address'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['purpose'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode(
            !empty($row['departure_date'])
                ? date('Y-m-d', strtotime($row['departure_date']))
                : ''
        )) ?>,
        <?= htmlspecialchars(json_encode(
            !empty($row['return_date'])
                ? date('Y-m-d', strtotime($row['return_date']))
                : ''
        )) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_company'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_address'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_city'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_state'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_zip'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_telephone'] ?? '')) ?>
    )"
    class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors">
    <i class="ti ti-edit text-base"></i>
</button>
 
</td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
        <!-- ============== ARCHIVED PANEL (Approved + Rejected) ============== -->
        <div class="subtab-panel hidden" data-module="vrds" data-subtab="archived">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500"><?= (int) $archivedReservations ?> archived</p>
            </div>
 
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                        <th class="py-3 px-4 font-normal">Shipment</th>
                        <th class="font-normal">Consignor</th>
                        <th class="font-normal">Consignee</th>
                        <th class="font-normal">Driver</th>
                        <th class="font-normal">Pickup Date</th>
                        <th class="font-normal">Status</th>
                        <th class="font-normal text-right pr-4">Actions</th>
                    </tr>
                </thead>
 
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php
               $archivedRes = pg_query($conn, "
                    SELECT r.reservation_id, r.requestor, r.consignor_address, r.purpose,
                        r.pickup_location, r.destination, r.departure_date, r.return_date, r.status,
                        r.consignee_company, r.consignee_address, r.consignee_city,
                        r.consignee_state, r.consignee_zip, r.consignee_telephone,
                        r.assigned_driver_id,
                        d.first_name AS assigned_driver_first_name,
                        d.last_name AS assigned_driver_last_name
                    FROM reservations r
                    LEFT JOIN drivers d ON d.driver_id = r.assigned_driver_id
                    WHERE LOWER(r.status) IN ('completed', 'rejected')
                    ORDER BY r.reservation_id DESC
                ");
                if (pg_num_rows($archivedRes) === 0): ?>
                    <tr><td colspan="7" class="text-center text-slate-400 py-6">No archived reservations yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($archivedRes)):
                    $driverName = $row['assigned_driver_first_name']
                        ? trim($row['assigned_driver_first_name'] . ' ' . $row['assigned_driver_last_name'])
                        : null;
                ?>
                    <tr
                        class="cursor-pointer !bg-white dark:!bg-slate-900 hover:!bg-slate-50 dark:hover:!bg-slate-800/60 transition-colors"
                        onclick="openShipmentDetailsModal(<?= vrds_shipment_detail_json($row, $driverName) ?>)">
                        <td class="py-3 px-4"><?= manifest_tag(code_id('REQ', $row['reservation_id'])) ?></td>
                        <td><?= htmlspecialchars($row['requestor'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['consignee_company'] ?? '—') ?></td>
                        <td>
                            <?= $driverName ? htmlspecialchars($driverName) : '—' ?>
                        </td>
                        <td>
                            <?= !empty($row['departure_date'])
                                ? htmlspecialchars(date('Y-m-d', strtotime($row['departure_date'])))
                                : '—' ?>
                        </td>
 
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
 
                        <td class="text-right pr-4 whitespace-nowrap" onclick="event.stopPropagation()">
 
    <!-- EDIT -->
<button
    type="button"
    onclick="openEditReservationModal(
        <?= (int) $row['reservation_id'] ?>,
        <?= htmlspecialchars(json_encode($row['requestor'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignor_address'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['purpose'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode(
            !empty($row['departure_date'])
                ? date('Y-m-d', strtotime($row['departure_date']))
                : ''
        )) ?>,
        <?= htmlspecialchars(json_encode(
            !empty($row['return_date'])
                ? date('Y-m-d', strtotime($row['return_date']))
                : ''
        )) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_company'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_address'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_city'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_state'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_zip'] ?? '')) ?>,
        <?= htmlspecialchars(json_encode($row['consignee_telephone'] ?? '')) ?>
    )"
   class="inline-flex items-center justify-center w-8 h-8 text-blue-600 border border-blue-200 rounded-md hover:bg-blue-50 hover:border-blue-300 transition-colors">
    <i class="ti ti-edit"></i>
</button>
 
</td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
        <!-- ============== DISPATCHES PANEL ============== -->
<div class="subtab-panel hidden" data-module="vrds" data-subtab="dispatches">
    <div class="flex justify-between items-center mb-3">
        <p class="text-sm text-slate-500">Vehicle dispatches</p>
        <div class="flex items-center gap-2">
            <form method="POST" onsubmit="return vrdsConfirmAndLock(this, 'Auto-assign vehicles and drivers to all approved reservations that are still waiting?');">
                <button
                    type="submit"
                    name="auto_dispatch_all"
                    value="1"
                    class="border border-blue-600 text-blue-600 text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-50 transition-colors">
                    <i class="ti ti-refresh"></i> Auto-Dispatch Pending
                </button>
            </form>
            <button
                type="button"
                onclick="openDispatchModal()"
                class="bg-blue-600 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-blue-700 transition-colors">
                <i class="ti ti-plus"></i> New Dispatch
            </button>
        </div>
    </div>
 
    <?php if (!empty($dispatch_message)): ?>
        <div class="mb-3 px-4 py-2 text-sm rounded-lg <?= $reservationMessageType === 'error' ? 'bg-red-50 text-red-700 border border-red-100' : 'bg-blue-50 border-blue-100' ?>">
            <?= htmlspecialchars($dispatch_message) ?>
        </div>
    <?php endif; ?>
 
    <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">Trip</th><th class="font-normal">Vehicle</th>
                    <th class="font-normal">Driver</th><th class="font-normal">Route</th>
                    <th class="font-normal">Departure</th>
                    <th class="font-normal">Status</th>
                    <th class="font-normal text-right pr-4">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php
                $res = pg_query($conn, "
                    SELECT
                        t.trip_id,
                        t.departure_time,
                        t.arrival_time,
                        t.status,
                        v.plate_number,
                        d.first_name,
                        d.last_name,
                        r.requestor,
                        r.consignor_address,
                        r.purpose,
                        r.pickup_location,
                        r.destination,
                        r.consignee_company,
                        r.consignee_address,
                        r.consignee_city,
                        r.consignee_state,
                        r.consignee_zip,
                        r.consignee_telephone,
                        COALESCE(
                            rt.route_name,
                            r.pickup_location || ' → ' || r.destination
                        ) AS route_display
                    FROM trips t
                    LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                    LEFT JOIN drivers d ON d.driver_id = t.driver_id
                    LEFT JOIN routes rt ON rt.route_id = t.route_id
                    LEFT JOIN reservations r ON r.reservation_id = t.reservation_id
                    WHERE LOWER(t.status) IN ('scheduled', 'in transit')
                    ORDER BY t.trip_id DESC
                ");
                
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="7" class="text-center text-slate-400 py-6">No dispatches yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)):
                    $driverName = $row['first_name']
                        ? full_name($row['first_name'], $row['last_name'])
                        : null;
                ?>
                    <tr
                        class="cursor-pointer !bg-white dark:!bg-slate-900 hover:!bg-slate-50 dark:hover:!bg-slate-800/60 transition-colors"
                        onclick="openShipmentDetailsModal(<?= vrds_trip_detail_json($row, $driverName) ?>)">
                        <td class="py-3 px-4"><?= manifest_tag(code_id('TRP', $row['trip_id'])) ?></td>
                        <td class="tag text-xs"><?= htmlspecialchars($row['plate_number'] ?? '—') ?></td>
                        <td><?= $driverName ? htmlspecialchars($driverName) : '—' ?></td>
                        <td>
    <?= htmlspecialchars($row['route_display'] ?? '—') ?>
</td>
                        <td>
    <?php if (!empty($row['departure_time'])): ?>
        <?= htmlspecialchars(date('M d, Y', strtotime($row['departure_time']))) ?>
    <?php else: ?>
        —
    <?php endif; ?>
</td>
                        <td>
 
    <?= badge($row['status'], status_color($row['status'])) ?>
 
    <?php if (
        strtolower($row['status'] ?? '') === 'completed'
        && !empty($row['arrival_time'])
    ): ?>
 
        <div class="text-[11px] text-slate-400 mt-1">
            Arrived:
            <?= htmlspecialchars(
                date('M d, Y • g:i A', strtotime($row['arrival_time']))
            ) ?>
        </div>
 
    <?php endif; ?>
 
</td>
    
            <td class="text-right pr-4 whitespace-nowrap" onclick="event.stopPropagation()">
                <?php if (strtolower($row['status'] ?? '') === 'scheduled'): ?>
                    <span class="text-xs text-slate-400 italic">Waiting for driver to start (mobile app)</span>
                <?php elseif (strtolower($row['status'] ?? '') === 'in transit'): ?>
                    <span class="text-xs text-blue-500 italic">In progress — driver will complete via app</span>
                <?php else: ?>
                    <span class="text-xs text-slate-400">—</span>
                <?php endif; ?>
            </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
        <!-- ============== VEHICLES PANEL ============== -->
        <div class="subtab-panel hidden" data-module="vrds" data-subtab="vehicles">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Vehicles</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">Vehicle</th><th class="font-normal">Type</th>
                    <th class="font-normal">Plate no.</th><th class="font-normal">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php
                $res = pg_query($conn, "SELECT vehicle_id, vehicle_type, plate_number, status FROM vehicles ORDER BY vehicle_id");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="4" class="text-center text-slate-400 py-6">No vehicles yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr class="!bg-white dark:!bg-slate-900 hover:!bg-slate-50 dark:hover:!bg-slate-800/60 transition-colors">
                        <td class="py-3 px-4"><?= manifest_tag(code_id('VEH', $row['vehicle_id'])) ?></td>
                        <td><?= htmlspecialchars($row['vehicle_type'] ?? '—') ?></td>
                        <td class="tag text-xs"><?= htmlspecialchars($row['plate_number']) ?></td>
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
 
        <!-- ============== DRIVERS PANEL ============== -->
        <div class="subtab-panel hidden" data-module="vrds" data-subtab="drivers">
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-slate-500">Drivers</p>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                    <th class="py-3 px-4 font-normal">Driver</th><th class="font-normal">Phone</th><th class="font-normal">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php
                $res = pg_query($conn, "SELECT driver_id, first_name, last_name, phone, status FROM drivers ORDER BY driver_id");
                if (pg_num_rows($res) === 0): ?>
                    <tr><td colspan="3" class="text-center text-slate-400 py-6">No drivers yet.</td></tr>
                <?php else: while ($row = pg_fetch_assoc($res)): ?>
                    <tr class="!bg-white dark:!bg-slate-900 hover:!bg-slate-50 dark:hover:!bg-slate-800/60 transition-colors">
                        <td class="py-3 px-4"><?= htmlspecialchars(full_name($row['first_name'], $row['last_name'])) ?></td>
                        <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                        <td><?= badge($row['status'], status_color($row['status'])) ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</section>
 
 
<!-- ================= NEW RESERVATION MODAL ================= -->
<div
    id="reservationModal"
    class="fixed inset-0 z-80 hidden items-center justify-center bg-black/40 px-4">

   <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto overflow-hidden">
        <!-- Modal Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-8 py-5 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">
            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100 mb-1 mt-5">
                    New Reservation
                </h2>
                <p class="text-xs text-white/80 dark:text-slate-400 mt-1 mb-5">
                    Create a new vehicle reservation request
                </p>
            </div>

            <button
                type="button"
                onclick="closeReservationModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>
        </div>
 
        <!-- Form -->
        <form method="POST" onsubmit="return vrdsLockSubmit(this);">
 
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($newReservationFormToken) ?>">

            <div class="p-6 pt-8">
            <div class="grid grid-cols-2 gap-8">
             <div class="border-r border-slate-200 dark:border-slate-700 pr-8 space-y-4">

                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Consignor</p>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Consignor <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            name="requestor"
                            required
                            maxlength="100"
                            placeholder="Enter Consignor name"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Address <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            name="consignor_address"
                            required
                            maxlength="100"
                            placeholder="Enter consignor address"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Used automatically as the Pickup Location. Max 100 characters.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Purpose <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            name="purpose"
                            required
                            rows="3"
                            placeholder="Enter purpose of trip"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Departure Date <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                name="departure_date"
                                required
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Return Date
                            </label>
                            <input
                                type="date"
                                name="return_date"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        </div>
                    </div>
                </div>

                <!-- ===================== CONSIGNEE (right) ===================== -->
              <div class="pl-8 space-y-4">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Consignee</p>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Company Name</label>
                        <input
                            type="text"
                            name="consignee_company"
                            maxlength="150"
                            placeholder="Enter Company Name"
                            id="new_consignee_company"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Address <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            name="consignee_address"
                            required
                            maxlength="255"
                            placeholder="Enter Address"
                            id="new_consignee_address"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Used automatically as the Destination.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">City</label>
                            <select
                                name="consignee_city"
                                id="new_consignee_city"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                                <option value="">Select a city</option>
                                <option value="Manila">Manila</option>
                                <option value="Makati">Makati</option>
                                <option value="Quezon City">Quezon City</option>
                                <option value="Las Piñas">Las Piñas</option>
                                <option value="Parañaque">Parañaque</option>
                                <option value="Pasay">Pasay</option>
                                <option value="Pasig">Pasig</option>
                                <option value="Taguig">Taguig</option>
                                <option value="San Juan">San Juan</option>
                                <option value="Pateros">Pateros</option>
                                <option value="Mandaluyong">Mandaluyong</option>
                                <option value="San Andres">San Andres</option>
                                <option value="Santa Mesa">Santa Mesa</option>
                                <option value="Tondo">Tondo</option>
                                <option value="Binondo">Binondo</option>
                                <option value="Sampaloc">Sampaloc</option>
                                <option value="Quiapo">Quiapo</option>
                                <option value="Santa Cruz">Santa Cruz</option>
                                <option value="Ermita">Ermita</option>
                                <option value="Intramuros">Intramuros</option>
                                <option value="Malate">Malate</option>
                                <option value="Paco">Paco</option>
                                <option value="Pandacan">Pandacan</option>
                                <option value="Port Area">Port Area</option>
                                <option value="San Miguel">San Miguel</option>
                                <option value="Santa Ana">Santa Ana</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">State (Optional)</label>
                            <input
                                type="text"
                                name="consignee_state"
                                id="new_consignee_state"
                                maxlength="100"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Zip Code</label>
                            <select
                                name="consignee_zip"
                                id="new_consignee_zip"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                                <option value="">Select a city first</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Telephone</label>
                            <input type="text" name="consignee_telephone" id="new_consignee_telephone" maxlength="11" inputmode="numeric" pattern="[0-9]{11}"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        </div>
                    </div>
                </div>

            </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeReservationModal()"
                    class="px-4 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700">
                    Cancel
                </button>

                <button
                    type="submit"
                    name="create_reservation"
                    value="1"
                    class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-ink-800">
                    <i class="ti ti-device-floppy mr-2"></i>
                    Save Reservation
                </button>

            </div>
 
        </form>
 
    </div>
</div>
 
 
<!-- ================= EDIT RESERVATION MODAL ================= -->
<div
    id="editReservationModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-8 py-5 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">

            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    Edit Reservation
                </h2>

                <p class="text-xs text-white/80 dark:text-slate-400 mt-1">
                    Update reservation details
                </p>
            </div>

            <button
                type="button"
                onclick="closeEditReservationModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>

        </div>

        <!-- Form -->
        <form method="POST" onsubmit="return vrdsLockSubmit(this);">

            <input
                type="hidden"
                name="reservation_id"
                id="edit_reservation_id">

            <div class="p-6 pt-8">
            <div class="grid grid-cols-2 gap-8">
             <div class="border-r border-slate-200 dark:border-slate-700 pr-8 space-y-4">

                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Consignor</p>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Consignor
                        </label>
                        <input
                            type="text"
                            name="requestor"
                            id="edit_requestor"
                            required
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Address
                        </label>
                        <input
                            type="text"
                            name="consignor_address"
                            id="edit_consignor_address"
                            required
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Used automatically as the Pickup Location.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Purpose
                        </label>
                        <textarea
                            name="purpose"
                            id="edit_purpose"
                            required
                            rows="3"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Departure Date
                            </label>
                            <input
                                type="date"
                                name="departure_date"
                                id="edit_departure_date"
                                required
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Return Date
                            </label>
                            <input
                                type="date"
                                name="return_date"
                                id="edit_return_date"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                        </div>
                    </div>
                </div>

                <!-- ===================== CONSIGNEE (right) ===================== -->
              <div class="pl-8 space-y-4">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Consignee</p>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Company Name</label>
                        <input
                            type="text"
                            name="consignee_company"
                            placeholder="Enter Company Name"
                            id="edit_consignee_company"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Address <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            name="consignee_address"
                            required
                            placeholder="Enter Address"
                            id="edit_consignee_address"
                            class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Used automatically as the Destination.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">City</label>
                            <select
                                name="consignee_city"
                                id="edit_consignee_city"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:focus:ring-slate-600">
                                <option value="">Select a city</option>
                                <option value="Manila">Manila</option>
                                <option value="Makati">Makati</option>
                                <option value="Quezon City">Quezon City</option>
                                <option value="Las Piñas">Las Piñas</option>
                                <option value="Parañaque">Parañaque</option>
                                <option value="Pasay">Pasay</option>
                                <option value="Pasig">Pasig</option>
                                <option value="Taguig">Taguig</option>
                                <option value="San Juan">San Juan</option>
                                <option value="Pateros">Pateros</option>
                                <option value="Mandaluyong">Mandaluyong</option>
                                <option value="San Andres">San Andres</option>
                                <option value="Santa Mesa">Santa Mesa</option>
                                <option value="Tondo">Tondo</option>
                                <option value="Binondo">Binondo</option>
                                <option value="Sampaloc">Sampaloc</option>
                                <option value="Quiapo">Quiapo</option>
                                <option value="Santa Cruz">Santa Cruz</option>
                                <option value="Ermita">Ermita</option>
                                <option value="Intramuros">Intramuros</option>
                                <option value="Malate">Malate</option>
                                <option value="Paco">Paco</option>
                                <option value="Pandacan">Pandacan</option>
                                <option value="Port Area">Port Area</option>
                                <option value="San Miguel">San Miguel</option>
                                <option value="Santa Ana">Santa Ana</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">State (Optional)</label>
                            <input
                                type="text"
                                name="consignee_state"
                                id="edit_consignee_state"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Zip Code</label>
                            <input
                                type="text"
                                name="consignee_zip"
                                id="edit_consignee_zip"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Telephone</label>
                            <input type="text" name="consignee_telephone" id="edit_consignee_telephone" maxlength="11" inputmode="numeric" pattern="[0-9]{11}"
                                class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 dark:placeholder:text-slate-500">
                        </div>
                    </div>
                </div>

            </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 rounded-b-xl">
                <button
                    type="button"
                    onclick="closeEditReservationModal()"
                    class="px-4 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700">
                    Cancel
                </button>

                <button
                    type="submit"
                    name="update_reservation"
                    value="1"
                    class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-ink-800">
                    <i class="ti ti-device-floppy mr-1"></i>
                    Save Changes
                </button>

            </div>

        </form>

    </div>
</div>

<!-- ================= SHIPMENT DETAILS MODAL (read-only, opened by clicking a row) ================= -->
<div
    id="shipmentDetailsModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">

    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">

        <!-- Header -->
        <div class="flex items-center justify-between px-8 py-5 border-b border-slate-200">

            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    Shipment Details
                </h2>
                <p class="text-xs text-slate-500 mt-1" id="shipment_detail_code">—</p>
            </div>

            <button
                type="button"
                onclick="closeShipmentDetailsModal()"
                class="text-slate-400 hover:text-slate-700 text-xl">
                &times;
            </button>

        </div>

        <div class="p-6 pt-8 space-y-6">

            <div>
                <span id="shipment_detail_status"></span>
            </div>

            <div class="grid grid-cols-2 gap-8">

                <div class="space-y-4">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Consignor</p>

                    <div>
                        <p class="text-xs text-slate-400">Requestor</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_requestor">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Address</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_consignor_address">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Purpose</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_purpose">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Assigned Driver</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_driver">—</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Consignee</p>

                    <div>
                        <p class="text-xs text-slate-400">Company</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_consignee_company">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Address</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_consignee_address">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Telephone</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_consignee_telephone">—</p>
                    </div>
                </div>

            </div>

            <div class="border-t border-slate-200 pt-6">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Trip</p>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-slate-400">Pickup Location</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_pickup">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Destination</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_destination">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Departure Date</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_departure">—</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Return Date</p>
                        <p class="text-sm text-slate-800" id="shipment_detail_return">—</p>
                    </div>
                </div>
            </div>

        </div>

        <div class="px-6 pb-6 flex justify-end">
            <button
                type="button"
                onclick="closeShipmentDetailsModal()"
                class="text-slate-600 border border-slate-200 rounded-lg px-4 py-2 text-sm hover:bg-slate-50">
                Close
            </button>
        </div>

    </div>
</div>

<script>
function openShipmentDetailsModal(data) {
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = (value && value !== '') ? value : '—';
    };

    const statusColors = {
        pending:   'bg-yellow-50 text-yellow-700',
        approved:  'bg-blue-50 text-blue-700',
        completed: 'bg-green-50 text-green-700',
        rejected:  'bg-red-50 text-red-700',
    };
    const statusClass = statusColors[(data.status || '').toLowerCase()] || 'bg-slate-100 text-slate-700';

    document.getElementById('shipment_detail_code').textContent = data.code || '—';
    document.getElementById('shipment_detail_status').innerHTML =
        '<span class="inline-block text-xs px-2 py-1 rounded-full ' + statusClass + '">' +
        (data.status || '—') + '</span>';

    setText('shipment_detail_requestor', data.requestor);
    setText('shipment_detail_consignor_address', data.consignor_address);
    setText('shipment_detail_purpose', data.purpose);
    setText('shipment_detail_driver', data.driver_name);

    setText('shipment_detail_consignee_company', data.consignee_company);

    const addressParts = [data.consignee_address, data.consignee_city, data.consignee_state, data.consignee_zip]
        .filter(part => part && part !== '');
    setText('shipment_detail_consignee_address', addressParts.join(', '));

    setText('shipment_detail_consignee_telephone', data.consignee_telephone);

    setText('shipment_detail_pickup', data.pickup_location);
    setText('shipment_detail_destination', data.destination);
    setText('shipment_detail_departure', data.departure_date);
    setText('shipment_detail_return', data.return_date);

    const modal = document.getElementById('shipmentDetailsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeShipmentDetailsModal() {
    const modal = document.getElementById('shipmentDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

// Close when clicking the dark background
document.addEventListener('click', function(event) {
    const modal = document.getElementById('shipmentDetailsModal');
    if (modal && event.target === modal) {
        closeShipmentDetailsModal();
    }
});

// Close with ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeShipmentDetailsModal();
    }
});
</script>

<!-- ================= NEW DISPATCH MODAL ================= -->
<div
    id="dispatchModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">

    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-xl overflow-hidden">

        <!-- Header -->
        <div class="bg-linear-to-r from-sidebar-blue-900 via-sidebar-blue-600 to-sidebar-blue-900 flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 rounded-t-xl">

            <div>
                <h2 class="text-lg font-semibold text-white dark:text-slate-100">
                    New Dispatch
                </h2>

                <p class="text-xs text-white/80 dark:text-slate-400 mt-1">
                    Assign a vehicle and driver to an approved reservation that couldn't be auto-dispatched
                </p>
            </div>

            <button
                type="button"
                onclick="closeDispatchModal()"
                class="text-white/80 dark:text-slate-500 hover:text-white dark:hover:text-slate-200 text-xl">
                &times;
            </button>

        </div>
 
        <form method="POST" onsubmit="return vrdsLockSubmit(this);">
 
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($newDispatchFormToken) ?>">
 
            <div class="p-6 space-y-4">
 
                <!-- Approved Reservation -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Approved Reservation <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="reservation_id"
                        id="dispatch_reservation"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
 
                        <option value="">Select approved reservation</option>
 
                        <?php
                        $approvedReservations = pg_query($conn, "
                            SELECT
                                r.reservation_id,
                                r.requestor,
                                r.consignor_address,
                                r.pickup_location,
                                r.destination,
                                r.departure_date,
                                r.assigned_driver_id,
                                r.pickup_lat,
                                r.pickup_lng
                            FROM reservations r
                            LEFT JOIN trips t ON t.reservation_id = r.reservation_id
                            WHERE LOWER(r.status) = 'approved'
                              AND t.trip_id IS NULL
                            ORDER BY r.departure_date ASC
                        ");

                        while ($reservation = pg_fetch_assoc($approvedReservations)):
                            // Nearest-first, available-only drivers for THIS
                            // reservation's pickup point (see
                            // vrds_nearby_active_drivers() near the top of this
                            // file). The JS below swaps the Driver <select>'s
                            // options to this list when this option is chosen.
                            $nearbyForReservation = vrds_nearby_active_drivers(
                                $conn,
                                $reservation['pickup_lat'] !== null ? (float) $reservation['pickup_lat'] : null,
                                $reservation['pickup_lng'] !== null ? (float) $reservation['pickup_lng'] : null,
                                $reservation['pickup_location'] ?? null,
                                $reservation['destination'] ?? null
                            );
                        ?>

                            <option
                                value="<?= (int) $reservation['reservation_id'] ?>"
                                data-driver="<?= (int) ($reservation['assigned_driver_id'] ?? 0) ?>"
                                data-drivers="<?= htmlspecialchars(json_encode($nearbyForReservation), ENT_QUOTES) ?>"

                                data-departure="<?= htmlspecialchars(
                                    date('Y-m-d\TH:i', strtotime($reservation['departure_date']))
                                ) ?>">
 
                                <?= htmlspecialchars(
                                    'REQ-' . str_pad(
                                        $reservation['reservation_id'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    )
                                ) ?>
 
                                —
                                <?= htmlspecialchars($reservation['requestor']) ?>
 
                                —
                                <?= htmlspecialchars(
                                    $reservation['pickup_location']
                                    . ' → '
                                    . $reservation['destination']
                                ) ?>
 
                            </option>
 
                        <?php endwhile; ?>
 
                    </select>
                </div>
 
 
                <!-- Reservation Information -->
                <div
                    id="dispatchReservationInfo"
                    class="hidden bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-4">

                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">
                        Reservation Details
                    </p>

                    <div class="text-sm text-slate-700 dark:text-slate-300 space-y-1">
                        <p>
                            <strong>Route:</strong>
                            <span id="dispatchRoute">—</span>
                        </p>

                        <p>
                            <strong>Departure:</strong>
                            <span id="dispatchDeparture">—</span>
                        </p>
                    </div>

                </div>
 
 
                <!-- Vehicle -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Vehicle <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="vehicle_id"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
 
                        <option value="">Select available vehicle</option>
 
                        <?php
                        $vehicleResult = pg_query($conn, "
                            SELECT vehicle_id, plate_number, vehicle_type
                            FROM vehicles
                            WHERE LOWER(status) = 'available'
                            ORDER BY vehicle_id
                        ");
 
                        while ($vehicle = pg_fetch_assoc($vehicleResult)):
                        ?>
 
                            <option value="<?= (int) $vehicle['vehicle_id'] ?>">
                                <?= htmlspecialchars($vehicle['plate_number']) ?>
                                — <?= htmlspecialchars($vehicle['vehicle_type']) ?>
                            </option>
 
                        <?php endwhile; ?>
 
                    </select>
                </div>
 
 
                <!-- Driver -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Driver <span class="text-red-500">*</span>
                    </label>

                    <select
                        name="driver_id"
                        id="dispatch_driver"
                        required
                        class="w-full border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2.5 text-sm bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200">
 
                        <option value="">Select active driver</option>
 
                        <?php
                        // Default list before a reservation is chosen: just
                        // available drivers (active + not already on another
                        // trip), alphabetical since there's no pickup point
                        // yet to measure distance from. Picking a reservation
                        // on the left swaps these options for that
                        // reservation's nearest-first list (see the
                        // data-drivers attribute above and the JS below).
                        $defaultDrivers = vrds_nearby_active_drivers($conn, null, null);
                        foreach ($defaultDrivers as $driver):
                        ?>
 
                            <option value="<?= (int) $driver['driver_id'] ?>">
                                <?= htmlspecialchars($driver['name'] . ($driver['designated_place'] ? ' — ' . $driver['designated_place'] : '')) ?>
                            </option>
 
                        <?php endforeach; ?>
 
                    </select>
                </div>
 
            </div>
 
 
            <!-- Footer -->
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 rounded-b-xl">

                <button
                    type="button"
                    onclick="closeDispatchModal()"
                    class="px-4 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700">
                    Cancel
                </button>

                <button
                    type="submit"
                    name="create_dispatch"
                    value="1"
                    class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-ink-800">
                    <i class="ti ti-device-floppy mr-1"></i>
                    Create Dispatch
                </button>

            </div>

        </form>

    </div>
</div>
 
<script>
const dispatchReservation = document.getElementById('dispatch_reservation');
 
if (dispatchReservation) {
 
    dispatchReservation.addEventListener('change', function () {
 
        const selected = this.options[this.selectedIndex];
 
        const info = document.getElementById('dispatchReservationInfo');
        const route = document.getElementById('dispatchRoute');
        const departure = document.getElementById('dispatchDeparture');
 
        if (!this.value) {
            info.classList.add('hidden');
            route.textContent = '—';
            departure.textContent = '—';
            return;
        }
 
        // Get route text from the option
        const text = selected.textContent.trim();
 
        // Show the selected reservation
        route.textContent = text;
 
        // Show departure from reservation
        const departureValue = selected.dataset.departure;
 
        if (departureValue) {
            const date = new Date(departureValue);
 
            departure.textContent = date.toLocaleString();
        } else {
            departure.textContent = '—';
        }

        // Rebuild the Driver dropdown from THIS reservation's nearest-first,
        // available-only list (embedded server-side as data-drivers — see
        // vrds_nearby_active_drivers() in the PHP above), instead of just
        // picking from the generic default list. Keeps whichever driver was
        // manually assigned on the Reservations list pre-selected, if they're
        // still in the list.
        const driverSelect = document.getElementById('dispatch_driver');
        const assignedDriverId = selected.dataset.driver;
        let nearbyDrivers = [];

        try {
            nearbyDrivers = JSON.parse(selected.dataset.drivers || '[]');
        } catch (e) {
            nearbyDrivers = [];
        }

        if (driverSelect) {
            driverSelect.innerHTML = '<option value="">Select active driver</option>';

            if (nearbyDrivers.length === 0) {
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.disabled = true;
                emptyOpt.textContent = 'No driver designated for this location';
                driverSelect.appendChild(emptyOpt);
            }

            nearbyDrivers.forEach(function (driver) {
                const opt = document.createElement('option');
                opt.value = driver.driver_id;
                opt.textContent = driver.name
                    + (driver.designated_place ? ' — ' + driver.designated_place : '')
                    + (driver.distance_km !== null
                        ? ' (' + driver.distance_km.toFixed(1) + ' km)'
                        : '');
                driverSelect.appendChild(opt);
            });

            if (assignedDriverId && assignedDriverId !== '0') {
                const hasOption = Array.from(driverSelect.options)
                    .some(opt => opt.value === assignedDriverId);

                if (hasOption) {
                    driverSelect.value = assignedDriverId;
                }
            }
        }
 
        info.classList.remove('hidden');
    });
}
</script>
 
 
<!-- ================= DISPATCH MODAL JAVASCRIPT ================= -->
<script>
function openDispatchModal() {
    const modal = document.getElementById('dispatchModal');
 
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
 
function closeDispatchModal() {
    const modal = document.getElementById('dispatchModal');
 
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
 
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeDispatchModal();
    }
});
</script>
 
<script>
// NOTE: Now accepts the 6 additional consignee company parameters
// (consigneeCompany, consigneeAddress, consigneeCity, consigneeState,
// consigneeZip, consigneeTelephone) so the "Edit" button can pre-fill
// them, sourced directly from the reservations table columns.
function openEditReservationModal(
    id,
    requestor,
    consignorAddress,
    purpose,
    departure,
    returnDate,
    consigneeCompany,
    consigneeAddress,
    consigneeCity,
    consigneeState,
    consigneeZip,
    consigneeTelephone
) {
    document.getElementById('edit_reservation_id').value = id;
    document.getElementById('edit_requestor').value = requestor;
    document.getElementById('edit_consignor_address').value = consignorAddress;
    document.getElementById('edit_purpose').value = purpose;
       // DATE ONLY
    document.getElementById('edit_departure_date').value =
        departure ? departure.substring(0, 10) : '';
 
    document.getElementById('edit_return_date').value =
        returnDate ? returnDate.substring(0, 10) : '';

    // NEW: consignee company info
    document.getElementById('edit_consignee_company').value = consigneeCompany || '';
    document.getElementById('edit_consignee_address').value = consigneeAddress || '';
    document.getElementById('edit_consignee_city').value = consigneeCity || '';
    document.getElementById('edit_consignee_state').value = consigneeState || '';
    document.getElementById('edit_consignee_zip').value = consigneeZip || '';
    document.getElementById('edit_consignee_telephone').value = consigneeTelephone || '';
 
    const modal = document.getElementById('editReservationModal');
 
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
 
function closeEditReservationModal() {
    const modal = document.getElementById('editReservationModal');
 
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
 
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditReservationModal();
    }
});
</script>
 
 
<!-- ================= RESERVATION MODAL JAVASCRIPT ================= -->
<script>
function openReservationModal() {
    const modal = document.getElementById('reservationModal');
 
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
 
function closeReservationModal() {
    const modal = document.getElementById('reservationModal');
 
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
 
// Close when clicking the dark background
document.addEventListener('click', function(event) {
    const modal = document.getElementById('reservationModal');
 
    if (modal && event.target === modal) {
        closeReservationModal();
    }
});
 
// Close with ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeReservationModal();
    }
});
</script>
 
<script>
function vrdsLockSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        setTimeout(function () {
            btn.disabled = true;
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = 'Please wait…';
        }, 0);
    }
    return true;
}
 
function vrdsConfirmAndLock(form, message) {
    if (!confirm(message)) {
        return false;
    }
    return vrdsLockSubmit(form);
}
</script>
   <script>
    const CITY_ZIP_MAP = {
        'Manila': ['1000', '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028'],
        'Makati': ['1200', '1201', '1202', '1203', '1204', '1205', '1206', '1207', '1208', '1209', '1210', '1211', '1212', '1213', '1214', '1215', '1216', '1217', '1218', '1219', '1220', '1221', '1222', '1223', '1224', '1225', '1226', '1227', '1228', '1229', '1230', '1231', '1232', '1233', '1234'],
        'Quezon City': ['1100', '1101', '1102', '1103', '1104', '1105', '1106', '1107', '1108', '1109', '1110', '1111', '1112', '1113', '1114', '1115', '1116', '1117', '1118', '1119', '1120', '1121', '1122', '1123', '1124', '1125', '1126', '1127', '1128', '1129', '1130', '1131', '1132', '1133', '1134', '1135', '1136', '1137', '1138', '1139', '1140', '1141', '1142', '1143', '1144', '1145', '1146', '1147', '1148', '1149', '1150', '1151', '1152', '1153', '1154', '1155', '1156', '1157', '1158', '1159', '1160', '1161', '1162', '1163', '1164', '1165', '1166', '1167', '1168', '1169', '1170', '1171', '1172', '1173', '1174', '1175', '1176', '1177', '1178', '1179', '1180', '1181', '1182', '1183', '1184', '1185', '1186', '1187', '1188'],
        'Las Piñas': ['1740', '1741', '1742', '1743', '1744', '1745', '1746', '1747', '1748', '1749', '1750', '1751', '1752', '1753'],
        'Parañaque': ['1700', '1701', '1702', '1703', '1704', '1705', '1706', '1707', '1708', '1709', '1710', '1711', '1712', '1713', '1714', '1715', '1716', '1717', '1718', '1719', '1720', '1721', '1722', '1723', '1724', '1725', '1726', '1727', '1728', '1729', '1730', '1731'],
        'Pasay': ['1300', '1301', '1302', '1303', '1304', '1305', '1306', '1307', '1308', '1309', '1310', '1311', '1312', '1313', '1314', '1315', '1316', '1317', '1318', '1319', '1320', '1321', '1322', '1323', '1324', '1325', '1326', '1327', '1328', '1329'],
        'Pasig': ['1600', '1601', '1602', '1603', '1604', '1605', '1606', '1607', '1608', '1609', '1610', '1611', '1612', '1613', '1614', '1615', '1616', '1617', '1618', '1619', '1620'],
        'Taguig': ['1630', '1631', '1632', '1633', '1634', '1635', '1636', '1637', '1638', '1639', '1640', '1641', '1642', '1643', '1644', '1645', '1646', '1647'],
        'San Juan': ['1500', '1501', '1502', '1503', '1504', '1505', '1506', '1507', '1508', '1509', '1510', '1511', '1512', '1513', '1514', '1515'],
        'Pateros': ['1600', '1601', '1602', '1603', '1604', '1605', '1606', '1607', '1608', '1609', '1610', '1611', '1612', '1613', '1614', '1615', '1616', '1617', '1618', '1619', '1620'],
        'Mandaluyong': ['1550', '1551', '1552', '1553', '1554', '1555', '1556', '1557', '1558', '1559', '1560', '1561', '1562', '1563', '1564', '1565', '1566', '1567', '1568', '1569', '1570', '1571', '1572', '1573', '1574', '1575', '1576', '1577', '1578', '1579'],
        'San Andres': ['1000', '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020'],
        'Santa Mesa': ['1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030', '1031'],
        'Tondo': ['1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030', '1031'],
        'Binondo': ['1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025'],
        'Sampaloc': ['1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030', '1031', '1032', '1033', '1034', '1035', '1036', '1037', '1038', '1039'],
        'Quiapo': ['1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020'],
        'Santa Cruz': ['1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028'],
        'Ermita': ['1000', '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025'],
        'Intramuros': ['1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025'],
        'Malate': ['1004', '1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028'],
        'Paco': ['1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030'],
        'Pandacan': ['1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030', '1031'],
        'Port Area': ['1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030'],
        'San Miguel': ['1005', '1006', '1007', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028'],
        'Santa Ana': ['1009', '1010', '1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019', '1020', '1021', '1022', '1023', '1024', '1025', '1026', '1027', '1028', '1029', '1030', '1031', '1032'],
    };

    function wireCityZipDependency(citySelectId, zipSelectedId) {
        const citySelect = document.getElementById(citySelectId);
        const zipSelect  = document.getElementById(zipSelectedId);
        if (!citySelect || !zipSelect) return;

        function populateZip(city) {
            const zips = CITY_ZIP_MAP[city] || [];

            if (zips.length === 0) {
                zipSelect.innerHTML = '<option value="">Select a city</option>';
                zipSelect.disabled = true;
                return;
            }

            zipSelect.disabled = false;
            zipSelect.innerHTML =
                '<option value="">Select zip code</option>' +
                zips.map(z => `<option value="${z}">${z}</option>`).join('');
        }

        citySelect.addEventListener('change', () => populateZip(citySelect.value));
        populateZip(citySelect.value);
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireCityZipDependency('new_consignee_city', 'new_consignee_zip');
        wireCityZipDependency('edit_consignee_city', 'edit_consignee_zip');
    });
</script>