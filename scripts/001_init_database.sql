-- ============================================
-- MODULAR ENTERPRISE MANAGEMENT SYSTEM
-- MySQL Database Schema
-- ============================================

-- CORE USERS & ROLES SYSTEM
CREATE TABLE IF NOT EXISTS roles (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  role_id INT NOT NULL,
  department VARCHAR(100),
  is_active BOOLEAN DEFAULT TRUE,
  last_login TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_email (email),
  INDEX idx_role_id (role_id)
);

-- AUDIT TRAIL
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  module VARCHAR(100) NOT NULL,
  action VARCHAR(50) NOT NULL,
  table_name VARCHAR(100),
  record_id INT,
  old_values JSON,
  new_values JSON,
  ip_address VARCHAR(45),
  user_agent TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_user_id (user_id),
  INDEX idx_module (module),
  INDEX idx_created_at (created_at)
);

-- ============================================
-- STORES MODULE (HUB)
-- ============================================
CREATE TABLE IF NOT EXISTS stores (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  location VARCHAR(255),
  capacity_units INT,
  description TEXT,
  is_active BOOLEAN DEFAULT TRUE,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_name (name),
  INDEX idx_is_active (is_active)
);

CREATE TABLE IF NOT EXISTS store_locations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  store_id INT NOT NULL,
  section_name VARCHAR(100),
  shelf_position VARCHAR(50),
  capacity INT,
  current_usage INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  UNIQUE KEY unique_store_section (store_id, section_name)
);

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sku VARCHAR(100) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  unit_of_measure VARCHAR(50),
  quantity INT DEFAULT 0,
  reorder_level INT,
  max_quantity INT,
  store_id INT NOT NULL,
  location_id INT,
  unit_cost DECIMAL(12, 2),
  is_active BOOLEAN DEFAULT TRUE,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id),
  FOREIGN KEY (location_id) REFERENCES store_locations(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_sku (sku),
  INDEX idx_store_id (store_id),
  INDEX idx_name (name)
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  inventory_item_id INT NOT NULL,
  transaction_type ENUM('IN', 'OUT', 'ADJUST', 'RETURN') NOT NULL,
  quantity INT NOT NULL,
  reference_id INT,
  reference_type VARCHAR(50),
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_inventory_item_id (inventory_item_id),
  INDEX idx_reference (reference_id, reference_type),
  INDEX idx_created_at (created_at)
);

-- ============================================
-- PURCHASES MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS suppliers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(100),
  email VARCHAR(255),
  phone VARCHAR(20),
  address TEXT,
  payment_terms VARCHAR(100),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name)
);

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  po_number VARCHAR(50) UNIQUE NOT NULL,
  supplier_id INT NOT NULL,
  order_date DATE NOT NULL,
  expected_delivery_date DATE,
  actual_delivery_date DATE,
  status ENUM('DRAFT', 'PENDING', 'CONFIRMED', 'RECEIVED', 'CANCELLED') DEFAULT 'DRAFT',
  total_amount DECIMAL(15, 2),
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_po_number (po_number),
  INDEX idx_status (status),
  INDEX idx_order_date (order_date)
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  purchase_order_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12, 2),
  line_total DECIMAL(15, 2),
  received_quantity INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
);

