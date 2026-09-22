<?php

    session_start();

        require_once __DIR__ . '/../config/ftms_db.php';
        require_once __DIR__ . '/../api/auth/otp.php';

            if(empty($_SESSION['pending_pre_auth_token'])) {
                header("Location: login.php");
                exit;
            }

        $code = trim($_POST['code'] ?? '');
        $preAuthToken = $_SESSION['pending_pre_auth_token'];

        $verification = otp_verify_code($conn, $preAuthToken, $code);

            if(!$verification['ok']) {
                $_SESSION['otp_error'] = $verification['reason'];
                    header("Location: verify_otp_web1.php");
                      exit();
            }

        $userId = $verification['user_id'];

        $userResult = pg_query_params($conn, "SELECT user_id, username, role, first_name, last_name, email FROM users WHERE user_id = $1 LIMIT 1", [$userId]);

        $user = pg_fetch_assoc($userResult);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];

            unset($_SESSION['pending_pre_auth_token']);
            unset($_SESSION['pending_user_id']);

            header("Location: ../index.php");
            exit();