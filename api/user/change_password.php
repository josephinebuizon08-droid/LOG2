<?php

    header('Content-Type: application/json');
    // Session is already started in index.php

    if(session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../../config/ftms_db.php';
    require_once __DIR__ . '/../../auth/session_guard.php';

// Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    ftms_enforce_session_timeout_json();

    $userId = $_SESSION['user_id'];

    // Handle POST request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';
        
        // Validate required fields
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            echo json_encode(['success' => false, 'message' => 'All password fields are required']);
            exit();
        }
        
        // Validate password match
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
            exit();
        }
        
        // Validate password strength
        if (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long']);
            exit();
        }
        
        // Verify current password
        $verifyQuery = pg_query_params($conn,
            "SELECT password FROM users WHERE user_id = $1",
            [$userId]
        );
        
        if (!$verifyQuery || pg_num_rows($verifyQuery) === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        $currentHash = pg_fetch_result($verifyQuery, 0, 0);
        
        if (!password_verify($currentPassword, $currentHash)) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit();
        }
        
        // Update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateQuery = pg_query_params($conn,
            "UPDATE users SET password = $1 WHERE user_id = $2",
            [$newHash, $userId]
        );
        
        if ($updateQuery) {
            echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to change password']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    }
?>