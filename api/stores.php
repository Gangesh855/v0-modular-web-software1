<?php
// Stores Module API - Central Inventory Hub

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Role-based access control
if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

switch ($action) {
    case 'list-stores':
        getStores($conn);
        break;
    case 'create-store':
        createStore($conn);
        break;
    case 'get-store':
        getStore($conn);
        break;
    case 'list-inventory':
        listInventory($conn);
        break;
    case 'add-item':
        addInventoryItem($conn);
        break;
    case 'transaction':
        processTransaction($conn);
        break;
    case 'low-stock':
        getLowStockItems($conn);
        break;
    default:
        sendError('Store action not found', 404);
}

function getStores($conn) {
    $query = "SELECT s.*, u.first_name, u.last_name, COUNT(DISTINCT i.id) as item_count,
              SUM(i.quantity) as total_items FROM stores s
              LEFT JOIN users u ON s.manager_id = u.id
              LEFT JOIN inventory_items i ON s.id = i.store_id
              GROUP BY s.id ORDER BY s.name";
    
    $result = $conn->query($query);
    $stores = [];
    
    while ($row = $result->fetch_assoc()) {
        $stores[] = $row;
    }
    
    sendResponse(['stores' => $stores]);
}

function createStore($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['name', 'location']);
    
    if (!hasRole(['admin', 'manager'])) {
        sendError('Only managers can create stores', 403);
    }
    
    $name = sanitizeInput($data['name']);
    $location = sanitizeInput($data['location']);
    $manager_id = getCurrentUserId();
    $capacity = $data['capacity'] ?? 1000;
    
    $query = "INSERT INTO stores (name, location, manager_id, capacity) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssii", $name, $location, $manager_id, $capacity);
    
    if (!$stmt->execute()) {
        sendError('Failed to create store', 500);
    }
    
    $store_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'STORES', $store_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'store_id' => $store_id], 201);
}

function getStore($conn) {
    $store_id = $_GET['id'] ?? null;
    
    if (!$store_id) {
        sendError('Store ID required', 400);
    }
    
    $query = "SELECT * FROM stores WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Store not found', 404);
    }
    
    $store = $result->fetch_assoc();
    $stmt->close();
    
    sendResponse(['store' => $store]);
}

function listInventory($conn) {
    $store_id = $_GET['store_id'] ?? null;
    
    if (!$store_id) {
        sendError('Store ID required', 400);
    }
    
    $query = "SELECT i.*, l.location_name, l.aisle, l.rack, l.shelf FROM inventory_items i
              LEFT JOIN store_locations l ON i.location_id = l.id
              WHERE i.store_id = ? ORDER BY i.sku";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    $stmt->close();
    
    sendResponse(['items' => $items]);
}

function addInventoryItem($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['store_id', 'sku', 'item_name', 'quantity']);
    
    $store_id = $data['store_id'];
    $sku = sanitizeInput($data['sku']);
    $item_name = sanitizeInput($data['item_name']);
    $quantity = intval($data['quantity']);
    $category = sanitizeInput($data['category'] ?? '');
    $unit_price = floatval($data['unit_price'] ?? 0);
    $reorder_level = intval($data['reorder_level'] ?? 50);
    $location_id = $data['location_id'] ?? null;
    
    $query = "INSERT INTO inventory_items (store_id, sku, item_name, category, quantity, unit_price, reorder_level, location_id) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isssidii", $store_id, $sku, $item_name, $category, $quantity, $unit_price, $reorder_level, $location_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to add item', 500);
    }
    
    $item_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE_ITEM', 'STORES', $item_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'item_id' => $item_id], 201);
}

function processTransaction($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['item_id', 'transaction_type', 'quantity']);
    
    $item_id = $data['item_id'];
    $transaction_type = sanitizeInput($data['transaction_type']);
    $quantity = intval($data['quantity']);
    $reference_type = sanitizeInput($data['reference_type'] ?? 'MANUAL');
    $reference_id = $data['reference_id'] ?? null;
    $notes = sanitizeInput($data['notes'] ?? '');
    $user_id = getCurrentUserId();
    
    // Validate transaction type
    $validTypes = ['IN', 'OUT', 'ADJUST', 'RETURN'];
    if (!in_array($transaction_type, $validTypes)) {
        sendError('Invalid transaction type', 400);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get current item quantity
        $itemQuery = "SELECT quantity FROM inventory_items WHERE id = ?";
        $itemStmt = $conn->prepare($itemQuery);
        $itemStmt->bind_param("i", $item_id);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        
        if ($itemResult->num_rows === 0) {
            throw new Exception('Item not found');
        }
        
        $item = $itemResult->fetch_assoc();
        $currentQty = $item['quantity'];
        $newQty = $currentQty;
        
        // Calculate new quantity based on type
        switch ($transaction_type) {
            case 'IN':
            case 'RETURN':
                $newQty = $currentQty + $quantity;
                break;
            case 'OUT':
            case 'ADJUST':
                $newQty = $currentQty - $quantity;
                break;
        }
        
        if ($newQty < 0) {
            throw new Exception('Insufficient stock for this transaction');
        }
        
        // Update inventory
        $updateQuery = "UPDATE inventory_items SET quantity = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ii", $newQty, $item_id);
        $updateStmt->execute();
        
        // Log transaction
        $transQuery = "INSERT INTO inventory_transactions (item_id, transaction_type, quantity, reference_type, reference_id, notes, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $transStmt = $conn->prepare($transQuery);
        $transStmt->bind_param("isisiis", $item_id, $transaction_type, $quantity, $reference_type, $reference_id, $notes, $user_id);
        $transStmt->execute();
        
        $transaction_id = $conn->insert_id;
        
        logAuditTrail($conn, 'TRANSACTION', 'STORES', $item_id, $currentQty, $newQty);
        
        $conn->commit();
        
        $itemStmt->close();
        $updateStmt->close();
        $transStmt->close();
        
        sendResponse([
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_quantity' => $newQty
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        sendError($e->getMessage(), 400);
    }
}

function getLowStockItems($conn) {
    $query = "SELECT i.*, s.name as store_name FROM inventory_items i
              JOIN stores s ON i.store_id = s.id
              WHERE i.quantity <= i.reorder_level
              ORDER BY i.quantity ASC";
    
    $result = $conn->query($query);
    $items = [];
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    sendResponse(['low_stock_items' => $items]);
}
?>
