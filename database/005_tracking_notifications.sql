-- Workflow tracking and in-app notifications.
-- Run once after 004_provincial_office_registration.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO permissions (code, name) VALUES
    ('notifications.view', 'ดูงานและการแจ้งเตือนของตนเอง')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'notifications.view'
WHERE r.code IN (
    'system_admin', 'central_officer', 'provincial_officer',
    'promotion_officer', 'cooperative_user'
);

CREATE TABLE IF NOT EXISTS notification_rules (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(200) NOT NULL,
    days_value SMALLINT UNSIGNED NOT NULL,
    repeat_days SMALLINT UNSIGNED NULL,
    priority ENUM('normal', 'warning', 'urgent') NOT NULL DEFAULT 'normal',
    is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_rule_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_rules (code, name, days_value, repeat_days, priority) VALUES
    ('registration_approval_sla', 'ตรวจคำขอสมัครสมาชิก', 2, NULL, 'warning'),
    ('officer_assignment_sla', 'มอบหมายเจ้าหน้าที่ส่งเสริม', 2, NULL, 'warning'),
    ('promotion_review_sla', 'เจ้าหน้าที่ส่งเสริมตรวจคำขอกู้', 3, NULL, 'warning'),
    ('revision_sla', 'หน่วยงานแก้ไขคำขอกู้', 5, NULL, 'warning'),
    ('province_review_sla', 'เจ้าหน้าที่จังหวัดพิจารณาคำขอกู้', 5, NULL, 'warning'),
    ('contract_preparation_sla', 'จัดทำสัญญาหลังอนุมัติ', 7, NULL, 'warning'),
    ('repayment_notice', 'แจ้งเตือนก่อนครบกำหนดชำระ', 30, 7, 'warning')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    days_value = VALUES(days_value),
    repeat_days = VALUES(repeat_days),
    priority = VALUES(priority);

CREATE TABLE IF NOT EXISTS workflow_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    cooperative_id BIGINT UNSIGNED NULL,
    province_id SMALLINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) NULL,
    priority ENUM('normal', 'warning', 'urgent') NOT NULL DEFAULT 'normal',
    status ENUM('open', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
    due_at DATETIME NULL,
    action_url VARCHAR(500) NOT NULL,
    source_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed_at DATETIME NULL,
    completed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workflow_task_source (source_key),
    KEY idx_workflow_assignee_status_due (assigned_user_id, status, due_at),
    KEY idx_workflow_entity (entity_type, entity_id, status),
    KEY idx_workflow_cooperative (cooperative_id, status),
    KEY idx_workflow_province (province_id, status),
    KEY idx_workflow_completed_by (completed_by),
    CONSTRAINT fk_workflow_cooperative FOREIGN KEY (cooperative_id)
        REFERENCES cooperatives (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_workflow_province FOREIGN KEY (province_id)
        REFERENCES provinces (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_workflow_assignee FOREIGN KEY (assigned_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_workflow_completed_by FOREIGN KEY (completed_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entity_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    priority ENUM('normal', 'warning', 'urgent') NOT NULL DEFAULT 'normal',
    action_url VARCHAR(500) NOT NULL,
    dedup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_user_dedup (user_id, dedup_key),
    KEY idx_notification_user_read_created (user_id, read_at, created_at),
    KEY idx_notification_entity (entity_type, entity_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('in_app', 'email', 'line') NOT NULL DEFAULT 'in_app',
    status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    delivered_at DATETIME NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_delivery_channel (notification_id, channel),
    KEY idx_delivery_status_created (status, created_at),
    CONSTRAINT fk_delivery_notification FOREIGN KEY (notification_id)
        REFERENCES notifications (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
