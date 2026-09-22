<?php
/**
 * Unauthenticated health check for the hosting platform (Hostforge, or any
 * Docker-based host) to poll. Confirms the app can talk to the database
 * without requiring a logged-in session, unlike auth/ping.php.
 *
 * Deliberately returns no stack traces or connection strings on failure.
 */

require_once __DIR__ . '/config/env_loader.php';

header('Content-Type: application/json');

$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '5432';
$dbname   = getenv('DB_NAME') ?: 'ftms_db';
$user     = getenv('DB_USER') ?: 'postgres';
$password = getenv('DB_PASSWORD') ?: '';

$conn = @pg_connect(
    "host=$host port=$port dbname=$dbname user=$user password=$password connect_timeout=3",
    PGSQL_CONNECT_FORCE_NEW
);

if (!$conn) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'component' => 'database']);
    exit;
}

pg_close($conn);

http_response_code(200);
echo json_encode(['status' => 'ok']);
