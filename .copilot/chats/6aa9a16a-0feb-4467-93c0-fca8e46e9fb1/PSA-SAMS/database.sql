CREATE DATABASE IF NOT EXISTS psa_sams_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE psa_sams_db;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_name VARCHAR(120) NOT NULL,
    code VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (department_name),
    UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin_officer', 'stores_officer', 'approving_officer', 'management') NOT NULL,
    department_id INT UNSIGNED NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_department (department_id),
    KEY idx_users_role_status (role, status),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_key CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_key),
    KEY idx_login_attempts_ip (ip_hash, last_attempt_at),
    KEY idx_login_attempts_email (email_hash, last_attempt_at),
    KEY idx_login_attempts_last_attempt (last_attempt_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_categories_name (category_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag_id VARCHAR(60) NOT NULL,
    asset_name VARCHAR(180) NOT NULL,
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    serial_number VARCHAR(120) NULL,
    category_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    location VARCHAR(180) NULL,
    condition_status ENUM('Good', 'Fair', 'Poor', 'Unserviceable') NOT NULL DEFAULT 'Good',
    custodian_id INT UNSIGNED NULL,
    acquisition_date DATE NULL,
    verification_status ENUM('Verified', 'Unverified') NOT NULL DEFAULT 'Unverified',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_tag (asset_tag_id),
    UNIQUE KEY uq_assets_serial_number (serial_number),
    KEY idx_assets_category (category_id),
    KEY idx_assets_department (department_id),
    KEY idx_assets_custodian (custodian_id),
    KEY idx_assets_condition (condition_status),
    CONSTRAINT fk_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assets_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assets_custodian FOREIGN KEY (custodian_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    assigned_to_user INT UNSIGNED NOT NULL,
    assigned_by_user INT UNSIGNED NOT NULL,
    date_assigned DATE NOT NULL,
    return_date DATE NULL,
    status ENUM('Active', 'Returned') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (id),
    KEY idx_assignments_asset_status (asset_id, status),
    KEY idx_assignments_assigned_to (assigned_to_user),
    KEY idx_assignments_assigned_by (assigned_by_user),
    CONSTRAINT fk_assignments_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assignments_assigned_to FOREIGN KEY (assigned_to_user) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assignments_assigned_by FOREIGN KEY (assigned_by_user) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_transfers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    from_department_id INT UNSIGNED NOT NULL,
    to_department_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    transfer_date DATE NULL,
    status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    PRIMARY KEY (id),
    KEY idx_transfers_status (status),
    KEY idx_transfers_asset (asset_id),
    KEY idx_transfers_from_department (from_department_id),
    KEY idx_transfers_to_department (to_department_id),
    KEY idx_transfers_requested_by (requested_by),
    KEY idx_transfers_approved_by (approved_by),
    CONSTRAINT fk_transfers_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_from_department FOREIGN KEY (from_department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_to_department FOREIGN KEY (to_department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_requested_by FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(180) NOT NULL,
    unit VARCHAR(40) NOT NULL,
    current_stock DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_store_items_name (item_name),
    KEY idx_store_items_stock (current_stock, reorder_level),
    CONSTRAINT chk_store_items_stock CHECK (current_stock >= 0),
    CONSTRAINT chk_store_items_reorder CHECK (reorder_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('IN', 'OUT') NOT NULL,
    quantity DECIMAL(12, 2) NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_stock_transactions_item_date (item_id, transaction_date),
    KEY idx_stock_transactions_performed_by (performed_by),
    CONSTRAINT chk_stock_transactions_quantity CHECK (quantity > 0),
    CONSTRAINT fk_stock_transactions_item FOREIGN KEY (item_id) REFERENCES store_items (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_stock_transactions_user FOREIGN KEY (performed_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    issue_description TEXT NOT NULL,
    repair_cost DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    status ENUM('Reported', 'In-Progress', 'Completed') NOT NULL DEFAULT 'Reported',
    reported_by INT UNSIGNED NOT NULL,
    service_date DATE NULL,
    PRIMARY KEY (id),
    KEY idx_maintenance_status (status),
    KEY idx_maintenance_asset (asset_id),
    KEY idx_maintenance_reported_by (reported_by),
    CONSTRAINT chk_maintenance_cost CHECK (repair_cost >= 0),
    CONSTRAINT fk_maintenance_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_maintenance_reporter FOREIGN KEY (reported_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action_performed VARCHAR(255) NOT NULL,
    target_table VARCHAR(100) NOT NULL,
    record_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_timestamp (`timestamp`),
    KEY idx_audit_user_timestamp (user_id, `timestamp`),
    KEY idx_audit_target_record (target_table, record_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT INTO departments (department_name, code) VALUES
    ('Administration', 'ADMIN'),
    ('ICT Unit', 'ICT'),
    ('Finance', 'FIN')
ON DUPLICATE KEY UPDATE
    department_name = VALUES(department_name),
    code = VALUES(code);

INSERT INTO users (full_name, email, password_hash, role, department_id, status)
SELECT seed.full_name, seed.email, seed.password_hash, seed.role, departments.id, 'Inactive'
FROM (
    SELECT 'System Administrator' AS full_name, 'superadmin@psa.local' AS email,
           '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq' AS password_hash,
           'super_admin' AS role, 'ADMIN' AS department_code
    UNION ALL SELECT 'Administration Officer', 'admin@psa.local',
           '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq',
           'admin_officer', 'ADMIN'
    UNION ALL SELECT 'Stores Officer', 'stores@psa.local',
           '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq',
           'stores_officer', 'ICT'
    UNION ALL SELECT 'Approving Officer', 'approver@psa.local',
           '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq',
           'approving_officer', 'ADMIN'
    UNION ALL SELECT 'Academy Management', 'management@psa.local',
           '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq',
           'management', 'FIN'
) AS seed
JOIN departments ON departments.code = seed.department_code
ON DUPLICATE KEY UPDATE
    email = VALUES(email);

INSERT INTO asset_categories (category_name, description) VALUES
    ('Computing Equipment', 'Desktop computers, laptops and peripherals'),
    ('Office Furniture', 'Desks, chairs and office fittings'),
    ('Vehicles', 'Official vehicles and transport equipment')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

INSERT INTO assets (
    asset_tag_id, asset_name, brand, model, serial_number, category_id, department_id,
    location, condition_status, custodian_id, acquisition_date, verification_status
)
SELECT seed.asset_tag_id, seed.asset_name, seed.brand, seed.model, seed.serial_number,
       categories.id, departments.id, seed.location, seed.condition_status,
       NULL, seed.acquisition_date, seed.verification_status
FROM (
    SELECT 'PSA-ICT-0001' AS asset_tag_id, 'Desktop Computer' AS asset_name, 'Dell' AS brand,
           'OptiPlex 7010' AS model, 'PSA-DEMO-0001' AS serial_number,
           'Computing Equipment' AS category_name, 'ICT' AS department_code,
           'ICT Unit' AS location, 'Good' AS condition_status,
           '2024-03-15' AS acquisition_date, 'Verified' AS verification_status
    UNION ALL SELECT 'PSA-ADM-0001', 'Executive Office Desk', 'Generic', 'Executive 1800',
           'PSA-DEMO-0002', 'Office Furniture', 'ADMIN', 'Administration', 'Fair',
           '2023-08-10', 'Unverified'
    UNION ALL SELECT 'PSA-FIN-0001', 'Laptop Computer', 'HP', 'ProBook 450', 'PSA-DEMO-0003',
           'Computing Equipment', 'FIN', 'Finance Office', 'Poor',
           '2022-05-20', 'Verified'
) AS seed
JOIN asset_categories AS categories ON categories.category_name = seed.category_name
JOIN departments ON departments.code = seed.department_code
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    asset_tag_id = assets.asset_tag_id;

INSERT INTO store_items (item_name, unit, current_stock, reorder_level) VALUES
    ('A4 Copier Paper', 'ream', 8, 10),
    ('Printer Toner', 'cartridge', 18, 5),
    ('Blue Ballpoint Pens', 'box', 4, 6)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name);

INSERT INTO stock_transactions (item_id, transaction_type, quantity, performed_by, notes)
SELECT items.id, 'IN', items.current_stock, users.id, 'Opening balance for demonstration data'
FROM store_items AS items
JOIN users ON users.email = 'stores@psa.local'
WHERE items.current_stock > 0
AND NOT EXISTS (
    SELECT 1 FROM stock_transactions
    WHERE item_id = items.id
      AND notes = 'Opening balance for demonstration data'
);

INSERT INTO maintenance_records (asset_id, issue_description, repair_cost, status, reported_by, service_date)
SELECT assets.id, 'Intermittent startup issue; awaiting technical assessment.', 0.00,
       'Reported', users.id, NULL
FROM assets
JOIN users ON users.email = 'stores@psa.local'
WHERE asset_tag_id = 'PSA-FIN-0001'
AND NOT EXISTS (
    SELECT 1 FROM maintenance_records
    WHERE asset_id = assets.id
      AND issue_description = 'Intermittent startup issue; awaiting technical assessment.'
);

INSERT INTO asset_transfers (
    asset_id, from_department_id, to_department_id, requested_by, status
)
SELECT assets.id, source_department.id, destination_department.id, requester.id, 'Pending'
FROM assets
JOIN departments AS source_department ON source_department.code = 'ICT'
JOIN departments AS destination_department ON destination_department.code = 'ADMIN'
JOIN users AS requester ON requester.email = 'admin@psa.local'
WHERE assets.asset_tag_id = 'PSA-ICT-0001'
AND NOT EXISTS (
    SELECT 1 FROM asset_transfers
    WHERE asset_id = assets.id
      AND from_department_id = source_department.id
      AND to_department_id = destination_department.id
);

INSERT INTO audit_logs (user_id, action_performed, target_table, record_id)
SELECT users.id, 'Sample asset registered', 'assets', assets.id
FROM assets
JOIN users ON users.email = 'admin@psa.local'
WHERE assets.asset_tag_id = 'PSA-ICT-0001'
AND NOT EXISTS (
    SELECT 1 FROM audit_logs
    WHERE action_performed = 'Sample asset registered'
      AND target_table = 'assets'
      AND record_id = assets.id
);
