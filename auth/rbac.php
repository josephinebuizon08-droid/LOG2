<?php

    function current_role(): string {
        return strtolower(trim($_SESSION['role'] ?? ''));
    }

    function is_admin(): bool {
        $role = current_role();
        return $role === 'admin' || $role === 'administrator';
    }


    function is_fleet_manager(): bool {
        $role = current_role();
        return $role === 'fleet manager' || $role === 'fleet_manager' || $role === 'fleetmanager';
    }

    function require_admin(bool $json = false): void {
        if (!is_admin()) {
            http_response_code(403);

            if ($json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Only Administrators can perform this action.']);
                exit;
            }

            echo '<div style="padding:60px 20px;text-align:center;font-family:sans-serif;">
                    <h2 style="color:#b91c1c;">Access Denied</h2>
                    <p style="color:#4b5563;">Only Administrators can perform this action.</p>
                </div>';
            exit;
        }
    }