-- ============================================
-- FOUNDRY MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS materials (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  material_type VARCHAR(100),
  specification TEXT,
  supplier_id INT,
  unit_cost DECIMAL(12, 2),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE IF NOT EXISTS foundry_processes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  process_name VARCHAR(150) NOT NULL,
  description TEXT,
  material_id INT NOT NULL,
  temperature INT,
  duration_minutes INT,
  yield_percentage DECIMAL(5, 2),
  is_active BOOLEAN DEFAULT TRUE,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (material_id) REFERENCES materials(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS foundry_batches (
  id INT PRIMARY KEY AUTO_INCREMENT,
  batch_number VARCHAR(50) UNIQUE NOT NULL,
  process_id INT NOT NULL,
  material_id INT NOT NULL,
  quantity INT,
  start_date TIMESTAMP,
  end_date TIMESTAMP,
  status ENUM('PLANNED', 'IN_PROGRESS', 'COMPLETED', 'FAILED') DEFAULT 'PLANNED',
  quality_check_result VARCHAR(50),
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (process_id) REFERENCES foundry_processes(id),
  FOREIGN KEY (material_id) REFERENCES materials(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_batch_number (batch_number),
  INDEX idx_status (status)
);

-- ============================================
-- PRODUCTION MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS products (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_code VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  bom_id INT,
  standard_cost DECIMAL(12, 2),
  is_active BOOLEAN DEFAULT TRUE,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_product_code (product_code)
);

CREATE TABLE IF NOT EXISTS production_orders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  order_number VARCHAR(50) UNIQUE NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  start_date DATE,
  end_date DATE,
  status ENUM('PLANNED', 'SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED') DEFAULT 'PLANNED',
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_order_number (order_number),
  INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS production_stages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  production_order_id INT NOT NULL,
  stage_name VARCHAR(100),
  stage_sequence INT,
  start_time TIMESTAMP,
  end_time TIMESTAMP,
  status ENUM('PENDING', 'IN_PROGRESS', 'COMPLETED') DEFAULT 'PENDING',
  operator_id INT,
  quality_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (operator_id) REFERENCES users(id)
);

-- ============================================
-- DISPATCH MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS shipments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shipment_number VARCHAR(50) UNIQUE NOT NULL,
  production_order_id INT,
  ship_date DATE,
  expected_delivery_date DATE,
  actual_delivery_date DATE,
  status ENUM('PENDING', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED') DEFAULT 'PENDING',
  carrier_name VARCHAR(150),
  tracking_number VARCHAR(100),
  destination_address TEXT,
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (production_order_id) REFERENCES production_orders(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_shipment_number (shipment_number),
  INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS shipment_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shipment_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

-- ============================================
-- HR MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS employees (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  employee_id VARCHAR(50) UNIQUE NOT NULL,
  department VARCHAR(100),
  position VARCHAR(100),
  manager_id INT,
  hire_date DATE,
  salary DECIMAL(12, 2),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (manager_id) REFERENCES employees(id),
  INDEX idx_department (department),
  INDEX idx_employee_id (employee_id)
);

CREATE TABLE IF NOT EXISTS attendance (
  id INT PRIMARY KEY AUTO_INCREMENT,
  employee_id INT NOT NULL,
  check_in TIMESTAMP,
  check_out TIMESTAMP,
  hours_worked DECIMAL(5, 2),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id),
  INDEX idx_employee_id (employee_id),
  INDEX idx_check_in (check_in)
);

CREATE TABLE IF NOT EXISTS leaves (
  id INT PRIMARY KEY AUTO_INCREMENT,
  employee_id INT NOT NULL,
  leave_type VARCHAR(50),
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
  reason TEXT,
  approved_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- ============================================
-- DIE SHOP MODULE
-- ============================================
CREATE TABLE IF NOT EXISTS dies (
  id INT PRIMARY KEY AUTO_INCREMENT,
  die_code VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(150) NOT NULL,
  die_type VARCHAR(100),
  status ENUM('NEW', 'ACTIVE', 'MAINTENANCE', 'RETIRED') DEFAULT 'NEW',
  life_cycles INT,
  maintenance_interval_cycles INT,
  last_maintenance_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_die_code (die_code)
);

CREATE TABLE IF NOT EXISTS die_maintenance (
  id INT PRIMARY KEY AUTO_INCREMENT,
  die_id INT NOT NULL,
  maintenance_type VARCHAR(50),
  start_date TIMESTAMP,
  end_date TIMESTAMP,
  technician_id INT,
  notes TEXT,
  status ENUM('SCHEDULED', 'IN_PROGRESS', 'COMPLETED') DEFAULT 'SCHEDULED',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (die_id) REFERENCES dies(id),
  FOREIGN KEY (technician_id) REFERENCES users(id),
  INDEX idx_die_id (die_id)
);

-- ============================================
-- PERMISSIONS & ACL
-- ============================================
CREATE TABLE IF NOT EXISTS permissions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) UNIQUE NOT NULL,
  description TEXT,
  module VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  UNIQUE KEY unique_role_permission (role_id, permission_id)
);

-- ============================================
-- SEED DATA
-- ============================================
INSERT INTO roles (name, description) VALUES
('ADMIN', 'System administrator with full access'),
('MANAGER', 'Department manager with oversight'),
('OPERATOR', 'Operator/staff with data entry access'),
('AUDITOR', 'Auditor with read-only access');

INSERT INTO permissions (name, description, module) VALUES
-- Stores Module
('stores_view', 'View stores and inventory', 'stores'),
('stores_create', 'Create new stores', 'stores'),
('stores_edit', 'Edit store details', 'stores'),
('stores_delete', 'Delete stores', 'stores'),
('inventory_view', 'View inventory', 'stores'),
('inventory_create', 'Create inventory items', 'stores'),
('inventory_edit', 'Edit inventory', 'stores'),
-- Purchases Module
('purchases_view', 'View purchase orders', 'purchases'),
('purchases_create', 'Create purchase orders', 'purchases'),
('purchases_approve', 'Approve purchase orders', 'purchases'),
-- Foundry Module
('foundry_view', 'View foundry batches', 'foundry'),
('foundry_create', 'Create foundry batches', 'foundry'),
-- Production Module
('production_view', 'View production orders', 'production'),
('production_create', 'Create production orders', 'production'),
-- Dispatch Module
('dispatch_view', 'View shipments', 'dispatch'),
('dispatch_create', 'Create shipments', 'dispatch'),
-- HR Module
('hr_view', 'View HR data', 'hr'),
('hr_manage', 'Manage HR data', 'hr'),
-- Die Shop Module
('die_shop_view', 'View dies', 'die_shop'),
('die_shop_manage', 'Manage dies', 'die_shop');

-- Admin gets all permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'ADMIN';

-- Manager gets module-specific view and create permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.name = 'MANAGER' AND p.name IN (
  'stores_view', 'inventory_view', 'purchases_view', 'purchases_create',
  'foundry_view', 'production_view', 'dispatch_view', 'hr_view', 'die_shop_view'
);

-- Operator gets view and create permissions for primary modules
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.name = 'OPERATOR' AND p.name IN (
  'stores_view', 'inventory_view', 'inventory_create', 'inventory_edit',
  'purchases_view', 'foundry_view', 'foundry_create',
  'production_view', 'dispatch_view', 'hr_view'
);

-- Auditor gets read-only permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.name = 'AUDITOR' AND p.name LIKE '%_view';
