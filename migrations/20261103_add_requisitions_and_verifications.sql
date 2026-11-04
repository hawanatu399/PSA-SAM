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
    CONSTRAINT fk_asset_verifications_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_asset_verifications_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_asset_verifications_user FOREIGN KEY (verified_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
