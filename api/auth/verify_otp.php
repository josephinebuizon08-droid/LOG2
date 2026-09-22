<?php
/**
 * api/verify_otp.php
 *
 * Step 2 of the MFA login handshake (see auth/otp.php + api/api_login.php
 * for step 1). Takes the pre_auth_token issued by api_login.php plus the
 * 6-digit code the user typed in, and -- only if that checks out -- issues
 * the real api_tokens bearer token, exactly like api_login.php used to do
 * directly before MFA was added. Response shape matches what
 * api_login.php returned pre-MFA, so the app's existing
 * AuthResult/User parsing didn't need to change, just where it's called
 * from in the login flow.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$preAuthToken = trim($_POST['pre_auth_token'] ?? '');
$code = trim($_POST['code'] ?? '');

if ($preAuthToken === '' || $code === '') {
    echo json_encode(['success' => false, 'message' => 'pre_auth_token and code are required.']);
    exit;
}

$verification = otp_verify_code($conn, $preAuthToken, $code);

if (!$verification['ok']) {
    echo json_encode(['success' => false, 'message' => $verification['reason']]);
    exit;
}

$userId = $verification['user_id'];

$userResult = pg_query_params(
    $conn,
    "SELECT user_id, username, email, role, first_name, last_name FROM users WHERE user_id = $1 LIMIT 1",
    [$userId]
);

if (!$userResult || pg_num_rows($userResult) === 0) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

$user = pg_fetch_assoc($userResult);

// Same token issuance api_login.php used to do directly -- unchanged
// other than now happening after MFA instead of right after the password
// check.
$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

$tokenResult = pg_query_params(
    $conn,
    "INSERT INTO api_tokens (token, user_id, expires_at) VALUES ($1, $2, $3)",
    [$token, $user['user_id'], $expiresAt]
);

if (!$tokenResult) {
    echo json_encode(['success' => false, 'message' => 'Verified, but session could not be created. Please try again.']);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Login successful.",
    "token" => $token,
    "user" => [
        "id" => $user['user_id'],
        "name" => $user['username'],
        "email" => $user['email'],
        "role" => $user['role'],
        "first_name" => $user['first_name'],
        "last_name" => $user['last_name']
    ]
]);
