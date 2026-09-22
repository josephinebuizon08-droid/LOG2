<?php
/**
 * Odometer-based maintenance monitoring helpers.
 *
 * Require this file wherever a vehicle's odometer can change:
 *   - fleet.php (web: Add/Edit Vehicle)
 *   - api/driver/... (mobile: wherever the driver app logs mileage /
 *     ends a trip / updates the vehicle's odometer)
 *
 * Usage — call this right after ANY update to vehicles.odometer:
 *
 *   require_once __DIR__ . '/maintenance_helper.php';
 *   $check = ftms_check_maintenance_due($conn, $vehicle_id);
 *
 * And call this when a maintenance record for a vehicle is marked
 * "Completed" (resets the odometer baseline for the next interval):
 *
 *   ftms_reset_maintenance_baseline($conn, $vehicle_id);
 */

if (!defined('FTMS_MAINTENANCE_WARNING_KM')) {
    // How many km before the limit to start warning the driver/admin.
    define('FTMS_MAINTENANCE_WARNING_KM', 500);
}

if (!function_exists('ftms_check_maintenance_due')) {

    /**
     * Checks a vehicle's current odometer against its maintenance interval.
     * If the vehicle is near or past its limit (and hasn't already been
     * flagged), this will:
     *   1. Insert a notification for the assigned driver.
     *   2. Insert a 'Needed' row in the maintenance table (if one doesn't
     *      already exist for this vehicle), so it shows up in the
     *      Maintenance tab.
     *   3. Set maintenance_alert_sent = TRUE so it doesn't repeat on every
     *      odometer update until maintenance is completed.
     *
     * Safe to call on every odometer update — it no-ops if no interval is
     * configured for the vehicle, or if already far from due.
     */
    function ftms_check_maintenance_due($conn, int $vehicle_id): array {

        $result = pg_query_params(
            $conn,
            "SELECT vehicle_id, plate_number, odometer, assigned_driver_id,
                    maintenance_interval_km, last_maintenance_odometer,
                    maintenance_alert_sent
             FROM vehicles
             WHERE vehicle_id = $1
             LIMIT 1",
            [$vehicle_id]
        );

        if (!$result || pg_num_rows($result) === 0) {
            return ['checked' => false, 'reason' => 'vehicle_not_found'];
        }

        $vehicle = pg_fetch_assoc($result);

        $interval = $vehicle['maintenance_interval_km'] !== null
            ? (float) $vehicle['maintenance_interval_km']
            : 0;

        if ($interval <= 0) {
            // No interval configured for this vehicle yet — nothing to check.
            return ['checked' => false, 'reason' => 'no_interval_set'];
        }

        $odometer  = (float) $vehicle['odometer'];
        $baseline  = (float) ($vehicle['last_maintenance_odometer'] ?? 0);
        $nextDue   = $baseline + $interval;
        $remaining = $nextDue - $odometer;

        $alertAlreadySent =
            $vehicle['maintenance_alert_sent'] === 't' ||
            $vehicle['maintenance_alert_sent'] === true;

        $response = [
            'checked'           => true,
            'vehicle_id'        => $vehicle_id,
            'odometer'          => $odometer,
            'next_due_odometer' => $nextDue,
            'remaining_km'      => $remaining,
            'is_due'            => $remaining <= 0,
            'is_near_due'       => $remaining <= FTMS_MAINTENANCE_WARNING_KM,
            'notified'          => false,
        ];

        if ($remaining > FTMS_MAINTENANCE_WARNING_KM) {
            // Comfortably far from due. If it was previously flagged
            // (e.g. odometer got corrected downward), clear the flag so a
            // future warning can fire again.
            if ($alertAlreadySent) {
                pg_query_params(
                    $conn,
                    "UPDATE vehicles SET maintenance_alert_sent = FALSE WHERE vehicle_id = $1",
                    [$vehicle_id]
                );
            }
            return $response;
        }

        // Near or past the limit — only act once per cycle, until the
        // maintenance is completed (ftms_reset_maintenance_baseline).
        if (!$alertAlreadySent) {

            $driver_id = $vehicle['assigned_driver_id'];
            $plate     = $vehicle['plate_number'];

            if ($remaining <= 0) {
                $title   = 'Maintenance Overdue';
                $message = "Vehicle {$plate} has reached its maintenance limit ("
                    . number_format($odometer) . " km). Please bring it in for maintenance.";
            } else {
                $title   = 'Maintenance Due Soon';
                $message = "Vehicle {$plate} is " . number_format($remaining)
                    . " km away from its maintenance limit. Please schedule maintenance soon.";
            }

            // 1) Notify the assigned driver (if the vehicle has one).
            //    Your notifications table doesn't have a vehicle_id column,
            //    so the plate number is folded into the message text instead.
            if ($driver_id !== null) {
                pg_query_params(
                    $conn,
                    "INSERT INTO notifications (driver_id, type, title, message, is_read)
                     VALUES ($1, 'maintenance', $2, $3, FALSE)",
                    [$driver_id, $title, $message]
                );
            }

            // 2) Reflect it in the maintenance table (avoid duplicate
            //    'Needed' rows for the same vehicle).
            $existingNeeded = pg_query_params(
                $conn,
                "SELECT maintenance_id FROM maintenance
                 WHERE vehicle_id = $1 AND status = 'Scheduled'
                 LIMIT 1",
                [$vehicle_id]
            );

            if (!$existingNeeded || pg_num_rows($existingNeeded) === 0) {
                pg_query_params(
                    $conn,
                    "INSERT INTO maintenance
                        (vehicle_id, maintenance_type, maintenance_date, cost, status)
                     VALUES ($1, 'Odometer-based Maintenance', CURRENT_DATE, 0, 'Scheduled')",
                    [$vehicle_id]
                );
            }

            // 3) Flag so we don't insert duplicates on every odometer update.
            pg_query_params(
                $conn,
                "UPDATE vehicles SET maintenance_alert_sent = TRUE WHERE vehicle_id = $1",
                [$vehicle_id]
            );

            $response['notified'] = true;
        }

        return $response;
    }
}

