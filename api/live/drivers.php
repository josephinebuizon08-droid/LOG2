<?php

    session_start();
    header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Not logged in.']);
            exit;
        }

            require_once __DIR__ . '/../../config/ftms_db.php';
            require_once __DIR__ . '/../../auth/session_guard.php';
            ftms_enforce_session_timeout_json();

        $result = pg_query($conn, "
            SELECT
                d.driver_id,
                d.first_name,
                d.last_name,
                t.trip_id,
                t.status,
                v.vehicle_id,
                v.plate_number,
                v.current_lat,
                v.current_lng,
                v.last_location_update
            FROM trips t
            JOIN drivers d ON d.driver_id = t.driver_id
            LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
            WHERE t.status = 'In Transit'
            AND v.current_lat IS NOT NULL
            AND v.current_lng IS NOT NULL
            ORDER BY d.driver_id
        ");

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to load driver locations.']);
            exit;
        }

    $drivers = [];
        while ($row = pg_fetch_assoc($result)) {
            $drivers[] = [
                'driver_id'   => (int) $row['driver_id'],
                'driver_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'trip_id'     => (int) $row['trip_id'],      
                'vehicle_id'  => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
                'plate_number' => $row['plate_number'],
                'latitude'    => (float) $row['current_lat'],
                'longitude'   => (float) $row['current_lng'],
                'status'      => $row['status'],
                'updated_at'  => $row['last_location_update'],
            ];
        }

echo json_encode(['success' => true, 'drivers' => $drivers]);