<?php

    header('Content-Type: application/json');
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: Content-Type");

        require_once __DIR__ . '/../config/ftms_db.php';

            if($_SERVER['REQUEST_METHOD'] != 'POST') {
                echo json_encode([
                    "success" => false,
                    "message" => "Only POST requests are allowed."
                ]);
                exit;
            }

            $password = $_POST['password'] ?? '';
            $firstName = $_POST['first_name'] ?? '';
            $lastName = $_POST['last_name'] ?? '';
            $email = $_POST['email']  ?? '';
            $role = $_POST['role']  ?? 'customer';

            if(empty($password) || empty($email) || empty($password)) {
                echo json_encode([
                    "success" => false,
                    "message" => "All fields are required."
                ]);
                exit;
            }

        $checkQuery = "SELECT user_id FROM users WHERE email = $1 LIMIT 1";
        $checkResult = pg_query_params($conn, $checkQuery, [$email]);

            if(pg_fetch_assoc($checkResult)){
                echo json_encode([
                    "success" => false,
                    "message" => "An account with this email already exists."
                ]);
                exit;
            }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insertQuery = "INSERT INTO users (username, password, email, role, first_name, last_name) 
                VALUES ($1, $2, $3, $4, $5, $6) 
                RETURNING user_id, username, email, role, first_name, last_name";
            $insertResult = pg_query_params($conn, $insertQuery, [$email, $hashedPassword, $email, $role, $firstName, $lastName]);

        if(!$insertQuery) { 
            echo json_encode([
                "success" => false,
                "message" => "Failed to create account."
            ]);
            exit;
        }

            $newUser = pg_fetch_assoc($insertResult);
            echo json_encode([
                "success" => true,
                "message" => "Account create successfully.",
                "token" => bin2hex(random_bytes(16)),
                "user" => [
                    "id" => $newUser['user_id'],
                    "name" => $newUser['first_name'] . ' ' . $newUser['last_name'],
                    "first_name" => $newUser['first_name'],
                    "last_name" => $newUser['last_name'],
                    "email" => $newUser['email'],
                    "role" => $newUser['role']
                ]
            ]);
       
    ?>
        



