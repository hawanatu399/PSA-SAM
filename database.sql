-- Initial schema only. Synthetic local walkthrough data is in demo_seed.sql.
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

CREATE TABLE IF NOT EXISTS verification_campaigns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_name VARCHAR(180) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('Planned', 'Active', 'Closed') NOT NULL DEFAULT 'Planned',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_verification_campaign_status_dates (status, start_date, end_date),
    KEY idx_verification_campaign_creator (created_by),
    CONSTRAINT chk_verification_campaign_dates CHECK (end_date >= start_date),
    CONSTRAINT fk_verification_campaign_creator FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verification_campaign_departments (
    campaign_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (campaign_id, department_id),
    KEY idx_verification_campaign_departments_department (department_id, campaign_id),
    CONSTRAINT fk_verification_campaign_departments_campaign FOREIGN KEY (campaign_id)
        REFERENCES verification_campaigns (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_verification_campaign_departments_department FOREIGN KEY (department_id)
        REFERENCES departments (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_requisitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 2) NOT NULL,
    purpose VARCHAR(1000) NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approval_notes VARCHAR(1000) NULL,
    issued_by INT UNSIGNED NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    issued_at TIMESTAMP NULL DEFAULT NULL,
    status ENUM('Pending', 'Approved', 'Rejected', 'Issued') NOT NULL DEFAULT 'Pending',
    PRIMARY KEY (id),
    KEY idx_store_requisitions_status (status, requested_at),
    KEY idx_store_requisitions_department (department_id, requested_at),
    KEY idx_store_requisitions_item (item_id),
    KEY idx_store_requisitions_requester (requested_by),
    KEY idx_store_requisitions_approver (approved_by),
    KEY idx_store_requisitions_issuer (issued_by),
    CONSTRAINT chk_store_requisitions_quantity CHECK (quantity > 0),
    CONSTRAINT fk_store_requisitions_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_store_requisitions_item FOREIGN KEY (item_id) REFERENCES store_items (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_store_requisitions_requester FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_store_requisitions_approver FOREIGN KEY (approved_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_store_requisitions_issuer FOREIGN KEY (issued_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NULL,
    asset_tag_snapshot VARCHAR(60) NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    verified_by INT UNSIGNED NOT NULL,
    result ENUM('Found', 'Missing') NOT NULL,
    observed_condition ENUM('Good', 'Fair', 'Poor', 'Unserviceable') NULL,
    notes TEXT NULL,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_asset_verifications_asset_date (asset_id, verified_at),
    KEY idx_asset_verifications_department_date (department_id, verified_at),
    KEY idx_asset_verifications_result_date (result, verified_at),
    KEY idx_asset_verifications_user (verified_by),
    KEY idx_asset_verifications_campaign_department (campaign_id, department_id),
    UNIQUE KEY uq_asset_verifications_campaign_asset (campaign_id, asset_id),
    CONSTRAINT fk_asset_verifications_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_asset_verifications_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_asset_verifications_user FOREIGN KEY (verified_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_asset_verifications_campaign FOREIGN KEY (campaign_id) REFERENCES verification_campaigns (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verification_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    verification_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(68) NOT NULL,
    mime_type ENUM('image/jpeg', 'image/png', 'image/webp') NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_evidence_verification (verification_id),
    UNIQUE KEY uq_verification_evidence_file_name (file_name),
    KEY idx_verification_evidence_uploader (uploaded_by),
    CONSTRAINT chk_verification_evidence_file_size CHECK (file_size > 0 AND file_size <= 4194304),
    CONSTRAINT fk_verification_evidence_verification FOREIGN KEY (verification_id)
        REFERENCES asset_verifications (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_verification_evidence_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
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
