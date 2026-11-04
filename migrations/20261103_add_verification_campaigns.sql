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

ALTER TABLE asset_verifications
    ADD COLUMN campaign_id INT UNSIGNED NULL AFTER asset_id,
    ADD UNIQUE KEY uq_asset_verifications_campaign_asset (campaign_id, asset_id),
    ADD KEY idx_asset_verifications_campaign_department (campaign_id, department_id),
    ADD CONSTRAINT fk_asset_verifications_campaign FOREIGN KEY (campaign_id)
        REFERENCES verification_campaigns (id) ON DELETE RESTRICT ON UPDATE CASCADE;
