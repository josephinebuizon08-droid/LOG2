<?php
/* JSON endpoint for the Evaluations tab (Driver & Trip Performance Monitoring).
 *
 *   POST  modules/evaluations_actions.php   body: JSON
 *     action = create | update | delete
 *
 * The overall score and performance level are always recomputed here from the
 * five 1-5 scores (see evaluation_scheme.php) -- whatever the browser sends for
 * rating/kpi is ignored. rating = overall score, kpi = performance level, so the
 * Performance tab and driver popup keep working.
 */

// Nothing but JSON may leave this file: buffer any stray output/warnings and drop it.
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function eval_respond(int $status, array $body): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/evaluation_scheme.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eval_respond(405, ['success' => false, 'error' => 'POST only.']);
}
if (empty($_SESSION)) {
    eval_respond(401, ['success' => false, 'error' => 'You are not signed in. Please log in again.']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    eval_respond(400, ['success' => false, 'error' => 'Invalid request.']);
}
$action = $in['action'] ?? '';

// ---- delete (admin only, same rule as the trash button in the table) --------
if ($action === 'delete') {
    if (!function_exists('is_admin') || !is_admin()) {
        eval_respond(403, ['success' => false, 'error' => 'Only an administrator can delete evaluations.']);
    }
    $id = (int) ($in['evaluation_id'] ?? 0);
    if ($id <= 0) {
        eval_respond(400, ['success' => false, 'error' => 'Missing evaluation id.']);
    }
    $ok = pg_query_params($conn, 'DELETE FROM evaluations WHERE evaluation_id = $1', [$id]);
    if (!$ok) {
        eval_respond(500, ['success' => false, 'error' => 'Database error: ' . pg_last_error($conn)]);
    }
    eval_respond(200, ['success' => true]);
}

if ($action !== 'create' && $action !== 'update') {
    eval_respond(400, ['success' => false, 'error' => 'Unknown action.']);
}

// ---- validate ---------------------------------------------------------------
$tripId   = (int) ($in['trip_id'] ?? 0);
$evalDate = trim((string) ($in['eval_date'] ?? ''));
$comments = trim((string) ($in['comments'] ?? ''));
if (function_exists('mb_substr')) {
    $comments = mb_substr($comments, 0, 500);
} else {
    $comments = substr($comments, 0, 500);
}

if ($tripId <= 0) {
    eval_respond(422, ['success' => false, 'error' => 'Please select a trip.']);
}
$d = DateTime::createFromFormat('Y-m-d', $evalDate);
if (!$d || $d->format('Y-m-d') !== $evalDate) {
    eval_respond(422, ['success' => false, 'error' => 'Please enter a valid date.']);
}

$tripCheck = pg_query_params($conn, 'SELECT 1 FROM trips WHERE trip_id = $1', [$tripId]);
if (!$tripCheck || pg_num_rows($tripCheck) === 0) {
    eval_respond(422, ['success' => false, 'error' => 'That trip does not exist.']);
}

$scores  = [];
$overall = 0.0;
foreach ($evalAreas as $area) {
    $v = (int) ($in[$area['key']] ?? 0);
    if ($v < 1 || $v > 5) {
        eval_respond(422, ['success' => false, 'error' => 'Please score every area from 1 to 5 (' . $area['label'] . ' is missing).']);
    }
    $scores[$area['key']] = $v;
    $overall += $v * $area['weight'];
}
$overall = round($overall, 2);
$level   = eval_level($overall, $evalLevels);

// ---- write ------------------------------------------------------------------
if ($action === 'create') {
    $ok = pg_query_params(
        $conn,
        'INSERT INTO evaluations
            (trip_id, eval_date, rating, kpi, comments,
             safety_score, reliability_score, vehicle_management_score, service_score, professionalism_score)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)',
        [
            $tripId, $evalDate, $overall, $level['label'], $comments,
            $scores['safety_score'], $scores['reliability_score'], $scores['vehicle_management_score'],
            $scores['service_score'], $scores['professionalism_score'],
        ]
    );
} else {
    $id = (int) ($in['evaluation_id'] ?? 0);
    if ($id <= 0) {
        eval_respond(400, ['success' => false, 'error' => 'Missing evaluation id.']);
    }
    $ok = pg_query_params(
        $conn,
        'UPDATE evaluations SET
            trip_id = $1, eval_date = $2, rating = $3, kpi = $4, comments = $5,
            safety_score = $6, reliability_score = $7, vehicle_management_score = $8,
            service_score = $9, professionalism_score = $10
         WHERE evaluation_id = $11',
        [
            $tripId, $evalDate, $overall, $level['label'], $comments,
            $scores['safety_score'], $scores['reliability_score'], $scores['vehicle_management_score'],
            $scores['service_score'], $scores['professionalism_score'], $id,
        ]
    );
}

if (!$ok) {
    eval_respond(500, ['success' => false, 'error' => 'Database error: ' . pg_last_error($conn)]);
}

eval_respond(200, ['success' => true, 'rating' => $overall, 'level' => $level['label']]);