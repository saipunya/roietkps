-- Repayment due date requested in each loan application.
-- Run once after 012_record_corrections.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE loan_applications
    ADD COLUMN repayment_due_date DATE NULL AFTER term_months;

UPDATE loan_applications
SET repayment_due_date = DATE_ADD(DATE(created_at), INTERVAL term_months MONTH)
WHERE repayment_due_date IS NULL;

ALTER TABLE loan_applications
    MODIFY COLUMN repayment_due_date DATE NOT NULL,
    ADD KEY idx_loan_repayment_due (repayment_due_date, status);

UPDATE loan_contracts lc
JOIN loan_applications la ON la.id = lc.loan_application_id
SET lc.maturity_date = la.repayment_due_date
WHERE lc.maturity_date IS NULL;