if (!function_exists('ftms_reset_maintenance_baseline')) {

    /**
     * Call this whenever a maintenance record for a vehicle is marked
     * "Completed". Resets the odometer baseline so the next interval
     * counts from here, clears the alert flag, and removes any
     * auto-generated 'Needed' placeholder rows for that vehicle.
     */
    function ftms_reset_maintenance_baseline($conn, int $vehicle_id): void {

        pg_query_params(
            $conn,
            "UPDATE vehicles
             SET last_maintenance_odometer = odometer,
                 maintenance_alert_sent = FALSE,
                 last_maintenance_date = CURRENT_DATE
             WHERE vehicle_id = $1",
            [$vehicle_id]
        );

        pg_query_params(
            $conn,
            "DELETE FROM maintenance
             WHERE vehicle_id = $1 AND status = 'Scheduled'
               AND maintenance_type = 'Odometer-based Maintenance'
               AND cost = 0",
            [$vehicle_id]
        );
    }
}

if (!function_exists('ftms_maintenance_badge')) {

    function ftms_maintenance_badge(?float $intervalKm, float $baseline, float $odometer): ?array {

        if ($intervalKm === null || $intervalKm <= 0) {
            return null;
        }

        $remaining = ($baseline + $intervalKm) - $odometer;

        if ($remaining <= 0) {
            return [
                'label' => 'Maintenance overdue',
                'class' => 'bg-red-50 text-red-600 border border-red-200',
            ];
        }

        if ($remaining <= FTMS_MAINTENANCE_WARNING_KM) {
            return [
                'label' => number_format($remaining) . ' km to maintenance',
                'class' => 'bg-amber-50 text-amber-600 border border-amber-200',
            ];
        }

        return null;
    }
}