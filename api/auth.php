<?php
// Authentication API Routes - Token-based for better Hostinger compatibility
// Handles login, register, logout

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['POST', 'GET']);

$action = $_GET['action'] ?? '';

$token = null;
$bearerHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/', $bearerHeader, $matches)) {
    $token = $matches[1];
}

switch ($action) {
    case 'login':
        handleLogin($conn);
        break;
    case 'register':
        handleRegister($conn);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'current':
        handleGetCurrentUser($conn, $token);
        break;
    case 'verify':
        handleVerifyToken($conn, $token);
        break;
    default:
        sendError('Auth action not found', 404);
}

function handleLogin($conn) {
    validateRequestMethod('POST');
    
    $data = getJsonBody();
    validateRequiredFields($data, ['username', 'password']);
    
    $username = sanitizeInput($data['username']);
    $password = $data['password'];
    
    $query = "SELECT id, username, email, password_hash, role, first_name, last_name FROM users 
              WHERE username = ? AND is_active = TRUE LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Invalid username or password', 401);
    }
    
    $user = $result->fetch_assoc();
    
    if (!verifyPassword($password, $user['password_hash'])) {
        sendError('Invalid username or password', 401);
    }
    
    $token = generateJWT($user['id'], $user['username'], $user['role']);
    
    // Set session as backup
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['token'] = $token;
    
    // Log audit trail
    logAuditTrail($conn, 'LOGIN', 'AUTH');
    
    $stmt->close();
    
    sendResponse([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name']
        ]
    ]);
}

function handleRegister($conn) {
    validateRequestMethod('POST');
    
    $data = getJsonBody();
    validateRequiredFields($data, ['username', 'email', 'password', 'first_name', 'last_name']);
    
    $username = sanitizeInput($data['username']);
    $email = sanitizeInput($data['email']);
    $password = $data['password'];
    $first_name = sanitizeInput($data['first_name']);
    $last_name = sanitizeInput($data['last_name']);
    $role = sanitizeInput($data['role'] ?? 'operator');
    
    if (!validateEmail($email)) {
        sendError('Invalid email format', 400);
    }
    
    // Check if user exists
    $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("ss", $username, $email);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        sendError('Username or email already exists', 409);
    }
    
    // Create user
    $password_hash = hashPassword($password);
    $insertQuery = "INSERT INTO users (username, email, password_hash, first_name, last_name, role, is_active) 
                   VALUES (?, ?, ?, ?, ?, ?, TRUE)";
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param("ssssss", $username, $email, $password_hash, $first_name, $last_name, $role);
    
    if (!$insertStmt->execute()) {
        sendError('Failed to create user', 500);
    }
    
    $user_id = $conn->insert_id;
    
    logAuditTrail($conn, 'REGISTER', 'AUTH', $user_id);
    
    $checkStmt->close();
    $insertStmt->close();
    
    sendResponse([
        'success' => true,
        'message' => 'User registered successfully',
        'user_id' => $user_id
    ], 201);
}

function handleLogout() {
    validateRequestMethod('GET');
    
    if (isLoggedIn()) {
        $user_id = getCurrentUserId();
        // Could log logout here
    }
    
    logout();
    
    sendResponse([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

function handleGetCurrentUser($conn, $token) {
    validateRequestMethod('GET');
    
    if (!isLoggedIn() && !$token) {
        sendError('Not authenticated', 401);
    }
    
    $user_id = getCurrentUserId();
    
    if (!$user_id && $token) {
        $decoded = verifyJWT($token);
        
        if (!$decoded) {
            sendError('Invalid or expired token', 401);
        }
        
        $user_id = $decoded['user_id'];
    }
    
    $query = "SELECT id, username, email, role, first_name, last_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('User not found', 404);
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    sendResponse(['user' => $user]);
}

function handleVerifyToken($conn, $token) {
    validateRequestMethod('GET');
    
    if (!$token) {
        sendError('No token provided', 401);
    }
    
    $decoded = verifyJWT($token);
    
    if (!$decoded) {
        sendError('Invalid or expired token', 401);
    }
    
    $user_id = $decoded['user_id'];
    
    $query = "SELECT id, username, email, role, first_name, last_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('User not found', 404);
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    sendResponse([
        'success' => true,
        'user' => $user
    ]);
}
?>
