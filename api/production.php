<?php
// Production Module API - Orders and Multi-Stage Manufacturing

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-products':
        listProducts($conn);
        break;
    case 'create-product':
        createProduct($conn);
        break;
    case 'list-orders':
        listProductionOrders($conn);
        break;
    case 'create-order':
        createProductionOrder($conn);
        break;
    case 'get-order':
        getProductionOrder($conn);
        break;
    case 'update-stage':
        updateProductionStage($conn);
        break;
    case 'complete-order':
        completeProductionOrder($conn);
        break;
    default:
        sendError('Production action not found', 404);
}

function listProducts($conn) {
    $query = "SELECT p.*, fm.material_name FROM products p
              LEFT JOIN foundry_materials fm ON p.base_material_id = fm.id
              ORDER BY p.product_name";
    
    $result = $conn->query($query);
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    sendResponse(['products' => $products]);
}

function createProduct($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['product_name', 'product_code']);
    
    $product_name = sanitizeInput($data['product_name']);
    $product_code = sanitizeInput($data['product_code']);
    $description = sanitizeInput($data['description'] ?? '');
    $base_material_id = $data['base_material_id'] ?? null;
    $standard_weight = floatval($data['standard_weight'] ?? 0);
    
    $query = "INSERT INTO products (product_name, product_code, description, base_material_id, standard_weight) 
              VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssid", $product_name, $product_code, $description, $base_material_id, $standard_weight);
    
    if (!$stmt->execute()) {
        sendError('Failed to create product', 500);
    }
    
    $product_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'PRODUCTION', $product_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'product_id' => $product_id], 201);
}

function listProductionOrders($conn) {
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT po.*, p.product_name, u.first_name, u.last_name FROM production_orders po
              JOIN products p ON po.product_id = p.id
              LEFT JOIN users u ON po.created_by = u.id";
    
    if (!empty($status)) {
        $query .= " WHERE po.status = ?";
    }
    
    $query .= " ORDER BY po.created_at DESC";
    
    if (!empty($status)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    sendResponse(['production_orders' => $orders]);
}

function createProductionOrder($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['product_id', 'quantity_ordered', 'due_date']);
    
    $order_number = 'MO-' . date('Ymd') . '-' . uniqid();
    $product_id = $data['product_id'];
    $quantity_ordered = intval($data['quantity_ordered']);
    $due_date = $data['due_date'];
    $start_date = $data['start_date'] ?? date('Y-m-d');
    $priority = sanitizeInput($data['priority'] ?? 'normal');
    $user_id = getCurrentUserId();
    
    $conn->begin_transaction();
    
    try {
        // Create order
        $query = "INSERT INTO production_orders (order_number, product_id, quantity_ordered, start_date, due_date, priority, created_by, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("siisssi", $order_number, $product_id, $quantity_ordered, $start_date, $due_date, $priority, $user_id);
        $stmt->execute();
        
        $order_id = $conn->insert_id;
        
        // Create default stages
        $stages = [
            ['Setup', 1],
            ['Manufacturing', 2],
            ['Inspection', 3],
            ['Packaging', 4]
        ];
        
        $stageQuery = "INSERT INTO production_stages (order_id, stage_name, stage_sequence, status) VALUES (?, ?, ?, 'pending')";
        $stageStmt = $conn->prepare($stageQuery);
        
        foreach ($stages as $stage) {
            $stage_name = $stage[0];
            $stage_sequence = $stage[1];
            $stageStmt->bind_param("isi", $order_id, $stage_name, $stage_sequence);
            $stageStmt->execute();
        }
        
        logAuditTrail($conn, 'CREATE', 'PRODUCTION', $order_id);
        
        $conn->commit();
        
        $stmt->close();
        $stageStmt->close();
        
        sendResponse(['success' => true, 'order_id' => $order_id, 'order_number' => $order_number], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        sendError($e->getMessage(), 500);
    }
}

function getProductionOrder($conn) {
    $order_id = $_GET['id'] ?? null;
    
    if (!$order_id) {
        sendError('Order ID required', 400);
    }
    
    $query = "SELECT po.*, p.product_name FROM production_orders po
              JOIN products p ON po.product_id = p.id
              WHERE po.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Order not found', 404);
    }
    
    $order = $result->fetch_assoc();
    
    // Get stages
    $stageQuery = "SELECT ps.*, u.first_name, u.last_name FROM production_stages ps
                   LEFT JOIN users u ON ps.operator_id = u.id
                   WHERE ps.order_id = ? ORDER BY ps.stage_sequence";
    
    $stageStmt = $conn->prepare($stageQuery);
    $stageStmt->bind_param("i", $order_id);
    $stageStmt->execute();
    $stageResult = $stageStmt->get_result();
    
    $stages = [];
    while ($row = $stageResult->fetch_assoc()) {
        $stages[] = $row;
    }
    
    $order['stages'] = $stages;
    
    $stmt->close();
    $stageStmt->close();
    
    sendResponse(['production_order' => $order]);
}

function updateProductionStage($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['stage_id', 'status']);
    
    $stage_id = $data['stage_id'];
    $status = sanitizeInput($data['status']);
    $operator_id = isset($data['operator_id']) ? $data['operator_id'] : getCurrentUserId();
    $qc_notes = sanitizeInput($data['qc_notes'] ?? '');
    
    $validStatuses = ['pending', 'in_progress', 'completed'];
    if (!in_array($status, $validStatuses)) {
        sendError('Invalid status', 400);
    }
    
    $startedAt = null;
    $completedAt = null;
    
    if ($status === 'in_progress') {
        $startedAt = date('Y-m-d H:i:s');
    } elseif ($status === 'completed') {
        $completedAt = date('Y-m-d H:i:s');
    }
    
    $query = "UPDATE production_stages SET status = ?, operator_id = ?, qc_notes = ?, started_at = ?, completed_at = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sisis", $status, $operator_id, $qc_notes, $startedAt, $completedAt, $stage_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to update stage', 500);
    }
    
    logAuditTrail($conn, 'UPDATE_STAGE', 'PRODUCTION', $stage_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}

function completeProductionOrder($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['order_id']);
    
    $order_id = $data['order_id'];
    $quantity_completed = $data['quantity_completed'] ?? 0;
    
    $query = "UPDATE production_orders SET status = 'completed', quantity_completed = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $quantity_completed, $order_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to complete order', 500);
    }
    
    logAuditTrail($conn, 'COMPLETE', 'PRODUCTION', $order_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}
?>
