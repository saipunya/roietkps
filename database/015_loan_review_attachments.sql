-- Attachments provided by reviewers when returning an application for revision.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE loan_review_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_review_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_review_attachment_stored_name (stored_name),
    KEY idx_loan_review_attachment_review (loan_review_id),
    CONSTRAINT fk_loan_review_attachment_review FOREIGN KEY (loan_review_id)
        REFERENCES loan_reviews (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
