<?php

    header('Content-Type: application/json');
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: Content-Type");

    require_once __DIR__ . '/../api/auth/otp.php';
    require_once __DIR__ . '/../config/ftms_db.php';

    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode([
            "success" => false,
            "message" => "Only POST request are allowed."
        ]);
        exit;
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode([
            "success" => false,
            "message" => "Username and password are required."
        ]);
        exit;
    }

   $query = "SELECT user_id, username, password, email, role, first_name, last_name FROM users WHERE username = $1 LIMIT 1";

   
    $result = pg_query_params($conn, $query, [$username]);

    if (!$result) {
        echo json_encode([
            "success" => false,
            "message" => "Database query failed."
        ]);
        exit;
    }

    $user = pg_fetch_assoc($result);

    if (!$user) {
        echo json_encode([
            "success" => false, 
            "message" => "Invalid username or password."
        ]);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid username or password."
    ]);
    exit;
    }

    $otpChallenge = otp_start_challenge(
        $conn,
        (int)$user['user_id'],
        $user['email'],
        $user['first_name']
    );

    if ($otpChallenge === null) {
        echo json_encode([
            "success" => false,
            "message" => "Unable to send OTP. Please try again."
        ]);
        exit;
    }

        echo json_encode([
            "success" => true,
            "message" => "OTP sent successfully.",
            "requires_otp" => true,
            "pre_auth_token" => $otpChallenge['pre_auth_token'],
            "expires_in_seconds" => $otpChallenge['expires_in_seconds'],
            "user" => [
                "id" => $user['user_id'],
                "name" => $user['username'],
                "email" => otp_mask_email($user['email']),
                "role" => $user['role'],
                "first_name" => $user['first_name'],
                "last_name" => $user['last_name']
            ]
        ]);

?>