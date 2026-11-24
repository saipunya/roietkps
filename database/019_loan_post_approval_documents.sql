-- Contracts and supporting documents retained on the approved loan application.
CREATE TABLE loan_post_approval_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    document_kind ENUM('contract', 'supporting') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_approval_file_content (loan_application_id, document_kind, file_hash),
    UNIQUE KEY uq_post_approval_stored_name (stored_name),
    CONSTRAINT fk_post_approval_file_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_post_approval_file_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
