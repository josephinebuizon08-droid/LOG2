<?php
/**
 * api/resend_otp.php
 *
 * Lets the app request a fresh code for an in-progress login without
 * starting over from username/password. Rate-limited server-side (see
 * OTP_RESEND_COOLDOWN_SECONDS in auth/otp.php) regardless of what the app
 * does on its end -- a client-side cooldown timer alone isn't enough,
 * since a client can always be bypassed.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../../api/auth/otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$preAuthToken = trim($_POST['pre_auth_token'] ?? '');

if ($preAuthToken === '') {
    echo json_encode(['success' => false, 'message' => 'pre_auth_token is required.']);
    exit;
}

$result = otp_resend($conn, $preAuthToken);

if (!$result['ok']) {
    echo json_encode(['success' => false, 'message' => $result['reason']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'A new code was sent to your email.',
    'expires_in_seconds' => OTP_EXPIRY_MINUTES * 60
]);
