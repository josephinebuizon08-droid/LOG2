<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

function respond(int $httpStatus, array $body): void {
    http_response_code($httpStatus);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$notificationId = isset($input['notification_id']) ? (int) $input['notification_id'] : 0;
if ($notificationId <= 0) {
    respond(400, ['success' => false, 'message' => 'notification_id is required.']);
}

$result = pg_query_params(
    $conn,
    "UPDATE notifications
     SET is_read = TRUE
     WHERE notification_id = $1 AND driver_id = $2",
    [$notificationId, $driverId]
);

if (!$result) {
    respond(500, ['success' => false, 'message' => 'Failed to update notification.']);
}

if (pg_affected_rows($result) === 0) {
    respond(404, ['success' => false, 'message' => 'Notification not found.']);
}

respond(200, ['success' => true, 'message' => 'Notification marked as read.']);