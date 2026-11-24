-- Messages and private files for the two conversations on a loan application.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE loan_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_application_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('cooperative', 'province') NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    submission_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body VARCHAR(2000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_message_channel (loan_application_id, channel, id),
    UNIQUE KEY uq_loan_message_submission (loan_application_id, channel, author_user_id, submission_token),
    CONSTRAINT fk_loan_message_application FOREIGN KEY (loan_application_id)
        REFERENCES loan_applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_loan_message_author FOREIGN KEY (author_user_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_message_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_message_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loan_message_attachment_name (stored_name),
    KEY idx_loan_message_attachment_message (loan_message_id),
    CONSTRAINT fk_loan_message_attachment_message FOREIGN KEY (loan_message_id)
        REFERENCES loan_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
