<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/ftms_db.php';
require_once __DIR__ . '/../auth/api_authenticate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

// Handle mark as read functionality
if (isset($_GET['mark_read'])) {
    $notificationId = intval($_GET['mark_read']);
    
    // Update the notification as read
    $updateSql = "UPDATE notifications SET is_read = TRUE WHERE notification_id = $1 AND driver_id = $2";
    $updateResult = pg_query_params($conn, $updateSql, [$notificationId, $driverId]);
    
    if (!$updateResult) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read.']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    exit;
}

// Handle mark all as read functionality
if (isset($_GET['mark_all_read']) && $_GET['mark_all_read'] === '1') {
    $updateAllSql = "UPDATE notifications SET is_read = TRUE WHERE driver_id = $1";
    $updateAllResult = pg_query_params($conn, $updateAllSql, [$driverId]);
    
    if (!$updateAllResult) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to mark all notifications as read.']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'All notifications marked as read.']);
    exit;
}

$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';

$sql = "
    SELECT notification_id, title, message, type, is_read, created_at
    FROM notifications
    WHERE driver_id = $1
";
if ($unreadOnly) {
    $sql .= " AND is_read = FALSE";
}
$sql .= " ORDER BY created_at DESC";

$result = pg_query_params($conn, $sql, [$driverId]);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load notifications.']);
    exit;
}

$notifications = [];
while ($row = pg_fetch_assoc($result)) {
    $notifications[] = [
        'notification_id' => (int) $row['notification_id'],
        'title'           => $row['title'],
        'message'         => $row['message'],
        'type'            => $row['type'],
        'is_read'         => $row['is_read'] === 't' || $row['is_read'] === true,
        'created_at'      => $row['created_at'],
    ];
}

echo json_encode(['success' => true, 'notifications' => $notifications]);