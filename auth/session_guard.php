<?php

    if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
        define('SESSION_IDLE_TIMEOUT_SECONDS', 30 * 60); // 30 minutes idle
    }

        function ftms_destroy_session(): void {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }

        function ftms_session_timed_out(): bool {
            if (!isset($_SESSION['user_id'])) {
                return false; // nothing to time out
            }

            $lastActivity = $_SESSION['last_activity'] ?? null;

            if ($lastActivity !== null && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
                ftms_destroy_session();
                return true;
            }

            $_SESSION['last_activity'] = time();
            return false;
        }

        function ftms_enforce_session_timeout(string $loginPath = 'auth/login.php'): void {
            if (ftms_session_timed_out()) {
                session_start();
                $_SESSION['login_error'] = 'Your session has expired due to inactivity. Please sign in again.';
                header('Location: ' . $loginPath);
                exit();
            }
        }

    function ftms_enforce_session_timeout_json(): void {
        if (ftms_session_timed_out()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => 'session_expired',
                'message' => 'Your session has expired due to inactivity. Please sign in again.',
            ]);
            exit();
        }
    }