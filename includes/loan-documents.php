<?php

function loan_document_types(): array
{
    return [
        'application_form' => 'แบบคำขอกู้',
        'project_plan' => 'โครงการ/แผนการใช้เงิน',
        'financial_statement' => 'งบการเงิน',
        'board_resolution' => 'รายงานการประชุม/มติคณะกรรมการ',
        'collateral' => 'เอกสารหลักประกัน',
        'guarantee' => 'เอกสารผู้ค้ำประกัน',
        'other' => 'เอกสารอื่น ๆ',
    ];
}

function loan_document_statuses(): array
{
    return [
        'uploaded' => 'รอตรวจ',
        'needs_revision' => 'ส่งกลับแก้ไข',
        'verified' => 'ผ่านการตรวจ',
    ];
}

function loan_document_allowed_files(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
}

function loan_document_storage_path(): string
{
    $configuredPath = getenv('LOAN_DOCUMENT_STORAGE_PATH');
    if (is_string($configuredPath) && trim($configuredPath) !== '') {
        return rtrim(trim($configuredPath), DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'roietkps-storage' . DIRECTORY_SEPARATOR . 'loan-documents';
}

function loan_document_clean_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: '';
    $name = trim($name, " .\t\n\r\0\x0B");
    return $name === '' ? 'document' : mb_substr($name, 0, 255, 'UTF-8');
}

function loan_document_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return number_format($bytes) . ' B';
}

function loan_document_date(string $utcDate): string
{
    try {
        $date = new DateTime($utcDate, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $date->format('d/m/Y H:i') . ' น.';
    } catch (Throwable $exception) {
        return $utcDate;
    }
}

function loan_document_upload_error(int $code): string
{
    if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return 'ไฟล์มีขนาดเกิน 20 MB หรือเกินค่าที่เซิร์ฟเวอร์อนุญาต';
    }
    if ($code === UPLOAD_ERR_NO_FILE) {
        return 'กรุณาเลือกไฟล์เอกสาร';
    }
    return 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่';
}

function loan_document_primary_officer(PDO $db, int $cooperativeId, ?int $assignedOfficerId): ?int
{
    if ($assignedOfficerId) {
        return $assignedOfficerId;
    }
    $query = $db->prepare("SELECT officer_user_id FROM organization_officer_assignments WHERE cooperative_id = ? AND is_primary = 1 AND (ends_on IS NULL OR ends_on >= CURRENT_DATE()) ORDER BY starts_on DESC LIMIT 1");
    $query->execute([$cooperativeId]);
    $officerId = $query->fetchColumn();
    return $officerId ? (int) $officerId : null;
}

