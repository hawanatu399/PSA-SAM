-- Local-only synthetic walkthrough records; never import into an operational database.
USE psa_sams_db;
SET NAMES utf8mb4;

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
