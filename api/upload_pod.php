<?php
/**
 * POST /api/upload_pod.php
 * Multipart form-data body:
 *   order_id : string/int  (the trip_id this proof of delivery is for)
 *   notes    : string      (optional)
 *   photo    : file        (required — the delivery photo)
 *
 * Validates, in order:
 *   1. Authenticated driver (token -> driver_id, never trusted from body)
 *   2. order_id (trip_id) is provided and belongs to that driver
 *   3. trip is currently 'In Transit' (POD can only be filed for a trip
 *      that's actually in progress -- mirrors location.php's rule)
 *   4. a photo file was actually uploaded and is a real image
 *
 * On success: saves the photo to disk under uploads/pod/, inserts a row
 * into trip_proofs (trip_id, proof_type, file_url, uploaded_at), and
 * (if the column exists) marks the trip's proof_of_delivery_submitted
 * flag so the dispatcher UI can show at a glance whether POD was filed.
 *
 * NOTE: this endpoint does NOT complete the trip. The Flutter app should
 * still call /api/driver/complete_trip.php separately once POD succeeds,
 * same as it already does for Navigator.pop(context, true) back to
 * MapScreen.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../config/ftms_db.php';
require_once __DIR__ . '/auth/api_authenticate.php';

function respond(int $httpStatus, array $body): void {
    http_response_code($httpStatus);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

$auth = api_authenticate_driver($conn);
$driverId = $auth['driver_id'];

// ---- Validate order_id (trip_id) ----
$tripId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
if ($tripId <= 0) {
    respond(400, ['success' => false, 'message' => 'order_id is required.']);
}

$notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';

// ---- Confirm the trip belongs to this driver and is In Transit ----
// Same ownership + status rule as location.php, so a driver can't file a
// POD against someone else's trip or one that hasn't started / already
// completed.
$tripCheck = pg_query_params(
    $conn,
    "SELECT trip_id, status FROM trips WHERE trip_id = $1 AND driver_id = $2 LIMIT 1",
    [$tripId, $driverId]
);

if (!$tripCheck || pg_num_rows($tripCheck) === 0) {
    respond(403, ['success' => false, 'message' => 'This trip is not assigned to you.']);
}

$trip = pg_fetch_assoc($tripCheck);

if (strtolower(trim((string) $trip['status'])) !== 'in transit') {
    respond(409, [
        'success' => false,
        'message' => 'Proof of delivery can only be submitted while the trip is In Transit. Current status: ' . $trip['status'],
    ]);
}

// ---- Validate the uploaded photo ----
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    $message = $uploadError === UPLOAD_ERR_NO_FILE
        ? 'A delivery photo is required.'
        : 'Photo upload failed (error code ' . $uploadError . ').';
    respond(400, ['success' => false, 'message' => $message]);
}

$photo = $_FILES['photo'];

// Cap at 10MB — plenty for a compressed camera photo (the app already
// sends imageQuality: 80, maxWidth: 1600), but guards against an
// oversized or unexpected upload.
$maxBytes = 10 * 1024 * 1024;
if ($photo['size'] > $maxBytes) {
    respond(400, ['success' => false, 'message' => 'Photo is too large. Maximum size is 10MB.']);
}

// Verify it's actually an image (not just trusting the client-sent name/
// extension), and read the real MIME type off the file contents.
$imageInfo = @getimagesize($photo['tmp_name']);
if ($imageInfo === false) {
    respond(400, ['success' => false, 'message' => 'Uploaded file is not a valid image.']);
}

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
$mimeType = $imageInfo['mime'];
if (!isset($allowedMimeTypes[$mimeType])) {
    respond(400, ['success' => false, 'message' => 'Unsupported image type. Use JPEG, PNG, or WebP.']);
}
$extension = $allowedMimeTypes[$mimeType];

// ---- Save the file to disk ----
// Stored outside the webroot's PHP execution path pattern used elsewhere
// (uploads/pod/), named with trip_id + timestamp so re-submissions never
// collide or overwrite a previous POD photo.
$uploadDir = __DIR__ . '/../uploads/pod/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        respond(500, ['success' => false, 'message' => 'Server could not prepare upload storage.']);
    }
}

$filename = 'pod_' . $tripId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($photo['tmp_name'], $destination)) {
    respond(500, ['success' => false, 'message' => 'Failed to save the uploaded photo.']);
}

// Path recorded relative to the app root, so it can be served/rendered
// consistently regardless of where this script physically lives.
$relativePath = 'uploads/pod/' . $filename;

// ---- Insert the POD record ----
// trip_proofs has no driver_id column (trip_id already implies the driver
// via trips.driver_id) and no submitted_at/pod_id naming — it uses
// uploaded_at (has its own DEFAULT) and proof_id as the PK (already an
// identity column, so it self-generates on insert). It DOES have a notes
// column, so the driver's optional notes are stored here too.
$insert = pg_query_params(
    $conn,
    "INSERT INTO trip_proofs (trip_id, proof_type, file_url, notes)
     VALUES ($1, 'delivery_photo', $2, $3)
     RETURNING proof_id, uploaded_at",
    [$tripId, $relativePath, $notes !== '' ? $notes : null]
);

if (!$insert) {
    // Clean up the saved file if the DB insert failed, so we don't leave
    // an orphaned photo with no matching record.
    @unlink($destination);
    respond(500, ['success' => false, 'message' => 'Failed to save proof of delivery record.']);
}

$row = pg_fetch_assoc($insert);

@pg_query_params(
    $conn,
    "UPDATE trips SET proof_of_delivery_submitted = TRUE WHERE trip_id = $1",
    [$tripId]
);

respond(200, [
    'success'       => true,
    'message'       => 'Proof of delivery uploaded successfully.',
    'proof_id'      => (int) $row['proof_id'],
    'trip_id'       => $tripId,
    'file_url'      => $relativePath,
    'notes'         => $notes !== '' ? $notes : null,
    'uploaded_at'   => $row['uploaded_at'],
]);