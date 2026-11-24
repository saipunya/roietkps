-- Keep a cooperative's submission note and staged files private until it sends the request.
ALTER TABLE loan_applications
    ADD COLUMN document_draft_message VARCHAR(1700) NULL;

ALTER TABLE loan_documents
    ADD COLUMN is_draft TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- Existing files in requests that have never been submitted are drafts too.
UPDATE loan_documents d
JOIN loan_applications a ON a.id = d.loan_application_id
SET d.is_draft = 1
WHERE a.status = 'draft' AND a.submitted_at IS NULL;
