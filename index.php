<?php
// Main Application Entry Point
// Route requests to appropriate API handlers

require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/functions.php';

// Get the request path
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/api', '', $request_path);
$parts = explode('/', trim($request_path, '/'));

// Route the request
$module = isset($parts[0]) ? $parts[0] : '';
$action = isset($parts[1]) ? $parts[1] : '';
$id = isset($parts[2]) ? $parts[2] : '';

// Public routes (no authentication required)
$publicRoutes = ['auth'];

// Check authentication for protected routes
if (!in_array($module, $publicRoutes) && !isLoggedIn()) {
    sendError('Unauthorized: Please login first', 401);
}

// Route to appropriate module
switch ($module) {
    case 'auth':
        require_once 'api/auth.php';
        break;
    case 'stores':
        require_once 'api/stores.php';
        break;
    case 'purchases':
        require_once 'api/purchases.php';
        break;
    case 'foundry':
        require_once 'api/foundry.php';
        break;
    case 'production':
        require_once 'api/production.php';
        break;
    case 'dispatch':
        require_once 'api/dispatch.php';
        break;
    case 'hr':
        require_once 'api/hr.php';
        break;
    case 'die-shop':
        require_once 'api/die-shop.php';
        break;
    default:
        sendError('API route not found', 404);
}
?>
