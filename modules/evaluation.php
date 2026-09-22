<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

    require_once __DIR__ . '/../config/ftms_db.php';
    require_once __DIR__ . '/../auth/rbac.php';
    require_once __DIR__ . '/../auth/session_guard.php';
    ftms_enforce_session_timeout_json();

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input) || empty($input['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $action = $input['action'];

    try {
        switch ($action) {

        case 'create': {
            $tripId      = (int) ($input['trip_id'] ?? 0);
            $supervisor  = trim($input['supervisor'] ?? '');
            $timeliness  = trim($input['timeliness'] ?? '') ?: null;
            $completion  = trim($input['completion'] ?? '') ?: null;
            $rating      = $input['rating'] !== '' && isset($input['rating']) ? (float) $input['rating'] : null;
            $kpi         = trim($input['kpi'] ?? '') ?: null;
            $evalDate    = trim($input['eval_date'] ?? '');

            if (!$tripId || !$supervisor || !$evalDate) {
                throw new Exception('Trip, supervisor, and date are required.');
            }

            $res = pg_query_params(
                $conn,
                "INSERT INTO evaluations (trip_id, supervisor, timeliness, completion, rating, kpi, eval_date)
                VALUES ($1, $2, $3, $4, $5, $6, $7)
                RETURNING evaluation_id",
                [$tripId, $supervisor, $timeliness, $completion, $rating, $kpi, $evalDate]
            );

            if (!$res) {
                throw new Exception(pg_last_error($conn) ?: 'Insert failed.');
            }

            $row = pg_fetch_assoc($res);
            echo json_encode(['success' => true, 'evaluation_id' => $row['evaluation_id']]);
            break;
        }

        case 'update': {
            $evalId      = (int) ($input['evaluation_id'] ?? 0);
            $tripId      = (int) ($input['trip_id'] ?? 0);
            $supervisor  = trim($input['supervisor'] ?? '');
            $timeliness  = trim($input['timeliness'] ?? '') ?: null;
            $completion  = trim($input['completion'] ?? '') ?: null;
            $rating      = $input['rating'] !== '' && isset($input['rating']) ? (float) $input['rating'] : null;
            $kpi         = trim($input['kpi'] ?? '') ?: null;
            $evalDate    = trim($input['eval_date'] ?? '');

            if (!$evalId || !$tripId || !$supervisor || !$evalDate) {
                throw new Exception('Trip, supervisor, and date are required.');
            }

            $res = pg_query_params(
                $conn,
                "UPDATE evaluations
                SET trip_id = $1, supervisor = $2, timeliness = $3, completion = $4,
                    rating = $5, kpi = $6, eval_date = $7
                WHERE evaluation_id = $8",
                [$tripId, $supervisor, $timeliness, $completion, $rating, $kpi, $evalDate, $evalId]
            );

            if (!$res) {
                throw new Exception(pg_last_error($conn) ?: 'Update failed.');
            }

            echo json_encode(['success' => true]);
            break;
        }

        case 'delete': {
            // Admin only. Runs BEFORE reading evaluation_id -- this is the
            // real security boundary, not just the hidden delete button in
            // monitoring.php. A Fleet Manager calling this endpoint directly
            // (bypassing the UI entirely) still gets rejected here.
            require_admin(true); // JSON mode -- this endpoint is called via fetch(), never a form

            $evalId = (int) ($input['evaluation_id'] ?? 0);

            if (!$evalId) {
                throw new Exception('Missing evaluation id.');
            }

            $res = pg_query_params($conn, "DELETE FROM evaluations WHERE evaluation_id = $1", [$evalId]);

            if (!$res) {
                throw new Exception(pg_last_error($conn) ?: 'Delete failed.');
            }

            echo json_encode(['success' => true]);
            break;
        }

        default:
            throw new Exception('Unknown action.');
    }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
}