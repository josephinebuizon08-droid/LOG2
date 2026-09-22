<?php

    if(session_status() === PHP_SESSION_NONE) {
        session_start();
    }

        header('Content-Type: application/json');

        require_once __DIR__ . '/session_guard.php';

            if(!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode([
                    'success'  => false,
                    'message'  => 'Not logged in.'
                ]);
            }

            if(ftms_session_timed_out()) {
                http_response_code(401);
                echo json_encode([
                    'success'  => false,
                    'message'  => 'session_expired'
                ]);
                exit;
            }

        echo json_encode([
            'success'  => true
        ]);