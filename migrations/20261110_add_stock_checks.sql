CREATE TABLE IF NOT EXISTS department_stock (
    department_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    current_stock DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (department_id, item_id),
    KEY idx_department_stock_item (item_id),
    CONSTRAINT chk_department_stock_quantity CHECK (current_stock >= 0),
    CONSTRAINT fk_department_stock_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_department_stock_item FOREIGN KEY (item_id) REFERENCES store_items (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO department_stock (department_id, item_id, current_stock)
SELECT department_id, item_id, SUM(quantity)
FROM store_requisitions
WHERE status = 'Issued'
GROUP BY department_id, item_id;

CREATE TABLE IF NOT EXISTS stock_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    shift ENUM('Opening', 'Closing') NOT NULL,
    check_date DATE NOT NULL,
    expected_stock DECIMAL(12, 2) NOT NULL,
    actual_stock DECIMAL(12, 2) NOT NULL,
    result ENUM('Confirmed', 'Adjusted') NOT NULL,
    notes VARCHAR(1000) NULL,
    checked_by INT UNSIGNED NOT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_checks_date_shift_department (check_date, shift, department_id, item_id, id),
    KEY idx_stock_checks_checked_by (checked_by),
    CONSTRAINT chk_stock_checks_expected CHECK (expected_stock >= 0),
    CONSTRAINT chk_stock_checks_actual CHECK (actual_stock >= 0),
    CONSTRAINT fk_stock_checks_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_stock_checks_item FOREIGN KEY (item_id) REFERENCES store_items (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_stock_checks_user FOREIGN KEY (checked_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
