<?php
// Die Shop Module API - Equipment and Maintenance Management

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-equipment':
        listEquipment($conn);
        break;
    case 'create-equipment':
        createEquipment($conn);
        break;
    case 'get-equipment':
        getEquipment($conn);
        break;
    case 'update-equipment':
        updateEquipment($conn);
        break;
    case 'maintenance-due':
        getMaintenanceDueEquipment($conn);
        break;
    default:
        sendError('Die Shop action not found', 404);
}

function listEquipment($conn) {
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT * FROM die_shop_equipment WHERE 1=1";
    
    if (!empty($status)) {
        $query .= " AND status = ?";
    }
    
    $query .= " ORDER BY equipment_name";
    
    if (!empty($status)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $equipment = [];
    while ($row = $result->fetch_assoc()) {
        $equipment[] = $row;
    }
    
    sendResponse(['equipment' => $equipment]);
}

function createEquipment($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['equipment_name', 'equipment_type']);
    
    $equipment_name = sanitizeInput($data['equipment_name']);
    $equipment_type = sanitizeInput($data['equipment_type']);
    $model = sanitizeInput($data['model'] ?? '');
    $serial_number = sanitizeInput($data['serial_number'] ?? '');
    $purchase_date = $data['purchase_date'] ?? null;
    $next_maintenance_date = $data['next_maintenance_date'] ?? null;
    
    $query = "INSERT INTO die_shop_equipment (equipment_name, equipment_type, model, serial_number, purchase_date, next_maintenance_date, status) 
              VALUES (?, ?, ?, ?, ?, ?, 'active')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssss", $equipment_name, $equipment_type, $model, $serial_number, $purchase_date, $next_maintenance_date);
    
    if (!$stmt->execute()) {
        sendError('Failed to create equipment', 500);
    }
    
    $eq_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'DIE_SHOP', $eq_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'equipment_id' => $eq_id], 201);
}

function getEquipment($conn) {
    $eq_id = $_GET['id'] ?? null;
    
    if (!$eq_id) {
        sendError('Equipment ID required', 400);
    }
    
    $query = "SELECT * FROM die_shop_equipment WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $eq_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Equipment not found', 404);
    }
    
    $equipment = $result->fetch_assoc();
    $stmt->close();
    
    sendResponse(['equipment' => $equipment]);
}

function updateEquipment($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['equipment_id']);
    
    $eq_id = $data['equipment_id'];
    $last_maintenance = $data['last_maintenance_date'] ?? null;
    $next_maintenance = $data['next_maintenance_date'] ?? null;
    $status = sanitizeInput($data['status'] ?? 'active');
    
    $query = "UPDATE die_shop_equipment SET last_maintenance_date = ?, next_maintenance_date = ?, status = ? WHERE id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssi", $last_maintenance, $next_maintenance, $status, $eq_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to update equipment', 500);
    }
    
    logAuditTrail($conn, 'UPDATE', 'DIE_SHOP', $eq_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}

function getMaintenanceDueEquipment($conn) {
    $today = date('Y-m-d');
    
    $query = "SELECT * FROM die_shop_equipment 
              WHERE status = 'active' 
              AND next_maintenance_date IS NOT NULL 
              AND next_maintenance_date <= ? 
              ORDER BY next_maintenance_date";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $equipment = [];
    while ($row = $result->fetch_assoc()) {
        $equipment[] = $row;
    }
    
    $stmt->close();
    
    sendResponse(['maintenance_due' => $equipment]);
}
?>
