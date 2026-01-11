<?php
// Foundry Module API - Materials and Batch Processing

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-materials':
        listMaterials($conn);
        break;
    case 'create-material':
        createMaterial($conn);
        break;
    case 'list-processes':
        listProcesses($conn);
        break;
    case 'create-process':
        createProcess($conn);
        break;
    case 'list-batches':
        listBatches($conn);
        break;
    case 'create-batch':
        createBatch($conn);
        break;
    case 'start-batch':
        startBatch($conn);
        break;
    case 'complete-batch':
        completeBatch($conn);
        break;
    default:
        sendError('Foundry action not found', 404);
}

function listMaterials($conn) {
    $query = "SELECT fm.*, s.supplier_name FROM foundry_materials fm
              LEFT JOIN suppliers s ON fm.supplier_id = s.id
              WHERE fm.is_active = TRUE ORDER BY fm.material_name";
    
    $result = $conn->query($query);
    $materials = [];
    
    while ($row = $result->fetch_assoc()) {
        $materials[] = $row;
    }
    
    sendResponse(['materials' => $materials]);
}

function createMaterial($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['material_name', 'material_type']);
    
    $material_name = sanitizeInput($data['material_name']);
    $material_type = sanitizeInput($data['material_type']);
    $specification = sanitizeInput($data['specification'] ?? '');
    $unit_cost = floatval($data['unit_cost'] ?? 0);
    $supplier_id = $data['supplier_id'] ?? null;
    
    $query = "INSERT INTO foundry_materials (material_name, material_type, specification, unit_cost, supplier_id) 
              VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssdi", $material_name, $material_type, $specification, $unit_cost, $supplier_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to create material', 500);
    }
    
    $material_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'FOUNDRY', $material_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'material_id' => $material_id], 201);
}

function listProcesses($conn) {
    $query = "SELECT * FROM foundry_processes ORDER BY process_name";
    $result = $conn->query($query);
    $processes = [];
    
    while ($row = $result->fetch_assoc()) {
        $processes[] = $row;
    }
    
    sendResponse(['processes' => $processes]);
}

function createProcess($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['process_name', 'process_type']);
    
    $process_name = sanitizeInput($data['process_name']);
    $process_type = sanitizeInput($data['process_type']);
    $temperature_range = sanitizeInput($data['temperature_range'] ?? '');
    $duration_minutes = intval($data['duration_minutes'] ?? 0);
    $description = sanitizeInput($data['description'] ?? '');
    
    $query = "INSERT INTO foundry_processes (process_name, process_type, temperature_range, duration_minutes, description) 
              VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssis", $process_name, $process_type, $temperature_range, $duration_minutes, $description);
    
    if (!$stmt->execute()) {
        sendError('Failed to create process', 500);
    }
    
    $process_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'FOUNDRY', $process_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'process_id' => $process_id], 201);
}

function listBatches($conn) {
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT b.*, fm.material_name, fp.process_name FROM foundry_batches b
              JOIN foundry_materials fm ON b.material_id = fm.id
              JOIN foundry_processes fp ON b.process_id = fp.id";
    
    if (!empty($status)) {
        $query .= " WHERE b.status = ?";
    }
    
    $query .= " ORDER BY b.created_at DESC";
    
    if (!empty($status)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $batches = [];
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row;
    }
    
    sendResponse(['batches' => $batches]);
}

function createBatch($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['material_id', 'process_id', 'quantity_input']);
    
    $batch_number = 'BATCH-' . date('Ymd') . '-' . uniqid();
    $material_id = $data['material_id'];
    $process_id = $data['process_id'];
    $quantity_input = intval($data['quantity_input']);
    $qc_notes = sanitizeInput($data['qc_notes'] ?? '');
    $user_id = getCurrentUserId();
    
    $query = "INSERT INTO foundry_batches (batch_number, material_id, process_id, quantity_input, qc_notes, created_by, status) 
              VALUES (?, ?, ?, ?, ?, ?, 'planned')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("siiisi", $batch_number, $material_id, $process_id, $quantity_input, $qc_notes, $user_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to create batch', 500);
    }
    
    $batch_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'FOUNDRY', $batch_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'batch_id' => $batch_id, 'batch_number' => $batch_number], 201);
}

function startBatch($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['batch_id']);
    
    $batch_id = $data['batch_id'];
    
    $query = "UPDATE foundry_batches SET status = 'in_progress', started_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $batch_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to start batch', 500);
    }
    
    logAuditTrail($conn, 'START', 'FOUNDRY', $batch_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}

function completeBatch($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['batch_id', 'quantity_output']);
    
    $batch_id = $data['batch_id'];
    $quantity_output = intval($data['quantity_output']);
    $qc_notes = sanitizeInput($data['qc_notes'] ?? '');
    
    // Get input quantity for yield calculation
    $getQuery = "SELECT quantity_input FROM foundry_batches WHERE id = ?";
    $getStmt = $conn->prepare($getQuery);
    $getStmt->bind_param("i", $batch_id);
    $getStmt->execute();
    $result = $getStmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Batch not found', 404);
    }
    
    $batch = $result->fetch_assoc();
    $yield = ($quantity_output / $batch['quantity_input']) * 100;
    
    $updateQuery = "UPDATE foundry_batches SET status = 'completed', quantity_output = ?, yield_percentage = ?, qc_notes = ?, completed_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("idsi", $quantity_output, $yield, $qc_notes, $batch_id);
    
    if (!$updateStmt->execute()) {
        sendError('Failed to complete batch', 500);
    }
    
    logAuditTrail($conn, 'COMPLETE', 'FOUNDRY', $batch_id);
    
    $getStmt->close();
    $updateStmt->close();
    
    sendResponse(['success' => true, 'yield_percentage' => $yield]);
}
?>
