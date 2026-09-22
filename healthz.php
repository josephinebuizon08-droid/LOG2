<?php
/**
 * Simple health check for the hosting platform
 * Just confirms the web server is running without database dependency
 */

header('Content-Type: application/json');

// Simple health check - just confirm PHP is working
http_response_code(200);
echo json_encode(['status' => 'ok', 'timestamp' => date('c')]);
