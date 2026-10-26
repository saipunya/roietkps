-- Initial user and access schema. Import once into the selected application database.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
-- No database is selected or created here. No user accounts or passwords are seeded.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE provinces (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provinces_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minimal cooperative reference for account scoping; extend in the registry module.
CREATE TABLE cooperatives (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    province_id SMALLINT UNSIGNED NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cooperatives_code (code),
    UNIQUE KEY uq_cooperatives_id_province (id, province_id),
    KEY idx_cooperatives_province (province_id),
    CONSTRAINT fk_cooperatives_province FOREIGN KEY (province_id)
        REFERENCES provinces (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    data_scope ENUM('none', 'all', 'province', 'cooperative') NOT NULL,
    is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code),
    UNIQUE KEY uq_roles_id_scope (id, data_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(150) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id)
        REFERENCES permissions (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NULL,
    role_id SMALLINT UNSIGNED NOT NULL,
    data_scope ENUM('none', 'all', 'province', 'cooperative') NOT NULL,
    province_id SMALLINT UNSIGNED NULL,
    cooperative_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'active', 'suspended') NOT NULL DEFAULT 'pending',
    must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 1,
    failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    password_changed_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role_scope (role_id, data_scope),
    KEY idx_users_province_status (province_id, status),
    KEY idx_users_cooperative_province (cooperative_id, province_id),
    KEY idx_users_created_by (created_by),
    CONSTRAINT fk_users_role_scope FOREIGN KEY (role_id, data_scope)
        REFERENCES roles (id, data_scope) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_users_province FOREIGN KEY (province_id)
        REFERENCES provinces (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_users_cooperative_province FOREIGN KEY (cooperative_id, province_id)
        REFERENCES cooperatives (id, province_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers enforce account scope on both INSERT and UPDATE, including servers
-- that parse but do not enforce CHECK constraints. Importer needs TRIGGER privilege.
DELIMITER $$
CREATE TRIGGER users_validate_before_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NOT (
        (NEW.data_scope IN ('none', 'all') AND NEW.province_id IS NULL AND NEW.cooperative_id IS NULL)
        OR (NEW.data_scope = 'province' AND NEW.province_id IS NOT NULL AND NEW.cooperative_id IS NULL)
        OR (NEW.data_scope = 'cooperative' AND NEW.province_id IS NOT NULL AND NEW.cooperative_id IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User data scope does not match province/cooperative assignment';
    END IF;
    IF NEW.must_change_password NOT IN (0, 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'must_change_password must be 0 or 1';
    END IF;
END$$

CREATE TRIGGER users_validate_before_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF NOT (
        (NEW.data_scope IN ('none', 'all') AND NEW.province_id IS NULL AND NEW.cooperative_id IS NULL)
        OR (NEW.data_scope = 'province' AND NEW.province_id IS NOT NULL AND NEW.cooperative_id IS NULL)
        OR (NEW.data_scope = 'cooperative' AND NEW.province_id IS NOT NULL AND NEW.cooperative_id IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User data scope does not match province/cooperative assignment';
    END IF;
    IF NEW.must_change_password NOT IN (0, 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'must_change_password must be 0 or 1';
    END IF;
END$$
DELIMITER ;

START TRANSACTION;

INSERT INTO roles (id, code, name, description, data_scope) VALUES
    (1, 'system_admin', 'ผู้ดูแลระบบ', 'จัดการบัญชีและสิทธิ์ แยกจากการอนุมัติธุรกรรม', 'none'),
    (2, 'central_officer', 'เจ้าหน้าที่ส่วนกลาง', 'ตรวจสอบข้อมูลและรายงานภาพรวมทุกพื้นที่', 'all'),
    (3, 'provincial_officer', 'เจ้าหน้าที่จังหวัด', 'ดูแลข้อมูลและติดตามงานเฉพาะจังหวัดที่รับผิดชอบ', 'province'),
    (4, 'cooperative_user', 'ผู้ใช้สหกรณ์', 'ยื่นและติดตามคำขอ ดูข้อมูลเฉพาะสหกรณ์ของตนเอง', 'cooperative');

INSERT INTO permissions (code, name) VALUES
    ('dashboard.view', 'ดูภาพรวมกองทุน'),
    ('cooperatives.view', 'ดูทะเบียนสหกรณ์'),
    ('cooperatives.manage', 'จัดการทะเบียนสหกรณ์'),
    ('loans.view', 'ดูคำขอและข้อมูลเงินกู้'),
    ('loans.submit', 'ยื่นคำขอกู้'),
    ('loans.review', 'ตรวจสอบคำขอและเอกสารประกอบ'),
    ('debt.view', 'ดูข้อมูลหนี้และกำหนดชำระ'),
    ('debt.record_follow_up', 'บันทึกผลการติดตามหนี้'),
    ('reports.view', 'ดูรายงาน'),
    ('reports.export', 'ส่งออกรายงาน'),
    ('users.view', 'ดูบัญชีผู้ใช้งาน'),
    ('users.manage', 'จัดการบัญชีผู้ใช้งาน'),
    ('roles.view', 'ดูบทบาทและสิทธิ์'),
    ('roles.manage', 'จัดการบทบาทและสิทธิ์');

-- Deny unassigned permissions. No loan approval or payment posting is granted.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    (r.code = 'system_admin' AND p.code IN (
        'users.view', 'users.manage', 'roles.view', 'roles.manage'
    ))
    OR (r.code = 'central_officer' AND p.code IN (
        'dashboard.view', 'cooperatives.view', 'loans.view', 'loans.review',
        'debt.view', 'reports.view', 'reports.export'
    ))
    OR (r.code = 'provincial_officer' AND p.code IN (
        'dashboard.view', 'cooperatives.view', 'cooperatives.manage',
        'loans.view', 'loans.review', 'debt.view', 'debt.record_follow_up',
        'reports.view', 'reports.export'
    ))
    OR (r.code = 'cooperative_user' AND p.code IN (
        'dashboard.view', 'cooperatives.view', 'loans.view', 'loans.submit', 'debt.view'
    ))
);

COMMIT;
