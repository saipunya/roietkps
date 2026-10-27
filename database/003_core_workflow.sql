-- Core cooperative, loan application, approval, contract and repayment workflow.
-- Run once after 001_user_access.sql and 002_registration_approval.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO provinces (code, name)
VALUES ('45', 'ร้อยเอ็ด')
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE cooperatives
    ADD COLUMN organization_type ENUM('cooperative', 'farmer_group') NOT NULL DEFAULT 'cooperative' AFTER name,
    ADD COLUMN registration_no VARCHAR(50) NULL AFTER organization_type,
    ADD COLUMN district VARCHAR(100) NULL AFTER province_id,
    ADD COLUMN subdistrict VARCHAR(100) NULL AFTER district,
    ADD COLUMN address VARCHAR(500) NULL AFTER subdistrict,
    ADD COLUMN postal_code VARCHAR(10) NULL AFTER address,
    ADD COLUMN phone VARCHAR(30) NULL AFTER postal_code,
    ADD COLUMN email VARCHAR(254) NULL AFTER phone,
    ADD COLUMN profile_status ENUM('incomplete', 'complete') NOT NULL DEFAULT 'incomplete' AFTER email,
    ADD COLUMN profile_completed_at DATETIME NULL AFTER profile_status,
    ADD KEY idx_cooperatives_type_status (organization_type, status),
    ADD KEY idx_cooperatives_profile (profile_status);

ALTER TABLE registration_requests
    ADD COLUMN organization_type ENUM('cooperative', 'farmer_group') NOT NULL DEFAULT 'cooperative' AFTER organization;

INSERT INTO roles (code, name, description, data_scope, is_active)
VALUES ('promotion_officer', 'เจ้าหน้าที่ส่งเสริม', 'ตรวจสอบเอกสารและดูแลหน่วยงานที่ได้รับมอบหมาย', 'province', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    data_scope = VALUES(data_scope),
    is_active = VALUES(is_active);

INSERT INTO permissions (code, name) VALUES
    ('organizations.profile.edit', 'แก้ไขข้อมูลหน่วยงาน'),
    ('organizations.assign_officer', 'มอบหมายเจ้าหน้าที่ส่งเสริม'),
    ('loan_applications.create', 'สร้างคำขอกู้'),
    ('loan_applications.edit', 'แก้ไขคำขอกู้ฉบับร่าง'),
    ('loan_applications.submit', 'ส่งคำขอกู้เข้าตรวจสอบ'),
    ('loan_applications.review_promotion', 'ตรวจคำขอในขั้นเจ้าหน้าที่ส่งเสริม'),
    ('loan_applications.review_province', 'ตรวจคำขอในขั้นเจ้าหน้าที่จังหวัด'),
    ('loan_applications.approve', 'อนุมัติคำขอกู้ขั้นสุดท้าย'),
    ('documents.upload', 'อัปโหลดเอกสาร'),
    ('documents.review', 'ตรวจสอบเอกสาร'),
    ('contracts.view', 'ดูข้อมูลสัญญา'),
    ('contracts.create', 'จัดทำสัญญาจากคำขอที่อนุมัติ'),
    ('contracts.manage', 'จัดการข้อมูลสัญญา'),
    ('repayments.view', 'ดูตารางและประวัติการชำระ'),
    ('repayments.record', 'บันทึกรับชำระ'),
    ('records.unlock', 'ปลดล็อกข้อมูลที่อนุมัติแล้ว'),
    ('audit.view', 'ดูประวัติการดำเนินการ')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    (r.code = 'system_admin' AND p.code IN (
        'dashboard.view', 'cooperatives.view', 'cooperatives.manage',
        'organizations.profile.edit', 'organizations.assign_officer',
        'loans.view', 'contracts.view', 'contracts.manage',
        'repayments.view', 'records.unlock', 'audit.view'
    ))
    OR (r.code = 'central_officer' AND p.code IN (
        'contracts.view', 'repayments.view', 'audit.view'
    ))
    OR (r.code = 'provincial_officer' AND p.code IN (
        'organizations.assign_officer', 'loan_applications.review_province',
        'loan_applications.approve', 'documents.review', 'contracts.view',
        'contracts.create', 'contracts.manage', 'repayments.view',
        'repayments.record', 'audit.view'
    ))
    OR (r.code = 'promotion_officer' AND p.code IN (
        'dashboard.view', 'cooperatives.view', 'loans.view',
        'loan_applications.review_promotion', 'documents.review',
        'contracts.view', 'repayments.view', 'debt.view',
        'debt.record_follow_up', 'reports.view'
    ))
    OR (r.code = 'cooperative_user' AND p.code IN (
        'organizations.profile.edit', 'loan_applications.create',
        'loan_applications.edit', 'loan_applications.submit',
        'documents.upload', 'contracts.view', 'repayments.view'
    ))
);

