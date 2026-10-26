-- Run after 001_user_access.sql in the same database. Existing users are preserved.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE registration_requests (
    user_id BIGINT UNSIGNED NOT NULL,
    organization VARCHAR(200) NOT NULL,
    requested_role ENUM('central_officer', 'provincial_officer', 'cooperative_user') NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_registration_reviewer (reviewed_by),
    CONSTRAINT fk_registration_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_registration_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_hash),
    KEY idx_auth_rate_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending applicants have no permissions and cannot request an administrator role.
INSERT INTO roles (code, name, description, data_scope)
VALUES ('applicant', 'ผู้สมัครรออนุมัติ', 'รอผู้ดูแลระบบตรวจสอบและกำหนดสิทธิ์', 'none');
