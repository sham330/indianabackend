<?php
declare(strict_types=1);

use src\Core\Router; // ✅ MUST be here

/**
 * Front Controller
 * All API requests enter here
 */

// Autoload (Composer)
require __DIR__ . '/../vendor/autoload.php';

// Show errors only in development
if (($_ENV['APP_ENV'] ?? 'local') === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Default response type
header('Content-Type: application/json; charset=UTF-8');

// Handle CORS (basic)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Boot router
$router = new Router();

// Load API routes
require __DIR__ . '/../src/Routes/api.php';

// Dispatch request
$router->dispatch();
