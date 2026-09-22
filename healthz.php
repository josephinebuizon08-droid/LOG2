<?php
/**
 * Simple health check for the hosting platform
 * Just confirms the web server is running without database dependency
 */

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple health check - just confirm PHP is working
http_response_code(200);
echo json_encode(['status' => 'ok', 'timestamp' => date('c'), 'service' => 'FTMS']);
