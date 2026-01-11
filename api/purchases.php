<?php
// Purchases Module API - PO and Supplier Management

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-suppliers':
        listSuppliers($conn);
        break;
    case 'create-supplier':
        createSupplier($conn);
        break;
    case 'list-pos':
        listPurchaseOrders($conn);
        break;
    case 'create-po':
        createPurchaseOrder($conn);
        break;
    case 'get-po':
        getPurchaseOrder($conn);
        break;
    case 'update-po-status':
        updatePOStatus($conn);
        break;
    case 'receive-po':
        receivePurchaseOrder($conn);
        break;
    default:
        sendError('Purchase action not found', 404);
}

function listSuppliers($conn) {
    $query = "SELECT * FROM suppliers WHERE is_active = TRUE ORDER BY supplier_name";
    $result = $conn->query($query);
    $suppliers = [];
    
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    
    sendResponse(['suppliers' => $suppliers]);
}

function createSupplier($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['supplier_name', 'contact_person', 'email']);
    
    if (!hasRole(['admin', 'manager'])) {
        sendError('Only managers can create suppliers', 403);
    }
    
    $supplier_name = sanitizeInput($data['supplier_name']);
    $contact_person = sanitizeInput($data['contact_person']);
    $email = sanitizeInput($data['email']);
    $phone = sanitizeInput($data['phone'] ?? '');
    $address = sanitizeInput($data['address'] ?? '');
    $city = sanitizeInput($data['city'] ?? '');
    $country = sanitizeInput($data['country'] ?? '');
    $payment_terms = sanitizeInput($data['payment_terms'] ?? 'Net 30');
    
    $query = "INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, city, country, payment_terms) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssssss", $supplier_name, $contact_person, $email, $phone, $address, $city, $country, $payment_terms);
    
    if (!$stmt->execute()) {
        sendError('Failed to create supplier', 500);
    }
    
    $supplier_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'PURCHASES', $supplier_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'supplier_id' => $supplier_id], 201);
}

function listPurchaseOrders($conn) {
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT po.*, s.supplier_name, u.first_name, u.last_name,
              COUNT(DISTINCT pli.id) as line_items FROM purchase_orders po
              JOIN suppliers s ON po.supplier_id = s.id
              LEFT JOIN users u ON po.created_by = u.id
              LEFT JOIN po_line_items pli ON po.id = pli.po_id";
    
    if (!empty($status)) {
        $query .= " WHERE po.status = ?";
    }
    
    $query .= " GROUP BY po.id ORDER BY po.created_at DESC";
    
    if (!empty($status)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $pos = [];
    while ($row = $result->fetch_assoc()) {
        $pos[] = $row;
    }
    
    sendResponse(['purchase_orders' => $pos]);
}

function createPurchaseOrder($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['supplier_id', 'order_date', 'items']);
    
    $supplier_id = $data['supplier_id'];
    $order_date = $data['order_date'];
    $required_date = $data['required_date'] ?? null;
    $notes = sanitizeInput($data['notes'] ?? '');
    $items = $data['items'];
    $user_id = getCurrentUserId();
    
    // Generate PO number
    $po_number = 'PO-' . date('Ymd') . '-' . uniqid();
    
    $conn->begin_transaction();
    
    try {
        // Insert PO header
        $query = "INSERT INTO purchase_orders (po_number, supplier_id, order_date, required_date, status, notes, created_by) 
                 VALUES (?, ?, ?, ?, 'draft', ?, ?)";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("siss", $po_number, $supplier_id, $order_date, $required_date, $notes, $user_id);
        $stmt->execute();
        
        $po_id = $conn->insert_id;
        
        // Insert line items and calculate total
        $total = 0;
        $lineQuery = "INSERT INTO po_line_items (po_id, item_name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)";
        $lineStmt = $conn->prepare($lineQuery);
        
        foreach ($items as $item) {
            $item_name = sanitizeInput($item['item_name']);
            $quantity = intval($item['quantity']);
            $unit_price = floatval($item['unit_price']);
            $line_total = $quantity * $unit_price;
            $total += $line_total;
            
            $lineStmt->bind_param("isid", $po_id, $item_name, $quantity, $unit_price, $line_total);
            $lineStmt->execute();
        }
        
        // Update PO total
        $updateQuery = "UPDATE purchase_orders SET total_amount = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("di", $total, $po_id);
        $updateStmt->execute();
        
        logAuditTrail($conn, 'CREATE', 'PURCHASES', $po_id);
        
        $conn->commit();
        
        $stmt->close();
        $lineStmt->close();
        $updateStmt->close();
        
        sendResponse(['success' => true, 'po_id' => $po_id, 'po_number' => $po_number], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        sendError($e->getMessage(), 500);
    }
}

function getPurchaseOrder($conn) {
    $po_id = $_GET['id'] ?? null;
    
    if (!$po_id) {
        sendError('PO ID required', 400);
    }
    
    $query = "SELECT po.*, s.supplier_name FROM purchase_orders po
              JOIN suppliers s ON po.supplier_id = s.id
              WHERE po.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('PO not found', 404);
    }
    
    $po = $result->fetch_assoc();
    
    // Get line items
    $lineQuery = "SELECT * FROM po_line_items WHERE po_id = ?";
    $lineStmt = $conn->prepare($lineQuery);
    $lineStmt->bind_param("i", $po_id);
    $lineStmt->execute();
    $lineResult = $lineStmt->get_result();
    
    $items = [];
    while ($row = $lineResult->fetch_assoc()) {
        $items[] = $row;
    }
    
    $po['items'] = $items;
    
    $stmt->close();
    $lineStmt->close();
    
    sendResponse(['purchase_order' => $po]);
}

function updatePOStatus($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['po_id', 'status']);
    
    $po_id = $data['po_id'];
    $status = sanitizeInput($data['status']);
    
    $validStatuses = ['draft', 'pending', 'confirmed', 'received', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        sendError('Invalid status', 400);
    }
    
    $query = "UPDATE purchase_orders SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $status, $po_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to update PO', 500);
    }
    
    logAuditTrail($conn, 'UPDATE', 'PURCHASES', $po_id, '', $status);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}

function receivePurchaseOrder($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['po_id', 'received_items']);
    
    $po_id = $data['po_id'];
    $received_items = $data['received_items'];
    $user_id = getCurrentUserId();
    
    $conn->begin_transaction();
    
    try {
        // Update PO status
        $updatePOQuery = "UPDATE purchase_orders SET status = 'received', received_by = ?, received_at = NOW() WHERE id = ?";
        $updatePOStmt = $conn->prepare($updatePOQuery);
        $updatePOStmt->bind_param("ii", $user_id, $po_id);
        $updatePOStmt->execute();
        
        // Update line items and add to inventory
        foreach ($received_items as $item) {
            $line_id = $item['line_id'];
            $received_qty = intval($item['received_quantity']);
            
            $updateLineQuery = "UPDATE po_line_items SET received_quantity = ? WHERE id = ?";
            $updateLineStmt = $conn->prepare($updateLineQuery);
            $updateLineStmt->bind_param("ii", $received_qty, $line_id);
            $updateLineStmt->execute();
        }
        
        logAuditTrail($conn, 'RECEIVE', 'PURCHASES', $po_id);
        
        $conn->commit();
        $updatePOStmt->close();
        $updateLineStmt->close();
        
        sendResponse(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        sendError($e->getMessage(), 500);
    }
}
?>
