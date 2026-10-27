-- Pin important public documents above the regular publication order.
-- Run once after 006_public_documents.sql.
-- MySQL 5.7+ / MariaDB 10.2+, InnoDB, utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE public_documents
    ADD COLUMN is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_published,
    ADD COLUMN pinned_at DATETIME NULL AFTER is_pinned,
    ADD COLUMN pinned_by BIGINT UNSIGNED NULL AFTER pinned_at,
    ADD KEY idx_public_document_pin (is_published, is_pinned, pinned_at, published_at),
    ADD KEY idx_public_document_pinned_by (pinned_by),
    ADD CONSTRAINT fk_public_document_pinned_by FOREIGN KEY (pinned_by)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
