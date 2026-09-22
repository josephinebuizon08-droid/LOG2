<?php
/**
 * api/auth/api_authenticate.php
 *
 * Shared authentication helper for mobile (driver) REST endpoints.
 *
 * FIXED VERSION -- the copy that was live had two bugs in the same query:
 *   1. A trailing comma before FROM ("d.driver_id, FROM ...") -- invalid
 *      SQL syntax on its own.
 *   2. The "LEFT JOIN drivers d ON d.user_id = u.user_id" line was
 *      missing entirely, even though d.driver_id was still selected --
 *      so the "d" alias didn't exist anywhere in the query.
 * Together these meant pg_query_params() always failed, which the code
 * then (correctly) treated as "invalid token" -- so EVERY request with
 * ANY token, valid or not, was rejected with 401. That's almost
 * certainly the root cause of what you've been seeing.
 *
 * This is separate from auth/authentication.php (the web dispatcher's
 * $_SESSION-based login) because that's cookie/session based and doesn't
 * suit a stateless mobile REST client. It is also separate from the
 * FTMS_API_KEY used by api/update_vehicle_location.php, which is a single
 * shared machine credential for the GPS simulator dev tool, not a per-user
 * identity.
 *
 * Usage in any api/driver/*.php endpoint (note: this file now lives at
 * api/auth/, one level up from api/driver/, not two):
 *
 *   require_once __DIR__ . '/../auth/api_authenticate.php';
 *   $auth = api_authenticate_driver($conn); // exits with 401/403 if invalid
 *   $driverId = $auth['driver_id'];
 *   $userId   = $auth['user_id'];
 *
 * The authenticated driver_id/user_id come ONLY from this lookup -- never
 * from $_POST/$_GET/JSON body -- so a client cannot request another
 * driver's data by changing an ID in the request.
 */

function api_respond_error(int $httpStatus, string $message): void {
    http_response_code($httpStatus);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/**
 * Reads the Authorization: Bearer <token> header, looks it up in
 * api_tokens, and returns the associated user_id + driver_id (if the user
 * is a driver). Exits the request with a 401 JSON response if the token is
 * missing, unknown, revoked, or expired.
 *
 * @return array{user_id:int, driver_id:?int, role:string}
 */
function api_authenticate($conn): array {
    $authHeader = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        // Some PHP/Apache setups strip the Authorization header from
        // $_SERVER; apache_request_headers() is the fallback used there.
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';
    }

    if ($authHeader === '' || stripos($authHeader, 'Bearer ') !== 0) {
        api_respond_error(401, 'Missing or invalid Authorization header.');
    }

    $token = trim(substr($authHeader, 7));

    if ($token === '') {
        api_respond_error(401, 'Missing bearer token.');
    }

    $result = pg_query_params(
        $conn,
        "SELECT t.user_id, u.role, d.driver_id
         FROM api_tokens t
         JOIN users u ON u.user_id = t.user_id
         LEFT JOIN drivers d ON d.user_id = u.user_id
         WHERE t.token = $1
           AND t.revoked_at IS NULL
           AND t.expires_at > CURRENT_TIMESTAMP
         LIMIT 1",
        [$token]
    );

    if (!$result || pg_num_rows($result) === 0) {
        api_respond_error(401, 'Session expired or invalid. Please log in again.');
    }

    $row = pg_fetch_assoc($result);

    return [
        'user_id'   => (int) $row['user_id'],
        'driver_id' => $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
        'role'      => $row['role'],
    ];
}

/**
 * Convenience wrapper for endpoints that specifically require a driver
 * (not just any authenticated user). Exits with 403 if the authenticated
 * user has no matching drivers row.
 *
 * @return array{user_id:int, driver_id:int, role:string}
 */
function api_authenticate_driver($conn): array {
    $auth = api_authenticate($conn);

    if ($auth['driver_id'] === null) {
        api_respond_error(403, 'This account is not registered as a driver.');
    }

    return $auth;
}
