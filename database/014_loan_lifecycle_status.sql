-- Unified loan lifecycle display and controlled cancellation history.
-- Run once after 013_loan_repayment_due_date.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO permissions (code, name)
VALUES ('loan_applications.cancel', 'ยกเลิกคำขอกู้ตามขั้นตอนที่อนุญาต')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'loan_applications.cancel'
WHERE r.code IN ('system_admin', 'provincial_officer', 'provincial_director', 'cooperative_user');

ALTER TABLE loan_applications
    ADD COLUMN cancelled_at DATETIME NULL AFTER locked_at,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at,
    ADD COLUMN cancellation_reason VARCHAR(1000) NULL AFTER cancelled_by,
    ADD KEY idx_loan_cancelled_by (cancelled_by),
    ADD CONSTRAINT fk_loan_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE loan_contracts
    ADD COLUMN cancelled_at DATETIME NULL AFTER locked_at,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at,
    ADD COLUMN cancellation_reason VARCHAR(1000) NULL AFTER cancelled_by,
    ADD KEY idx_contract_cancelled_by (cancelled_by),
    ADD CONSTRAINT fk_contract_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
