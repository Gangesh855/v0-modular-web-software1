-- MySQL Database Schema for Enterprise Management System
-- Compatible with Hostinger MySQL databases
-- Run this script in your cPanel phpMyAdmin

CREATE DATABASE IF NOT EXISTS enterprise_management;
USE enterprise_management;

-- Users Table
CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  role ENUM('admin', 'manager', 'operator', 'auditor') DEFAULT 'operator',
  department VARCHAR(50),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Audit Log Table
CREATE TABLE audit_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  module VARCHAR(50) NOT NULL,
  record_id INT,
  old_value LONGTEXT,
  new_value LONGTEXT,
  ip_address VARCHAR(45),
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX (module, record_id, timestamp)
);

-- Stores Table (Hub - Central Inventory)
CREATE TABLE stores (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE,
  location VARCHAR(255),
  manager_id INT,
  capacity INT DEFAULT 1000,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (manager_id) REFERENCES users(id)
);

-- Store Locations
CREATE TABLE store_locations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  store_id INT NOT NULL,
  location_name VARCHAR(100),
  aisle VARCHAR(10),
  rack VARCHAR(10),
  shelf VARCHAR(10),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  UNIQUE KEY (store_id, aisle, rack, shelf)
);

-- Inventory Items
CREATE TABLE inventory_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  store_id INT NOT NULL,
  sku VARCHAR(50) UNIQUE NOT NULL,
  item_name VARCHAR(150) NOT NULL,
  category VARCHAR(50),
  quantity INT DEFAULT 0,
  reorder_level INT DEFAULT 50,
  unit_price DECIMAL(10,2),
  location_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES store_locations(id),
  INDEX (sku, store_id)
);

-- Inventory Transactions
CREATE TABLE inventory_transactions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  item_id INT NOT NULL,
  transaction_type ENUM('IN', 'OUT', 'ADJUST', 'RETURN') NOT NULL,
  quantity INT NOT NULL,
  reference_id INT,
  reference_type VARCHAR(50),
  notes TEXT,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX (reference_type, reference_id, created_at)
);

-- Suppliers Table (for Purchases)
CREATE TABLE suppliers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  supplier_name VARCHAR(100) NOT NULL UNIQUE,
  contact_person VARCHAR(100),
  email VARCHAR(100),
  phone VARCHAR(20),
  address TEXT,
  city VARCHAR(50),
  country VARCHAR(50),
  payment_terms VARCHAR(50),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Purchase Orders
CREATE TABLE purchase_orders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  po_number VARCHAR(50) UNIQUE NOT NULL,
  supplier_id INT NOT NULL,
  order_date DATE NOT NULL,
  required_date DATE,
  status ENUM('draft', 'pending', 'confirmed', 'received', 'cancelled') DEFAULT 'draft',
  total_amount DECIMAL(12,2),
  notes TEXT,
  created_by INT,
  received_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  received_at TIMESTAMP NULL,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (received_by) REFERENCES users(id),
  INDEX (status, supplier_id, order_date)
);

-- PO Line Items
CREATE TABLE po_line_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  po_id INT NOT NULL,
  item_name VARCHAR(150),
  quantity INT NOT NULL,
  unit_price DECIMAL(10,2),
  line_total DECIMAL(12,2),
  received_quantity INT DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
);

