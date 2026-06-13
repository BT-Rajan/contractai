<?php
declare(strict_types=1);

// ── Global exception/error handler — must be first ───────────
// Catches any uncaught PDOException, TypeError, etc. and returns
// a clean JSON 500 instead of a raw PHP error page.
set_exception_handler(function (Throwable $e): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $msg = (defined('APP_DEBUG') && APP_DEBUG)
        ? $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
        : 'Internal server error';
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!($no & error_reporting())) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

// Composer autoloader — must come before any vendor class usage (mPDF etc.)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Buffer output so PHP warnings never corrupt JSON responses
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

// ── Content-Security-Policy ───────────────────────────────────
// Restricts what the browser will load/execute, providing a second
// line of defence if a stored-XSS payload survives sanitize_html().
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://checkout.razorpay.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https://*.razorpay.com; connect-src 'self' https://generativelanguage.googleapis.com https://api.razorpay.com https://lumberjack.razorpay.com; frame-src 'self' https://api.razorpay.com https://checkout.razorpay.com; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── CSRF protection for state-changing requests ───────────────
// Approach: custom header check (X-Requested-With).
// Browsers cannot set custom headers on cross-origin requests without
// a CORS preflight — and our CORS config only allows localhost in dev.
// This stops CSRF from attacker-controlled pages in all environments.
// The SPA's api.js already sends X-Requested-With on every request.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    // Allow if the custom header is present (SPA requests)
    // Also allow if Authorization Bearer header is present (API clients)
    $hasBearer = str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer ');
    if (!$hasBearer && strtolower($xrw) !== 'xmlhttprequest') {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'CSRF check failed']);
        exit;
    }
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Auto-run pending migrations (no-op when schema is current)
require_once __DIR__ . '/migrate.php';
run_migrations();

