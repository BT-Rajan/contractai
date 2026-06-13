<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Load .env
$envFile = APP_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        $_ENV[$k] = $v;
        putenv("{$k}={$v}");
    }
}

define('APP_ENV',   $_ENV['APP_ENV']   ?? 'development');
define('APP_DEBUG', ($_ENV['APP_DEBUG'] ?? 'true') === 'true');

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'contractai');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

define('JWT_SECRET',   $_ENV['JWT_SECRET']   ?? 'contractai_jwt_secret_key_2024_dev');
define('JWT_TTL',      (int)($_ENV['JWT_TTL']     ?? 3600));
define('JWT_REFRESH',  (int)($_ENV['JWT_REFRESH'] ?? 604800));

// ENCRYPT_KEY is deliberately separate from JWT_SECRET.
// Rotating JWT_SECRET (e.g. after a leak) must not corrupt encrypted DB fields.
// Generate with: openssl rand -hex 32
define('ENCRYPT_KEY',  $_ENV['ENCRYPT_KEY']  ?? JWT_SECRET);

// Warn if secrets are still insecure defaults
if (JWT_SECRET  === 'contractai_jwt_secret_key_2024_dev' && APP_DEBUG) {
    error_log('[ContractAI] WARNING: JWT_SECRET is using the insecure default. Set a strong secret in .env');
}
if (ENCRYPT_KEY === JWT_SECRET && APP_ENV === 'production') {
    error_log('[ContractAI] WARNING: ENCRYPT_KEY not set. Add a separate ENCRYPT_KEY to .env');
}

// ── Razorpay (recharge / payments) ─────────────────────────────
define('RAZORPAY_KEY_ID',     $_ENV['RAZORPAY_KEY_ID']     ?? '');
define('RAZORPAY_KEY_SECRET', $_ENV['RAZORPAY_KEY_SECRET'] ?? '');
define('RAZORPAY_CURRENCY',   $_ENV['RAZORPAY_CURRENCY']   ?? 'INR');

define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');
define('GEMINI_MODEL',   $_ENV['GEMINI_MODEL']   ?? 'gemini-2.0-flash');

define('STORAGE_PATH', APP_ROOT . '/storage');
define('UPLOADS_PATH', APP_ROOT . '/uploads');
define('LOGS_PATH',    APP_ROOT . '/logs');

// Base URL detection
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appRoot  = str_replace('\\', '/', APP_ROOT);
$docRoot  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$basePath = '';
if ($docRoot && str_starts_with($appRoot, $docRoot)) {
    $basePath = substr($appRoot, strlen($docRoot));
}
$basePath = rtrim($basePath, '/');
define('BASE_PATH', $basePath);
define('BASE_URL',  $scheme . '://' . $host . $basePath);

// Error reporting
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0'); // Never display — use ob_start buffer instead
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
date_default_timezone_set('UTC');
