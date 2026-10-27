-- Public manuals and downloadable documents.
-- Run once after 005_tracking_notifications.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO permissions (code, name) VALUES
    ('public_documents.publish', 'เผยแพร่คู่มือและเอกสารดาวน์โหลด')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'public_documents.publish'
WHERE r.code IN ('system_admin', 'provincial_officer');

CREATE TABLE IF NOT EXISTS public_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(1000) NULL,
    category ENUM('manual', 'form', 'announcement', 'other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_extension VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_published TINYINT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_public_document_stored_name (stored_name),
    KEY idx_public_document_published (is_published, published_at, id),
    KEY idx_public_document_category (category, is_published, published_at),
    KEY idx_public_document_uploader (uploaded_by, created_at),
    CONSTRAINT fk_public_document_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