-- Foundry Materials
CREATE TABLE foundry_materials (
  id INT PRIMARY KEY AUTO_INCREMENT,
  material_name VARCHAR(100) NOT NULL,
  material_type VARCHAR(50),
  specification TEXT,
  unit_cost DECIMAL(10,2),
  supplier_id INT,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

-- Foundry Processes
CREATE TABLE foundry_processes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  process_name VARCHAR(100) NOT NULL UNIQUE,
  process_type VARCHAR(50),
  temperature_range VARCHAR(50),
  duration_minutes INT,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Foundry Batches
CREATE TABLE foundry_batches (
  id INT PRIMARY KEY AUTO_INCREMENT,
  batch_number VARCHAR(50) UNIQUE NOT NULL,
  material_id INT NOT NULL,
  process_id INT NOT NULL,
  quantity_input INT,
  quantity_output INT,
  yield_percentage DECIMAL(5,2),
  status ENUM('planned', 'in_progress', 'completed', 'failed') DEFAULT 'planned',
  qc_notes TEXT,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (material_id) REFERENCES foundry_materials(id),
  FOREIGN KEY (process_id) REFERENCES foundry_processes(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX (status, created_at)
);

-- Products
CREATE TABLE products (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_name VARCHAR(150) NOT NULL UNIQUE,
  product_code VARCHAR(50) UNIQUE,
  description TEXT,
  base_material_id INT,
  standard_weight DECIMAL(10,2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (base_material_id) REFERENCES foundry_materials(id)
);

-- Production Orders
CREATE TABLE production_orders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  order_number VARCHAR(50) UNIQUE NOT NULL,
  product_id INT NOT NULL,
  quantity_ordered INT NOT NULL,
  quantity_completed INT DEFAULT 0,
  status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  start_date DATE,
  due_date DATE,
  priority VARCHAR(20),
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX (status, due_date)
);

-- Production Stages
CREATE TABLE production_stages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  order_id INT NOT NULL,
  stage_name VARCHAR(100),
  stage_sequence INT,
  status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
  operator_id INT,
  qc_notes TEXT,
  duration_minutes INT,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  FOREIGN KEY (order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (operator_id) REFERENCES users(id),
  INDEX (order_id, stage_sequence)
);

-- Shipments (Dispatch)
CREATE TABLE shipments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shipment_number VARCHAR(50) UNIQUE NOT NULL,
  order_id INT NOT NULL,
  carrier VARCHAR(100),
  tracking_number VARCHAR(100),
  status ENUM('pending', 'in_transit', 'delivered', 'cancelled') DEFAULT 'pending',
  destination_address TEXT,
  ship_date DATE,
  estimated_delivery DATE,
  actual_delivery_date DATE,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES production_orders(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX (status, ship_date)
);

-- Shipment Timeline Events
CREATE TABLE shipment_events (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shipment_id INT NOT NULL,
  event_type VARCHAR(50),
  event_description TEXT,
  location VARCHAR(100),
  event_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
);

-- HR Employees
CREATE TABLE hr_employees (
  id INT PRIMARY KEY AUTO_INCREMENT,
  employee_id VARCHAR(50) UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  phone VARCHAR(20),
  department VARCHAR(50),
  position VARCHAR(100),
  hire_date DATE,
  salary DECIMAL(12,2),
  user_account_id INT UNIQUE,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_account_id) REFERENCES users(id)
);

-- Die Shop Equipment
CREATE TABLE die_shop_equipment (
  id INT PRIMARY KEY AUTO_INCREMENT,
  equipment_name VARCHAR(100) NOT NULL,
  equipment_type VARCHAR(50),
  model VARCHAR(50),
  serial_number VARCHAR(50) UNIQUE,
  purchase_date DATE,
  last_maintenance_date DATE,
  next_maintenance_date DATE,
  status ENUM('active', 'maintenance', 'retired') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create default admin user (password: admin123)
INSERT INTO users (username, email, password_hash, first_name, last_name, role, department, is_active) 
VALUES ('admin', 'admin@enterprise.local', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36YROYLm', 'System', 'Admin', 'admin', 'IT', TRUE);

-- Create sample suppliers
INSERT INTO suppliers (supplier_name, contact_person, email, phone, city, country) VALUES
('Steel Supplies Inc', 'John Smith', 'john@steelsupply.com', '+1-555-0101', 'New York', 'USA'),
('Aluminum Direct', 'Jane Doe', 'jane@aludir.com', '+1-555-0102', 'Chicago', 'USA'),
('Material Warehouse', 'Bob Johnson', 'bob@matware.com', '+1-555-0103', 'Houston', 'USA');

-- Create sample stores
INSERT INTO stores (name, location, manager_id, capacity) VALUES
('Main Warehouse', 'Building A', 1, 5000),
('Secondary Store', 'Building B', 1, 3000);

-- Create sample store locations
INSERT INTO store_locations (store_id, location_name, aisle, rack, shelf) VALUES
(1, 'Zone A1', 'A', '01', '01'),
(1, 'Zone A2', 'A', '01', '02'),
(1, 'Zone B1', 'B', '01', '01'),
(2, 'Zone C1', 'C', '01', '01');

-- Create sample foundry processes
INSERT INTO foundry_processes (process_name, process_type, temperature_range, duration_minutes) VALUES
('Melting', 'heating', '1500-1600C', 120),
('Casting', 'forming', 'room_temp', 60),
('Cooling', 'cooling', '1600-100C', 240);
