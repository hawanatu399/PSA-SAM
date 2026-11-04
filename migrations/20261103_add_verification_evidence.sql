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
