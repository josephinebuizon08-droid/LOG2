<?php

    header('Content-Type: application/json');
    
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
        
        $firstName = pg_escape_string($data['first_name'] ?? '');
        $lastName = pg_escape_string($data['last_name'] ?? '');
        $email = pg_escape_string($data['email'] ?? '');
        
        // Validate required fields
        if (empty($firstName) || empty($lastName) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'First name, last name, and email are required']);
            exit();
        }
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit();
        }
        
        // Check if email is already taken by another user
        $emailCheck = pg_query_params($conn,
            "SELECT user_id FROM users WHERE email = $1 AND user_id != $2",
            [$email, $userId]
        );
        
        if (pg_num_rows($emailCheck) > 0) {
            echo json_encode(['success' => false, 'message' => 'Email is already in use by another account']);
            exit();
        }
        
        // Update user profile
        $updateQuery = pg_query_params($conn,
            "UPDATE users SET first_name = $1, last_name = $2, email = $3 WHERE user_id = $4",
            [$firstName, $lastName, $email, $userId]
        );
        
        if ($updateQuery) {
            // Update session data
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $_SESSION['email'] = $email;
            
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    }
?>