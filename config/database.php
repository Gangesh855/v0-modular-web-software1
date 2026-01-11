<?php
// Database Configuration for Hostinger MySQL
// Update these values with your Hostinger database credentials

// Get credentials from environment or hardcode for testing
$db_host = isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'localhost';
$db_user = isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : 'your_hostinger_user';
$db_pass = isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : 'your_hostinger_password';
$db_name = isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : 'enterprise_management';

// Create MySQLi connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// Set charset to utf8
$conn->set_charset("utf8mb4");

// Enable error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Return connection
?>
