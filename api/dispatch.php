<?php
// Dispatch Module API - Shipment and Delivery Tracking

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

validateRequestMethod(['GET', 'POST', 'PUT', 'DELETE']);

if (!hasRole(['admin', 'manager', 'operator'])) {
    sendError('Access denied', 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list-shipments':
        listShipments($conn);
        break;
    case 'create-shipment':
        createShipment($conn);
        break;
    case 'get-shipment':
        getShipment($conn);
        break;
    case 'update-shipment-status':
        updateShipmentStatus($conn);
        break;
    case 'add-event':
        addShipmentEvent($conn);
        break;
    case 'delivery-metrics':
        getDeliveryMetrics($conn);
        break;
    default:
        sendError('Dispatch action not found', 404);
}

function listShipments($conn) {
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT s.*, po.order_number, p.product_name FROM shipments s
              LEFT JOIN production_orders po ON s.order_id = po.id
              LEFT JOIN products p ON po.product_id = p.id";
    
    if (!empty($status)) {
        $query .= " WHERE s.status = ?";
    }
    
    $query .= " ORDER BY s.created_at DESC";
    
    if (!empty($status)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $shipments = [];
    while ($row = $result->fetch_assoc()) {
        $shipments[] = $row;
    }
    
    sendResponse(['shipments' => $shipments]);
}

function createShipment($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['order_id', 'carrier', 'destination_address']);
    
    $shipment_number = 'SHIP-' . date('Ymd') . '-' . uniqid();
    $order_id = $data['order_id'];
    $carrier = sanitizeInput($data['carrier']);
    $tracking_number = sanitizeInput($data['tracking_number'] ?? '');
    $destination_address = sanitizeInput($data['destination_address']);
    $ship_date = $data['ship_date'] ?? date('Y-m-d');
    $estimated_delivery = $data['estimated_delivery'] ?? null;
    $user_id = getCurrentUserId();
    
    $query = "INSERT INTO shipments (shipment_number, order_id, carrier, tracking_number, destination_address, ship_date, estimated_delivery, status, created_by) 
              VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sisssss", $shipment_number, $order_id, $carrier, $tracking_number, $destination_address, $ship_date, $estimated_delivery, $user_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to create shipment', 500);
    }
    
    $shipment_id = $conn->insert_id;
    logAuditTrail($conn, 'CREATE', 'DISPATCH', $shipment_id);
    
    $stmt->close();
    
    sendResponse(['success' => true, 'shipment_id' => $shipment_id, 'shipment_number' => $shipment_number], 201);
}

function getShipment($conn) {
    $shipment_id = $_GET['id'] ?? null;
    
    if (!$shipment_id) {
        sendError('Shipment ID required', 400);
    }
    
    $query = "SELECT s.*, po.order_number, p.product_name FROM shipments s
              LEFT JOIN production_orders po ON s.order_id = po.id
              LEFT JOIN products p ON po.product_id = p.id
              WHERE s.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Shipment not found', 404);
    }
    
    $shipment = $result->fetch_assoc();
    
    // Get events
    $eventQuery = "SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_time DESC";
    $eventStmt = $conn->prepare($eventQuery);
    $eventStmt->bind_param("i", $shipment_id);
    $eventStmt->execute();
    $eventResult = $eventStmt->get_result();
    
    $events = [];
    while ($row = $eventResult->fetch_assoc()) {
        $events[] = $row;
    }
    
    $shipment['events'] = $events;
    
    $stmt->close();
    $eventStmt->close();
    
    sendResponse(['shipment' => $shipment]);
}

function updateShipmentStatus($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['shipment_id', 'status']);
    
    $shipment_id = $data['shipment_id'];
    $status = sanitizeInput($data['status']);
    
    $validStatuses = ['pending', 'in_transit', 'delivered', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        sendError('Invalid status', 400);
    }
    
    $actualDeliveryDate = null;
    if ($status === 'delivered') {
        $actualDeliveryDate = date('Y-m-d');
    }
    
    $query = "UPDATE shipments SET status = ?, actual_delivery_date = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $status, $actualDeliveryDate, $shipment_id);
    
    if (!$stmt->execute()) {
        sendError('Failed to update shipment', 500);
    }
    
    logAuditTrail($conn, 'UPDATE', 'DISPATCH', $shipment_id);
    
    $stmt->close();
    
    sendResponse(['success' => true]);
}

function addShipmentEvent($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed', 405);
    }
    
    $data = getJsonBody();
    validateRequiredFields($data, ['shipment_id', 'event_type', 'event_description']);
    
    $shipment_id = $data['shipment_id'];
    $event_type = sanitizeInput($data['event_type']);
    $event_description = sanitizeInput($data['event_description']);
    $location = sanitizeInput($data['location'] ?? '');
    
    $query = "INSERT INTO shipment_events (shipment_id, event_type, event_description, location) VALUES (?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isss", $shipment_id, $event_type, $event_description, $location);
    
    if (!$stmt->execute()) {
        sendError('Failed to add event', 500);
    }
    
    $event_id = $conn->insert_id;
    
    $stmt->close();
    
    sendResponse(['success' => true, 'event_id' => $event_id], 201);
}

function getDeliveryMetrics($conn) {
    // On-time delivery
    $deliveredQuery = "SELECT COUNT(*) as total_delivered FROM shipments WHERE actual_delivery_date IS NOT NULL";
    $onTimeQuery = "SELECT COUNT(*) as on_time FROM shipments WHERE actual_delivery_date IS NOT NULL AND actual_delivery_date <= estimated_delivery";
    
    $deliveredResult = $conn->query($deliveredQuery);
    $onTimeResult = $conn->query($onTimeQuery);
    
    $delivered = $deliveredResult->fetch_assoc()['total_delivered'];
    $onTime = $onTimeResult->fetch_assoc()['on_time'];
    $onTimePercentage = $delivered > 0 ? ($onTime / $delivered) * 100 : 0;
    
    // Average delivery days
    $avgQuery = "SELECT AVG(DATEDIFF(actual_delivery_date, ship_date)) as avg_days FROM shipments WHERE actual_delivery_date IS NOT NULL";
    $avgResult = $conn->query($avgQuery);
    $avgDays = $avgResult->fetch_assoc()['avg_days'];
    
    // Shipments by status
    $statusQuery = "SELECT status, COUNT(*) as count FROM shipments GROUP BY status";
    $statusResult = $conn->query($statusQuery);
    
    $byStatus = [];
    while ($row = $statusResult->fetch_assoc()) {
        $byStatus[] = $row;
    }
    
    sendResponse([
        'on_time_percentage' => round($onTimePercentage, 2),
        'average_delivery_days' => round($avgDays, 2),
        'by_status' => $byStatus
    ]);
}
?>
