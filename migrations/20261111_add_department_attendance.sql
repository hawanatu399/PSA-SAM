ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'super_admin',
        'admin_officer',
        'stores_officer',
        'approving_officer',
        'management',
        'department_staff'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS department_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    checked_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_out_at TIMESTAMP NULL DEFAULT NULL,
    is_first_in TINYINT(1) NOT NULL DEFAULT 0,
    is_last_out TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_department_attendance_user_date (user_id, attendance_date),
    KEY idx_department_attendance_department_date (department_id, attendance_date, checked_in_at),
    CONSTRAINT fk_department_attendance_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_department_attendance_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_department_attendance_first_in CHECK (is_first_in IN (0, 1)),
    CONSTRAINT chk_department_attendance_last_out CHECK (is_last_out IN (0, 1))
) ENGINE=InnoDB;
