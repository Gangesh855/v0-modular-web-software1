<?php
// HR Module API - Employee and Department Management

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-employees':
        listEmployees($conn);
        break;
    case 'create-employee':
        createEmployee($conn);
        break;
    case 'get-employee':
        getEmployee($conn);
        break;
    case 'update-employee':
        updateEmployee($conn);
        break;
    default:
        sendError('HR action not found', 404);
}

function listEmployees($conn) {
    $department = $_GET['department'] ?? '';
    
    $query = "SELECT he.*, u.username, u.email, u.role FROM hr_employees he
              LEFT JOIN users u ON he.user_account_id = u.id
              WHERE he.is_active = TRUE";
    
    if (!empty($department)) {
        $query .= " AND he.department = ?";
    }
    
    $query .= " ORDER BY he.last_name, he.first_name";
    
    if (!empty($department)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $department);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    sendResponse(['employees' => $employees]);
}

function createEmployee($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['employee_id', 'first_name', 'last_name', 'department', 'position']);
    
    $employee_id = sanitizeInput($data['employee_id']);
    $first_name = sanitizeInput($data['first_name']);
    $last_name = sanitizeInput($data['last_name']);
    $email = sanitizeInput($data['email'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $department = sanitizeInput($data['department']);
    $position = sanitizeInput($data['position']);
    $hire_date = $data['hire_date'] ?? date('Y-m-d');
    $salary = floatval($data['salary'] ?? 0);
    
    $query = "INSERT INTO hr_employees (employee_id, first_name, last_name, email, phone, department, position, hire_date, salary, is_active) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssssssid", $employee_id, $first_name, $last_name, $email, $phone, $department, $position, $hire_date, $salary);
    
    if (!$stmt->execute()) {
        sendError('Failed to create employee', 500);
    }
    
    $emp_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'HR', $emp_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'employee_id' => $emp_id], 201);
}

function getEmployee($conn) {
    $emp_id = $_GET['id'] ?? null;
    
    if (!$emp_id) {
        sendError('Employee ID required', 400);
    }
    
    $query = "SELECT he.*, u.username, u.email, u.role FROM hr_employees he
              LEFT JOIN users u ON he.user_account_id = u.id
              WHERE he.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Employee not found', 404);
    }
    
    $employee = $result->fetch_assoc();
    $stmt->close();
    
    sendResponse(['employee' => $employee]);
}

function updateEmployee($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['employee_id']);
    
    $emp_id = $data['employee_id'];
    $first_name = sanitizeInput($data['first_name'] ?? '');
    $last_name = sanitizeInput($data['last_name'] ?? '');
    $position = sanitizeInput($data['position'] ?? '');
    $salary = isset($data['salary']) ? floatval($data['salary']) : null;
    
    $query = "UPDATE hr_employees SET ";
    $params = [];
    $types = "";
    
    if (!empty($first_name)) {
        $query .= "first_name = ?, ";
        $params[] = $first_name;
        $types .= "s";
    }
    if (!empty($last_name)) {
        $query .= "last_name = ?, ";
        $params[] = $last_name;
        $types .= "s";
    }
    if (!empty($position)) {
        $query .= "position = ?, ";
        $params[] = $position;
        $types .= "s";
    }
    if ($salary !== null) {
        $query .= "salary = ?, ";
        $params[] = $salary;
        $types .= "d";
    }
    
    if (empty($params)) {
        sendError('No fields to update', 400);
    }
    
    $query = rtrim($query, ", ");
    $query .= " WHERE id = ?";
    $params[] = $emp_id;
    $types .= "i";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        sendError('Failed to update employee', 500);
    }
    
    logAuditTrail($conn, 'UPDATE', 'HR', $emp_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}
?>
