-- Add the provincial cooperative director role and staff position titles.
-- Run once after 009_post_approval_tracking.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @position_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'position_title'
);
SET @position_column_sql = IF(
    @position_column_exists = 0,
    'ALTER TABLE users ADD COLUMN position_title VARCHAR(150) NULL AFTER full_name',
    'SELECT 1'
);
PREPARE position_column_statement FROM @position_column_sql;
EXECUTE position_column_statement;
DEALLOCATE PREPARE position_column_statement;

INSERT INTO roles (code, name, description, data_scope, is_active)
VALUES (
    'provincial_director',
    'สหกรณ์จังหวัด',
    'กำกับ ตรวจสอบ อนุมัติ และติดตามงานระดับจังหวัด',
    'province',
    1
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    data_scope = VALUES(data_scope),
    is_active = VALUES(is_active);

-- The provincial cooperative director follows the same provincial workflow
-- permissions as a provincial officer. Copying here also includes permissions
-- added by migrations 003-009.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT director.id, source_permissions.permission_id
FROM roles director
JOIN roles source_role ON source_role.code = 'provincial_officer'
JOIN role_permissions source_permissions ON source_permissions.role_id = source_role.id
WHERE director.code = 'provincial_director';
