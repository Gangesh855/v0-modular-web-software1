<?php
// Helper Functions for API Responses and Utilities

// Send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// Send error response
function sendError($message, $statusCode = 400) {
    sendResponse(['error' => $message], $statusCode);
}

// Hash password using bcrypt
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Sanitize input
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Validate email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Get current date
function getCurrentDate() {
    return date('Y-m-d H:i:s');
}

// Format date for display
function formatDate($date, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($date));
}

// Generate unique ID
function generateUniqueId($prefix = '') {
    return $prefix . '-' . uniqid() . '-' . bin2hex(random_bytes(4));
}

// Check if request method is valid
function validateRequestMethod($allowedMethods) {
    $method = $_SERVER['REQUEST_METHOD'];
    $allowed = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    
    if (!in_array($method, $allowed)) {
        sendError('Method not allowed', 405);
    }
}

// Get JSON request body
function getJsonBody() {
    $json = file_get_contents('php://input');
    return json_decode($json, true);
}

// Validate required fields in request
function validateRequiredFields($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendError("Missing required field: $field", 400);
        }
    }
}

// Generate JWT Token
function generateJWT($user_id, $username, $role) {
    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'user_id' => $user_id,
        'username' => $username,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + (86400 * 7) // 7 days
    ]);
    
    $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $base64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    
    $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, 'your_secret_key_change_this', true);
    $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    
    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
}

// Verify JWT Token
function verifyJWT($token) {
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    $base64Header = $parts[0];
    $base64Payload = $parts[1];
    $signature = $parts[2];
    
    // Verify signature
    $base64HeaderPayload = $base64Header . '.' . $base64Payload;
    $expectedSignature = hash_hmac('sha256', $base64HeaderPayload, 'your_secret_key_change_this', true);
    $expectedBase64Signature = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');
    
    if ($signature !== $expectedBase64Signature) {
        return false;
    }
    
    // Decode payload
    $payload = json_decode(base64_decode(strtr($base64Payload, '-_', '+/')), true);
    
    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
}
?>
