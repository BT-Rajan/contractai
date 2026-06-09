<?php
declare(strict_types=1);

// Buffer ALL output so PHP warnings never corrupt JSON responses
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

// Create required directories
foreach ([LOGS_PATH, STORAGE_PATH, UPLOADS_PATH] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// CORS headers for local dev
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (APP_ENV === 'development') {
    // Only reflect localhost origins — never send * with credentials
    $allowedOriginPattern = '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/';
    $safeOrigin = ($origin && preg_match($allowedOriginPattern, $origin)) ? $origin : 'null';
    header('Access-Control-Allow-Origin: ' . $safeOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