function loan_document_admin_clear_state(PDO $db, int $applicationId): array
{
    $query = $db->prepare("SELECT a.id, a.application_no, a.status, a.review_round, a.province_reviewed_at,
        (SELECT COUNT(*) FROM loan_documents d WHERE d.loan_application_id = a.id) AS document_count,
        (SELECT COUNT(*) FROM loan_reviews r WHERE r.loan_application_id = a.id AND r.review_stage = 'province') AS province_review_count,
        (SELECT COUNT(*) FROM loan_contracts lc WHERE lc.loan_application_id = a.id) AS contract_count
        FROM loan_applications a WHERE a.id = ?");
    $query->execute([$applicationId]);
    $application = $query->fetch();
    if (!$application) {
        return ['allowed' => false, 'document_count' => 0, 'message' => 'ไม่พบคำขอกู้ที่ต้องการล้างเอกสาร'];
    }

    $documentCount = (int) $application['document_count'];
    if ($documentCount < 1) {
        return ['allowed' => false, 'document_count' => 0, 'message' => 'คำขอนี้ไม่มีเอกสารที่ต้องล้าง'];
    }
    if (!in_array($application['status'], ['draft', 'submitted', 'promotion_review', 'revision_requested'], true)
        || $application['province_reviewed_at'] !== null
        || (int) $application['province_review_count'] > 0
        || (int) $application['contract_count'] > 0) {
        return [
            'allowed' => false,
            'document_count' => $documentCount,
            'message' => 'คำขอนี้เข้าสู่การพิจารณาระดับจังหวัดหรือกระบวนการสัญญาแล้ว จึงไม่สามารถล้างเอกสารได้',
        ];
    }

    return [
        'allowed' => true,
        'document_count' => $documentCount,
        'application_no' => (string) $application['application_no'],
        'message' => '',
    ];
}

function loan_document_remove_stored_files(array $storedNames): array
{
    $removed = 0;
    $failed = 0;
    $storagePath = loan_document_storage_path();
    foreach (array_unique($storedNames) as $storedName) {
        if (!is_string($storedName) || $storedName === '' || basename($storedName) !== $storedName) {
            $failed++;
            continue;
        }
        $path = $storagePath . DIRECTORY_SEPARATOR . $storedName;
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }
        if (@unlink($path)) {
            $removed++;
        } else {
            $failed++;
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}

function loan_document_admin_clear(PDO $db, array $actor, int $applicationId, string $confirmation, string $reason): array
{
    if (!account_is_admin($actor)) {
        throw new DomainException('เฉพาะ Admin เท่านั้นที่ล้างข้อมูลการส่งเอกสารได้');
    }
    if ($applicationId < 1 || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 500) {
        throw new DomainException('กรุณาระบุเหตุผลการล้างข้อมูล 5–500 ตัวอักษร');
    }

    $storedNames = [];
    $result = [];
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id, application_no, status, review_round, province_reviewed_at FROM loan_applications WHERE id = ? FOR UPDATE');
        $query->execute([$applicationId]);
        $application = $query->fetch();
        if (!$application) {
            throw new DomainException('ไม่พบคำขอกู้ที่ต้องการล้างเอกสาร');
        }
        if (!hash_equals((string) $application['application_no'], trim($confirmation))) {
            throw new DomainException('กรุณาพิมพ์เลขที่คำขอให้ตรงกับรายการเพื่อยืนยัน');
        }

        $provinceReview = $db->prepare("SELECT COUNT(*) FROM loan_reviews WHERE loan_application_id = ? AND review_stage = 'province'");
        $provinceReview->execute([$applicationId]);
        $contract = $db->prepare('SELECT COUNT(*) FROM loan_contracts WHERE loan_application_id = ?');
        $contract->execute([$applicationId]);
        if (!in_array($application['status'], ['draft', 'submitted', 'promotion_review', 'revision_requested'], true)
            || $application['province_reviewed_at'] !== null
            || (int) $provinceReview->fetchColumn() > 0
            || (int) $contract->fetchColumn() > 0) {
            throw new DomainException('ไม่สามารถล้างได้ เนื่องจากคำขอเข้าสู่การพิจารณาระดับจังหวัดหรือกระบวนการสัญญาแล้ว');
        }

        $documents = $db->prepare('SELECT id, stored_name FROM loan_documents WHERE loan_application_id = ? ORDER BY id FOR UPDATE');
        $documents->execute([$applicationId]);
        $documentRows = $documents->fetchAll();
        if (!$documentRows) {
            throw new DomainException('คำขอนี้ไม่มีเอกสารที่ต้องล้าง');
        }
        $documentIds = array_map('intval', array_column($documentRows, 'id'));
        $storedNames = array_column($documentRows, 'stored_name');
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));

        $documentReviewCount = $db->prepare("SELECT COUNT(*) FROM loan_document_reviews WHERE loan_document_id IN ({$placeholders})");
        $documentReviewCount->execute($documentIds);
        $reviewCount = (int) $documentReviewCount->fetchColumn();

        $notificationCount = 0;
        $taskCount = 0;
        if (notification_schema_ready($db)) {
            $notification = $db->prepare("DELETE FROM notifications
                WHERE (entity_type = 'loan_document' AND entity_id IN ({$placeholders}))
                   OR (entity_type = 'loan_application' AND entity_id = ? AND category IN ('loan', 'loan_revision'))");
            $notification->execute(array_merge($documentIds, [$applicationId]));
            $notificationCount = $notification->rowCount();

            $tasks = $db->prepare("DELETE FROM workflow_tasks WHERE entity_type = 'loan_application' AND entity_id = ? AND task_type IN ('promotion_review', 'loan_revision')");
            $tasks->execute([$applicationId]);
            $taskCount = $tasks->rowCount();
        }

        $deleteDocumentReviews = $db->prepare("DELETE FROM loan_document_reviews WHERE loan_document_id IN ({$placeholders})");
        $deleteDocumentReviews->execute($documentIds);
        $db->prepare('UPDATE loan_documents SET replaces_document_id = NULL WHERE loan_application_id = ? AND replaces_document_id IS NOT NULL')->execute([$applicationId]);
        $deleteDocuments = $db->prepare('DELETE FROM loan_documents WHERE loan_application_id = ?');
        $deleteDocuments->execute([$applicationId]);

        $promotionReviews = $db->prepare("DELETE FROM loan_reviews WHERE loan_application_id = ? AND review_stage = 'promotion'");
        $promotionReviews->execute([$applicationId]);
        $promotionReviewCount = $promotionReviews->rowCount();

        $db->prepare("UPDATE loan_applications SET status = 'draft', review_round = 1, submitted_at = NULL, promotion_reviewed_at = NULL, row_version = row_version + 1 WHERE id = ?")->execute([$applicationId]);
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'loan_documents.admin_clear', 'loan_application', ?, ?, ?, ?)");
        $audit->execute([
            (int) $actor['id'],
            $applicationId,
            json_encode([
                'status' => $application['status'],
                'review_round' => (int) $application['review_round'],
                'document_count' => count($documentRows),
                'document_review_count' => $reviewCount,
                'promotion_review_count' => $promotionReviewCount,
            ], JSON_UNESCAPED_UNICODE),
            json_encode(['status' => 'draft', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $db->commit();
        $result = [
            'documents' => count($documentRows),
            'document_reviews' => $reviewCount,
            'promotion_reviews' => $promotionReviewCount,
            'notifications' => $notificationCount,
            'tasks' => $taskCount,
        ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $fileResult = loan_document_remove_stored_files($storedNames);
    $result['files_removed'] = $fileResult['removed'];
    $result['files_failed'] = $fileResult['failed'];
    return $result;
}

function loan_document_store(PDO $db, array $actor, int $applicationId, array $values, array $file): int
{
    if ($actor['role_code'] !== 'cooperative_user' || !account_can($actor, 'documents.upload')) {
        throw new DomainException('คุณไม่มีสิทธิ์อัปโหลดเอกสารคำขอกู้');
    }
    $application = $db->prepare("SELECT a.id, a.application_no, a.cooperative_id, a.assigned_officer_id, a.status, c.name AS organization_name FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ? AND a.cooperative_id = ? AND a.status IN ('draft', 'revision_requested')");
    $application->execute([$applicationId, (int) $actor['cooperative_id']]);
    $loan = $application->fetch();
    if (!$loan) {
        throw new DomainException('คำขอนี้ไม่อยู่ในขั้นตอนที่อัปโหลดเอกสารได้');
    }

    $replacementId = isset($values['replaces_document_id']) ? (int) $values['replaces_document_id'] : 0;
    $documentType = trim((string) ($values['document_type'] ?? ''));
    $title = trim((string) ($values['title'] ?? ''));
    if ($replacementId > 0) {
        $replacement = $db->prepare("SELECT d.id, d.document_type, d.title FROM loan_documents d WHERE d.id = ? AND d.loan_application_id = ? AND d.status = 'needs_revision' AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
        $replacement->execute([$replacementId, $applicationId]);
        $oldDocument = $replacement->fetch();
        if (!$oldDocument) {
            throw new DomainException('ไม่พบเอกสารฉบับที่ต้องการแก้ไข');
        }
        $documentType = $oldDocument['document_type'];
        $title = $oldDocument['title'];
    } elseif (!array_key_exists($documentType, loan_document_types()) || mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 200) {
        throw new DomainException('กรุณาเลือกประเภทและระบุชื่อเอกสาร 3–200 ตัวอักษร');
    }

    $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new DomainException(loan_document_upload_error($uploadError));
    }
    $temporaryPath = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > 20 * 1024 * 1024) {
        throw new DomainException('ไฟล์ไม่ถูกต้องหรือมีขนาดเกิน 20 MB');
    }
    $originalName = loan_document_clean_name((string) ($file['name'] ?? 'document'));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedFiles = loan_document_allowed_files();
    if (!isset($allowedFiles[$extension])) {
        throw new DomainException('รองรับเฉพาะ PDF, Word, Excel, JPG, PNG และ ZIP');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($temporaryPath);
    if (!in_array($mimeType, $allowedFiles[$extension], true)) {
        throw new DomainException('ชนิดของไฟล์ไม่ตรงกับนามสกุลหรือไม่รองรับ');
    }

    $storagePath = loan_document_storage_path();
    if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
        throw new RuntimeException('ไม่สามารถเตรียมพื้นที่จัดเก็บเอกสารคำขอกู้ได้');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $destination = $storagePath . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์บนเซิร์ฟเวอร์ได้');
    }
    @chmod($destination, 0640);

    try {
        $db->beginTransaction();
        $hash = hash_file('sha256', $destination);
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('ไม่สามารถตรวจสอบความสมบูรณ์ของไฟล์ได้');
        }
        $query = $db->prepare("INSERT INTO loan_documents (loan_application_id, replaces_document_id, document_type, title, original_name, stored_name, mime_type, file_size, file_hash, status, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'uploaded', ?)");
        $query->execute([$applicationId, $replacementId ?: null, $documentType, $title, $originalName, $storedName, $mimeType, $size, $hash, (int) $actor['id']]);
        $documentId = (int) $db->lastInsertId();
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'loan_document.upload', 'loan_document', ?, ?, ?)");
        $audit->execute([(int) $actor['id'], $documentId, json_encode(['loan_application_id' => $applicationId, 'document_type' => $documentType, 'title' => $title, 'replaces_document_id' => $replacementId ?: null, 'file_size' => $size], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);

        $officerId = loan_document_primary_officer($db, (int) $loan['cooperative_id'], $loan['assigned_officer_id'] ? (int) $loan['assigned_officer_id'] : null);
        if ($officerId) {
            $notificationTitle = $replacementId > 0 ? 'มีเอกสารคำขอกู้ฉบับแก้ไข' : 'มีเอกสารคำขอกู้ใหม่';
            $notificationMessage = $loan['organization_name'] . ($replacementId > 0 ? ' อัปโหลดฉบับแก้ไขของ ' : ' อัปโหลด ') . $title;
            notification_create($db, $officerId, 'loan_document', $notificationTitle, $notificationMessage, 'warning', 'dashboard.php?page=loan-documents&loan=' . $applicationId, 'loan-document:' . $documentId . ':uploaded', 'loan_document', $documentId);
        }
        $db->commit();
        return $documentId;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($destination);
        throw $exception;
    }
}

function loan_document_review(PDO $db, array $actor, int $applicationId, int $documentId, string $decision, string $note): void
{
    if ($actor['role_code'] !== 'promotion_officer' || !account_can($actor, 'documents.review')) {
        throw new DomainException('คุณไม่มีสิทธิ์ตรวจเอกสารรายการนี้');
    }
    if (!in_array($decision, ['verified', 'needs_revision'], true) || mb_strlen($note, 'UTF-8') > 1000 || ($decision === 'needs_revision' && mb_strlen($note, 'UTF-8') < 3)) {
        throw new DomainException('กรุณาระบุผลการตรวจและเหตุผลที่ต้องแก้ไข');
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT d.id, d.title, d.status AS document_status, a.status AS application_status, a.review_round, a.cooperative_id, a.assigned_officer_id FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id WHERE d.id = ? AND d.loan_application_id = ? AND d.status = 'uploaded' AND a.assigned_officer_id = ? AND a.status IN ('submitted', 'promotion_review') AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id) FOR UPDATE");
        $query->execute([$documentId, $applicationId, (int) $actor['id']]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('เอกสารนี้ถูกตรวจแล้วหรือไม่อยู่ในความรับผิดชอบของคุณ');
        }
        $query = $db->prepare('UPDATE loan_documents SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?');
        $query->execute([$decision, (int) $actor['id'], $note !== '' ? $note : null, $documentId]);
        $query = $db->prepare('INSERT INTO loan_document_reviews (loan_document_id, decision, from_status, to_status, note, reviewed_by) VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$documentId, $decision, $document['document_status'], $decision, $note !== '' ? $note : null, (int) $actor['id']]);
        $audit = $db->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, \'loan_document\', ?, ?, ?, ?)');
        $audit->execute([(int) $actor['id'], 'loan_document.' . $decision, $documentId, json_encode(['status' => $document['document_status']]), json_encode(['status' => $decision, 'note' => $note], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);

        if ($decision === 'needs_revision') {
            $db->prepare("UPDATE loan_applications SET status = 'revision_requested', promotion_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?")->execute([$applicationId]);
            $comment = 'เอกสาร "' . $document['title'] . '": ' . $note;
            $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'promotion', 'request_revision', ?, 'revision_requested', ?, ?)")->execute([$applicationId, $document['review_round'], $document['application_status'], $comment, (int) $actor['id']]);
            tracking_promotion_reviewed($db, $applicationId, (int) $actor['id'], 'request_revision', $comment, 'dashboard.php?page=loan-documents&loan=' . $applicationId);
        } else {
            $cooperativeUsers = notification_active_user_ids($db, 'cooperative_user', null, (int) $document['cooperative_id']);
            notification_create_for_users($db, $cooperativeUsers, 'loan_document', 'เอกสารผ่านการตรวจแล้ว', $document['title'] . ' ผ่านการตรวจจากเจ้าหน้าที่ส่งเสริม', 'normal', 'dashboard.php?page=loan-documents&loan=' . $applicationId, 'loan-document:' . $documentId . ':verified', 'loan_document', $documentId);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function loan_document_access_row(PDO $db, array $user, int $documentId): ?array
{
    $query = $db->prepare('SELECT d.*, a.cooperative_id, a.assigned_officer_id, c.province_id FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id JOIN cooperatives c ON c.id = a.cooperative_id WHERE d.id = ?');
    $query->execute([$documentId]);
    $document = $query->fetch();
    if (!$document || !account_can($user, 'loans.view')) {
        return null;
    }
    $allowed = ($user['role_code'] === 'cooperative_user' && $user['cooperative_id'] !== null && (int) $user['cooperative_id'] === (int) $document['cooperative_id'])
        || (role_is_provincial($user['role_code']) && $user['province_id'] !== null && $document['province_id'] !== null && (int) $user['province_id'] === (int) $document['province_id'])
        || in_array($user['role_code'], ['system_admin', 'central_officer'], true);
    if ($user['role_code'] === 'promotion_officer') {
        if ((int) $document['assigned_officer_id'] === (int) $user['id']) {
            $allowed = true;
        } else {
            $assignment = $db->prepare('SELECT id FROM organization_officer_assignments WHERE cooperative_id = ? AND officer_user_id = ? AND (ends_on IS NULL OR ends_on >= CURRENT_DATE()) LIMIT 1');
            $assignment->execute([(int) $document['cooperative_id'], (int) $user['id']]);
            $allowed = (bool) $assignment->fetchColumn();
        }
    }
    return $allowed ? $document : null;
}