CREATE TABLE organization_officer_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cooperative_id BIGINT UNSIGNED NOT NULL,
    officer_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT UNSIGNED NOT NULL DEFAULT 1,
    starts_on DATE NOT NULL,
    ends_on DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_org_officer_start (cooperative_id, officer_user_id, starts_on),
    KEY idx_assignment_officer_active (officer_user_id, ends_on),
    KEY idx_assignment_assigned_by (assigned_by),
    CONSTRAINT fk_assignment_cooperative FOREIGN KEY (cooperative_id)
        REFERENCES cooperatives (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_officer FOREIGN KEY (officer_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_creator FOREIGN KEY (assigned_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cooperative_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    assigned_officer_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(500) NOT NULL,
    requested_amount DECIMAL(15,2) NOT NULL,
    approved_amount DECIMAL(15,2) NULL,
    proposed_interest_rate DECIMAL(7,4) NOT NULL,
    approved_interest_rate DECIMAL(7,4) NULL,
    term_months SMALLINT UNSIGNED NOT NULL,
    collateral TEXT NULL,
    status ENUM(
        'draft', 'submitted', 'promotion_review', 'revision_requested',
        'province_review', 'approved', 'rejected', 'cancelled'
    ) NOT NULL DEFAULT 'draft',
    review_round SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    submitted_at DATETIME NULL,
    promotion_reviewed_at DATETIME NULL,
    province_reviewed_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    locked_at DATETIME NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_application_no (application_no),
    KEY idx_loan_coop_status (cooperative_id, status),
    KEY idx_loan_officer_status (assigned_officer_id, status),
    KEY idx_loan_status_created (status, created_at),
    KEY idx_loan_created_by (created_by),
    KEY idx_loan_approved_by (approved_by),
    CONSTRAINT fk_loan_cooperative FOREIGN KEY (cooperative_id)
        REFERENCES cooperatives (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_loan_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_loan_officer FOREIGN KEY (assigned_officer_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_loan_approver FOREIGN KEY (approved_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_guarantors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    identity_reference VARCHAR(50) NULL,
    relationship VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(500) NULL,
    guarantee_amount DECIMAL(15,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_guarantor_application (loan_application_id),
    CONSTRAINT fk_guarantor_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organization_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cooperative_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(200) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('uploaded', 'needs_revision', 'verified') NOT NULL DEFAULT 'uploaded',
    uploaded_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    review_note VARCHAR(1000) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_org_document_coop_status (cooperative_id, status),
    KEY idx_org_document_uploader (uploaded_by),
    KEY idx_org_document_reviewer (reviewed_by),
    CONSTRAINT fk_org_document_cooperative FOREIGN KEY (cooperative_id)
        REFERENCES cooperatives (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_org_document_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_org_document_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(200) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('uploaded', 'needs_revision', 'verified') NOT NULL DEFAULT 'uploaded',
    uploaded_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    review_note VARCHAR(1000) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_document_status (loan_application_id, status),
    KEY idx_loan_document_uploader (uploaded_by),
    KEY idx_loan_document_reviewer (reviewed_by),
    CONSTRAINT fk_loan_document_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_loan_document_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_loan_document_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    review_round SMALLINT UNSIGNED NOT NULL,
    review_stage ENUM('promotion', 'province', 'admin') NOT NULL,
    decision ENUM('comment', 'request_revision', 'forward', 'approve', 'reject', 'unlock') NOT NULL,
    from_status VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_status VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    comment VARCHAR(2000) NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_review_application_created (loan_application_id, created_at),
    KEY idx_review_reviewer (reviewed_by),
    CONSTRAINT fk_review_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_review_user FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    cooperative_id BIGINT UNSIGNED NOT NULL,
    contract_no VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    principal_amount DECIMAL(15,2) NOT NULL,
    interest_rate DECIMAL(7,4) NOT NULL,
    term_months SMALLINT UNSIGNED NOT NULL,
    start_date DATE NULL,
    maturity_date DATE NULL,
    outstanding_principal DECIMAL(15,2) NOT NULL,
    status ENUM(
        'awaiting_preparation', 'draft', 'pending_review', 'awaiting_signature',
        'signed', 'pending_disbursement', 'active', 'completed', 'closed', 'cancelled'
    ) NOT NULL DEFAULT 'awaiting_preparation',
    signed_at DATETIME NULL,
    disbursed_at DATETIME NULL,
    locked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contract_application (loan_application_id),
    UNIQUE KEY uq_contract_no (contract_no),
    KEY idx_contract_coop_status (cooperative_id, status),
    KEY idx_contract_maturity (maturity_date, status),
    KEY idx_contract_creator (created_by),
    CONSTRAINT fk_contract_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_contract_cooperative FOREIGN KEY (cooperative_id)
        REFERENCES cooperatives (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_contract_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE repayment_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    installment_no SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    principal_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    interest_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    penalty_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('scheduled', 'partial', 'paid', 'overdue', 'waived') NOT NULL DEFAULT 'scheduled',
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedule_installment (contract_id, installment_no),
    KEY idx_schedule_due_status (due_date, status),
    CONSTRAINT fk_schedule_contract FOREIGN KEY (contract_id)
        REFERENCES loan_contracts (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE repayments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED NULL,
    receipt_no VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    paid_at DATETIME NOT NULL,
    principal_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    penalty_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_channel VARCHAR(100) NULL,
    note VARCHAR(1000) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_repayment_receipt (receipt_no),
    KEY idx_repayment_contract_paid (contract_id, paid_at),
    KEY idx_repayment_schedule (schedule_id),
    KEY idx_repayment_recorder (recorded_by),
    CONSTRAINT fk_repayment_contract FOREIGN KEY (contract_id)
        REFERENCES loan_contracts (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_repayment_schedule FOREIGN KEY (schedule_id)
        REFERENCES repayment_schedules (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_repayment_recorder FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
