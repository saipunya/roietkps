-- Private loan-document submission, revision and review history.
-- Run once after 007_public_document_pinning.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE loan_documents
    ADD COLUMN replaces_document_id BIGINT UNSIGNED NULL AFTER loan_application_id,
    ADD UNIQUE KEY uq_loan_document_replaces (replaces_document_id),
    ADD CONSTRAINT fk_loan_document_replaces FOREIGN KEY (replaces_document_id)
        REFERENCES loan_documents (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE loan_document_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_document_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('verified', 'needs_revision') NOT NULL,
    from_status ENUM('uploaded', 'needs_revision', 'verified') NOT NULL,
    to_status ENUM('uploaded', 'needs_revision', 'verified') NOT NULL,
    note VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_document_review_document (loan_document_id, created_at),
    KEY idx_loan_document_review_reviewer (reviewed_by, created_at),
    CONSTRAINT fk_loan_document_review_document FOREIGN KEY (loan_document_id)
        REFERENCES loan_documents (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_loan_document_review_user FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
