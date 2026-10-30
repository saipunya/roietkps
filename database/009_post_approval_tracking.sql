-- Post-approval contract, repayment and debt follow-up tracking.
-- Run once after 008_loan_document_workflow.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_follow_ups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED NULL,
    followed_up_at DATETIME NOT NULL,
    channel ENUM('phone', 'line', 'email', 'letter', 'visit', 'other') NOT NULL,
    outcome ENUM(
        'contacted', 'promised_payment', 'no_response', 'disputed',
        'restructure_requested', 'other'
    ) NOT NULL,
    note VARCHAR(2000) NOT NULL,
    next_follow_up_date DATE NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_follow_up_contract_date (contract_id, followed_up_at),
    KEY idx_follow_up_schedule (schedule_id),
    KEY idx_follow_up_next_date (next_follow_up_date),
    KEY idx_follow_up_creator (created_by),
    CONSTRAINT fk_follow_up_contract FOREIGN KEY (contract_id)
        REFERENCES loan_contracts (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_follow_up_schedule FOREIGN KEY (schedule_id)
        REFERENCES repayment_schedules (id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_follow_up_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    (r.code = 'provincial_officer' AND p.code IN (
        'contracts.view', 'contracts.create', 'contracts.manage',
        'repayments.view', 'repayments.record', 'debt.view',
        'debt.record_follow_up'
    ))
    OR (r.code = 'promotion_officer' AND p.code IN (
        'contracts.view', 'repayments.view', 'debt.view',
        'debt.record_follow_up'
    ))
    OR (r.code = 'cooperative_user' AND p.code IN (
        'contracts.view', 'repayments.view', 'debt.view'
    ))
    OR (r.code = 'central_officer' AND p.code IN (
        'contracts.view', 'repayments.view', 'debt.view'
    ))
    OR (r.code = 'system_admin' AND p.code IN (
        'contracts.view', 'contracts.manage', 'repayments.view', 'debt.view'
    ))
);
