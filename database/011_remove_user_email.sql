-- User accounts authenticate with username and password only.
-- Run once after 010_provincial_director_and_positions.sql.
-- This removes previously stored user-account email addresses.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @user_email_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'email'
);
SET @user_email_column_sql = IF(
    @user_email_column_exists > 0,
    'ALTER TABLE users DROP COLUMN email',
    'SELECT 1'
);
PREPARE user_email_column_statement FROM @user_email_column_sql;
EXECUTE user_email_column_statement;
DEALLOCATE PREPARE user_email_column_statement;
