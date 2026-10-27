-- Allow provincial office staff to register through the public registration form.
-- Run once after 003_core_workflow.sql.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE registration_requests
    MODIFY COLUMN organization_type ENUM('cooperative', 'farmer_group', 'provincial_office')
    NOT NULL DEFAULT 'cooperative';
