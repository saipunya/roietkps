-- เก็บเฉพาะเอกสารคำขอฉบับปัจจุบันหลังอนุมัติ
-- ฉบับที่ถูกแทนที่จะเก็บ metadata/hash ไว้ แล้วลบไฟล์จริงภายหลังตาม purge_after

ALTER TABLE loan_documents
    MODIFY stored_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN archived_at DATETIME NULL AFTER reviewed_at,
    ADD COLUMN purge_after DATETIME NULL AFTER archived_at,
    ADD COLUMN purged_at DATETIME NULL AFTER purge_after,
    ADD KEY idx_loan_document_archive (archived_at, purge_after, purged_at);

-- ย้อนทำเครื่องหมายให้คำขอที่อนุมัติไปแล้ว โดยเริ่มระยะพัก 180 วันนับจากวันที่นำ migration นี้เข้า
UPDATE loan_documents d
JOIN loan_applications a ON a.id = d.loan_application_id
JOIN loan_documents newer ON newer.replaces_document_id = d.id
SET d.archived_at = UTC_TIMESTAMP(),
    d.purge_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 180 DAY)
WHERE a.status = 'approved' AND d.archived_at IS NULL;
