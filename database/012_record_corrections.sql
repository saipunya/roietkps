-- Controlled corrections for approved loans, repayments and follow-up history.
-- Run once after 011_remove_user_email.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE loan_applications
    ADD COLUMN admin_unlocked_at DATETIME NULL AFTER locked_at,
    ADD COLUMN admin_unlocked_by BIGINT UNSIGNED NULL AFTER admin_unlocked_at,
    ADD COLUMN admin_unlock_reason VARCHAR(1000) NULL AFTER admin_unlocked_by,
    ADD KEY idx_loan_admin_unlocked_by (admin_unlocked_by),
    ADD CONSTRAINT fk_loan_admin_unlocked_by FOREIGN KEY (admin_unlocked_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE repayments
    ADD COLUMN replaces_repayment_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN voided_at DATETIME NULL AFTER note,
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(1000) NULL AFTER voided_by,
    ADD UNIQUE KEY uq_repayment_replaces (replaces_repayment_id),
    ADD KEY idx_repayment_voided_by (voided_by),
    ADD CONSTRAINT fk_repayment_replaces FOREIGN KEY (replaces_repayment_id)
        REFERENCES repayments (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_repayment_voided_by FOREIGN KEY (voided_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE contract_follow_ups
    ADD COLUMN replaces_follow_up_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN voided_at DATETIME NULL AFTER next_follow_up_date,
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(1000) NULL AFTER voided_by,
    ADD UNIQUE KEY uq_follow_up_replaces (replaces_follow_up_id),
    ADD KEY idx_follow_up_voided_by (voided_by),
    ADD CONSTRAINT fk_follow_up_replaces FOREIGN KEY (replaces_follow_up_id)
        REFERENCES contract_follow_ups (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_follow_up_voided_by FOREIGN KEY (voided_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
