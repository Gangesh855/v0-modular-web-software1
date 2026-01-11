<?php
// Session Configuration and Security Settings

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session security settings
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 86400); // 24 hours

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Function to get current user ID
function getCurrentUserId() {
    return isLoggedIn() ? $_SESSION['user_id'] : null;
}

// Function to get current user role
function getCurrentUserRole() {
    return isLoggedIn() ? $_SESSION['user_role'] : null;
}

// Function to check user permission
function hasRole($requiredRoles) {
    if (!isLoggedIn()) return false;
    
    $userRole = getCurrentUserRole();
    $roles = is_array($requiredRoles) ? $requiredRoles : [$requiredRoles];
    
    return in_array($userRole, $roles);
}

// Function to log out user
function logout() {
    $_SESSION = [];
    session_destroy();
}

// Function to log user action (audit trail)
function logAuditTrail($connection, $action, $module, $record_id = null, $oldValue = null, $newValue = null) {
    if (!isLoggedIn()) return;
    
    $user_id = getCurrentUserId();
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    $query = "INSERT INTO audit_logs (user_id, action, module, record_id, old_value, new_value, ip_address) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("issiiss", $user_id, $action, $module, $record_id, $oldValue, $newValue, $ip_address);
    $stmt->execute();
    $stmt->close();
}

// CORS Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
?>
