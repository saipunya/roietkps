<?php

require_once __DIR__ . '/loan-status.php';

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

function loan_document_ini_bytes(string $setting): int
{
    $value = trim((string) ini_get($setting));
    if (!preg_match('/\A(\d+)\s*([KMG]?)\z/i', $value, $parts)) {
        return 0;
    }
    $multipliers = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
    return (int) min(PHP_INT_MAX, (int) $parts[1] * $multipliers[strtoupper($parts[2])]);
}

function loan_document_batch_limits(): array
{
    $requestLimit = loan_document_ini_bytes('post_max_size');
    $fileLimit = loan_document_ini_bytes('upload_max_filesize');
    $serverFileCount = (int) ini_get('max_file_uploads');
    // Leave room for multipart headers and detect excess files before PHP silently truncates them.
    $total = $requestLimit > 0 ? min(100 * 1048576, max(1, $requestLimit - 64 * 1024)) : 100 * 1048576;
    return [
        'count' => min(10, max(1, $serverFileCount - 1)),
        'file' => min(20 * 1048576, $fileLimit > 0 ? $fileLimit : $total, $total),
        'total' => $total,
    ];
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

function loan_document_archive_days(): int
{
    $configured = getenv('LOAN_DOCUMENT_ARCHIVE_DAYS');
    $days = is_string($configured) && ctype_digit($configured) ? (int) $configured : 180;
    return min(3650, max(1, $days));
}

function loan_document_archive_schema_ready(PDO $db): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $query = $db->query("SHOW COLUMNS FROM loan_documents LIKE 'archived_at'");
        $ready = (bool) $query->fetchColumn();
    } catch (Throwable $exception) {
        $ready = false;
    }
    return $ready;
}

function loan_document_archive_superseded_versions(PDO $db, int $applicationId, int $actorId): int
{
    if (!loan_document_archive_schema_ready($db)) {
        return 0;
    }
    $query = $db->prepare('SELECT d.id, d.original_name, d.file_hash FROM loan_documents d
        JOIN loan_documents newer ON newer.replaces_document_id = d.id
        WHERE d.loan_application_id = ? AND d.archived_at IS NULL
        ORDER BY d.id FOR UPDATE');
    $query->execute([$applicationId]);
    $documents = $query->fetchAll();
    if (!$documents) {
        return 0;
    }
    $days = loan_document_archive_days();
    $update = $db->prepare("UPDATE loan_documents
        SET archived_at = UTC_TIMESTAMP(), purge_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$days} DAY)
        WHERE id = ? AND archived_at IS NULL");
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash)
        VALUES (?, 'loan_document.archive', 'loan_document', ?, ?, ?, ?)");
    $archived = 0;
    foreach ($documents as $document) {
        $update->execute([(int) $document['id']]);
        if ($update->rowCount() !== 1) {
            continue;
        }
        $audit->execute([
            $actorId,
            (int) $document['id'],
            json_encode(['file_hash' => $document['file_hash'], 'state' => 'superseded'], JSON_UNESCAPED_UNICODE),
            json_encode(['state' => 'archived', 'purge_after_days' => $days], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $archived++;
    }
    return $archived;
}

function loan_document_purge_archived_files(PDO $db, int $limit = 100): array
{
    if (!loan_document_archive_schema_ready($db)) {
        return ['purged' => 0, 'failed' => 0];
    }
    $limit = min(500, max(1, $limit));
    $query = $db->query("SELECT d.id, d.stored_name, d.file_hash FROM loan_documents d
        JOIN loan_applications a ON a.id = d.loan_application_id
        WHERE a.status = 'approved' AND d.archived_at IS NOT NULL AND d.purge_after <= UTC_TIMESTAMP()
          AND d.purged_at IS NULL AND d.stored_name IS NOT NULL
        ORDER BY d.purge_after, d.id LIMIT {$limit}");
    $purged = 0;
    $failed = 0;
    $update = $db->prepare('UPDATE loan_documents SET stored_name = NULL, purged_at = UTC_TIMESTAMP() WHERE id = ? AND stored_name = ? AND purged_at IS NULL');
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash)
        VALUES (NULL, 'loan_document.purge', 'loan_document', ?, ?, ?, NULL)");
    foreach ($query->fetchAll() as $document) {
        $result = loan_document_remove_stored_files([(string) $document['stored_name']]);
        if ($result['failed'] > 0) {
            $failed++;
            continue;
        }
        $update->execute([(int) $document['id'], (string) $document['stored_name']]);
        if ($update->rowCount() !== 1) {
            continue;
        }
        $audit->execute([
            (int) $document['id'],
            json_encode(['file_hash' => $document['file_hash'], 'stored_file_removed' => true], JSON_UNESCAPED_UNICODE),
            json_encode(['state' => 'purged'], JSON_UNESCAPED_UNICODE),
        ]);
        $purged++;
    }
    return ['purged' => $purged, 'failed' => $failed];
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

        $reviewFiles = $db->prepare("SELECT f.stored_name FROM loan_review_attachments f JOIN loan_reviews r ON r.id = f.loan_review_id WHERE r.loan_application_id = ? AND r.review_stage = 'promotion'");
        $reviewFiles->execute([$applicationId]);
        $storedNames = array_merge($storedNames, $reviewFiles->fetchAll(PDO::FETCH_COLUMN));
        $promotionReviews = $db->prepare("DELETE FROM loan_reviews WHERE loan_application_id = ? AND review_stage = 'promotion'");
        $promotionReviews->execute([$applicationId]);
        $promotionReviewCount = $promotionReviews->rowCount();

        $db->prepare("UPDATE loan_applications SET status = 'draft', review_round = 1, submitted_at = NULL, promotion_reviewed_at = NULL, document_draft_message = NULL, row_version = row_version + 1 WHERE id = ?")->execute([$applicationId]);
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

function loan_document_prepare_upload(array $file): array
{
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

    $hash = hash_file('sha256', $temporaryPath);
    if (!is_string($hash) || strlen($hash) !== 64) {
        throw new RuntimeException('ไม่สามารถตรวจสอบความสมบูรณ์ของไฟล์ได้');
    }

    return [
        'temporary_path' => $temporaryPath,
        'extension' => $extension,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size' => $size,
        'file_hash' => $hash,
    ];
}

function loan_document_save_upload(array $upload): string
{
    $storagePath = loan_document_storage_path();
    if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
        throw new RuntimeException('ไม่สามารถเตรียมพื้นที่จัดเก็บเอกสารคำขอกู้ได้');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
    $destination = $storagePath . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($upload['temporary_path'], $destination)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์บนเซิร์ฟเวอร์ได้');
    }
    @chmod($destination, 0640);
    return $storedName;
}

function loan_document_prepare_uploads(array $files, ?string $deniedMessage = null): array
{
    $errors = $files['error'] ?? [];
    if (!is_array($errors)) {
        throw new DomainException('กรุณาเลือกไฟล์แนบอีกครั้ง');
    }
    $uploads = [];
    foreach ($errors as $index => $error) {
        if (!is_int($error)) {
            throw new DomainException('กรุณาเลือกไฟล์แนบอีกครั้ง');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($deniedMessage !== null) {
            throw new DomainException($deniedMessage);
        }
        $uploads[] = loan_document_prepare_upload([
            'error' => $error,
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'size' => $files['size'][$index] ?? 0,
            'name' => $files['name'][$index] ?? '',
        ]);
    }
    return $uploads;
}

function loan_document_prepare_batch(array $files, int $expectedCount, bool $allowEmpty = false): array
{
    $limits = loan_document_batch_limits();
    $errors = $files['error'] ?? [];
    if (is_array($errors) && count($errors) > $limits['count']) {
        throw new DomainException('เลือกได้ไม่เกิน ' . $limits['count'] . ' ไฟล์ต่อครั้ง กรุณาแบ่งอัปโหลดเป็นชุด');
    }
    // An individual replacement (and the previous upload form) sends a scalar file.
    $uploads = $allowEmpty && $errors === UPLOAD_ERR_NO_FILE
        ? []
        : (is_array($errors) ? loan_document_prepare_uploads($files) : [loan_document_prepare_upload($files)]);
    if ($expectedCount > 0 && count($uploads) !== $expectedCount) {
        throw new DomainException('เซิร์ฟเวอร์ได้รับไฟล์ไม่ครบ กรุณาแบ่งอัปโหลดเป็นชุดเล็กลง');
    }
    if (!$uploads && !$allowEmpty) {
        throw new DomainException('กรุณาเลือกไฟล์เอกสารอย่างน้อย 1 ไฟล์');
    }
    if (array_sum(array_column($uploads, 'file_size')) > $limits['total']) {
        throw new DomainException('ขนาดไฟล์รวมเกินกำหนด กรุณาแบ่งอัปโหลดเป็นชุดเล็กลง');
    }
    return $uploads;
}

function loan_review_attachment_uploads(array $files): array
{
    return loan_document_prepare_uploads($files);
}

function loan_review_save_attachments(PDO $db, int $reviewId, array $uploads, array &$storedNames): void
{
    if (!$uploads) {
        return;
    }
    $query = $db->prepare('INSERT INTO loan_review_attachments (loan_review_id, original_name, stored_name, mime_type, file_size, file_hash) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($uploads as $upload) {
        $storedName = loan_document_save_upload($upload);
        $storedNames[] = $storedName;
        $query->execute([$reviewId, $upload['original_name'], $storedName, $upload['mime_type'], $upload['file_size'], $upload['file_hash']]);
    }
}

function loan_review_attachments_by_application(PDO $db, int $applicationId): array
{
    $query = $db->prepare('SELECT f.id, f.loan_review_id, f.original_name, f.file_size
        FROM loan_review_attachments f JOIN loan_reviews r ON r.id = f.loan_review_id
        WHERE r.loan_application_id = ? ORDER BY f.id');
    $query->execute([$applicationId]);
    $byReview = [];
    foreach ($query as $attachment) {
        $byReview[(int) $attachment['loan_review_id']][] = $attachment;
    }
    return $byReview;
}

/**
 * Removes a file that is still being prepared by the cooperative.  Once the
 * application has left draft, documents are evidence of that review round and
 * must be retained (revisions are uploaded as a new version instead).
 */
function loan_document_delete_draft(PDO $db, array $actor, int $applicationId, int $documentId): array
{
    if ($actor['role_code'] !== 'cooperative_user' || !account_can($actor, 'documents.upload')) {
        throw new DomainException('คุณไม่มีสิทธิ์ลบเอกสารคำขอกู้');
    }

    $storedName = null;
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT d.id, d.original_name, d.stored_name, d.document_type, d.title, d.file_hash, d.file_size, a.status, a.review_round
            FROM loan_documents d
            JOIN loan_applications a ON a.id = d.loan_application_id
            WHERE d.id = ? AND d.loan_application_id = ? AND a.cooperative_id = ?
              AND (a.status = 'draft' OR (a.status = 'revision_requested' AND d.is_draft = 1))
              AND d.status = 'uploaded'
              AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)
              AND NOT EXISTS (SELECT 1 FROM loan_document_reviews r WHERE r.loan_document_id = d.id)
            FOR UPDATE");
        $query->execute([$documentId, $applicationId, (int) $actor['cooperative_id']]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('ลบได้เฉพาะเอกสารฉบับร่างที่ยังไม่ได้ส่งให้เจ้าหน้าที่ตรวจสอบ');
        }
        loan_require_cooperative_revision_turn($db, $applicationId, $document);

        $delete = $db->prepare('DELETE FROM loan_documents WHERE id = ?');
        $delete->execute([$documentId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('ไม่สามารถลบเอกสารได้ กรุณาลองใหม่');
        }

        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash)
            VALUES (?, 'loan_document.delete_draft', 'loan_document', ?, ?, ?, ?)");
        $audit->execute([
            (int) $actor['id'],
            $documentId,
            json_encode([
                'loan_application_id' => $applicationId,
                'document_type' => $document['document_type'],
                'title' => $document['title'],
                'original_name' => $document['original_name'],
                'file_hash' => $document['file_hash'],
                'file_size' => (int) $document['file_size'],
            ], JSON_UNESCAPED_UNICODE),
            json_encode(['state' => 'deleted_before_submission'], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $storedName = (string) $document['stored_name'];
        $db->prepare('UPDATE loan_applications SET row_version = row_version + 1 WHERE id = ?')->execute([$applicationId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $cleanup = loan_document_remove_stored_files([$storedName]);
    if ($cleanup['failed'] > 0) {
        error_log('Loan document file cleanup failed after draft deletion: ' . $documentId);
    }
    return $cleanup;
}

// Called within the upload transaction, which owns rollback and file cleanup.
function loan_documents_store(PDO $db, array $actor, int $applicationId, array $values, array $uploads, array &$storedNames): void
{
    $replacementId = isset($values['replaces_document_id']) ? (int) $values['replaces_document_id'] : 0;
    $documentType = trim((string) ($values['document_type'] ?? ''));
    $title = trim((string) ($values['title'] ?? ''));
    if ($replacementId <= 0 && !array_key_exists($documentType, loan_document_types())) {
        throw new DomainException('กรุณาเลือกประเภทเอกสาร');
    }
    if ($title !== '' && (!mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 200)) {
        throw new DomainException('ชื่อเอกสารต้องมีความยาว 3–200 ตัวอักษร');
    }
    $application = $db->prepare("SELECT status FROM loan_applications WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
    $application->execute([$applicationId, (int) $actor['cooperative_id']]);
    $status = $application->fetchColumn();
    if (!$status) {
        throw new DomainException('คำขอนี้ไม่อยู่ในขั้นตอนที่อัปโหลดเอกสารได้');
    }
    if ($replacementId > 0) {
        if (count($uploads) !== 1) {
            throw new DomainException('กรุณาเลือกไฟล์ฉบับแก้ไขเพียง 1 ไฟล์สำหรับเอกสารเดิม');
        }
        $replacement = $db->prepare("SELECT d.document_type, d.title FROM loan_documents d WHERE d.id = ? AND d.loan_application_id = ? AND d.is_draft = 0 AND (? = 'revision_requested' OR d.status = 'needs_revision') AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
        $replacement->execute([$replacementId, $applicationId, $status]);
        $oldDocument = $replacement->fetch();
        if (!$oldDocument) {
            throw new DomainException('ไม่พบเอกสารฉบับปัจจุบันที่ต้องการแก้ไข');
        }
        $documentType = $oldDocument['document_type'];
        $title = $oldDocument['title'];
    }
    $query = $db->prepare("INSERT INTO loan_documents (loan_application_id, replaces_document_id, document_type, title, original_name, stored_name, mime_type, file_size, file_hash, status, uploaded_by, is_draft) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'uploaded', ?, 1)");
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'loan_document.upload', 'loan_document', ?, ?, ?)");
    foreach ($uploads as $upload) {
        $documentTitle = $title !== '' ? $title : mb_substr($upload['original_name'], 0, 200, 'UTF-8');
        $storedName = loan_document_save_upload($upload);
        $storedNames[] = $storedName;
        $query->execute([$applicationId, $replacementId ?: null, $documentType, $documentTitle, $upload['original_name'], $storedName, $upload['mime_type'], $upload['file_size'], $upload['file_hash'], (int) $actor['id']]);
        $documentId = (int) $db->lastInsertId();
        $audit->execute([(int) $actor['id'], $documentId, json_encode(['loan_application_id' => $applicationId, 'document_type' => $documentType, 'title' => $documentTitle, 'replaces_document_id' => $replacementId ?: null, 'file_size' => $upload['file_size']], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
    }
}

function loan_document_can_review_set(array $actor, array $loan): bool
{
    return $actor['role_code'] === 'promotion_officer' && account_can($actor, 'documents.review')
        && (int) $loan['assigned_officer_id'] === (int) $actor['id']
        && (int) $loan['organization_province_id'] === (int) $actor['province_id']
        && in_array($loan['status'], ['submitted', 'promotion_review'], true);
}

function loan_document_set_review_for_round(PDO $db, int $applicationId, int $round): ?array
{
    $query = $db->prepare("SELECT r.*, u.full_name AS reviewer_name FROM loan_reviews r JOIN users u ON u.id = r.reviewed_by
        WHERE r.loan_application_id = ? AND r.review_round = ? AND r.review_stage = 'promotion'
        AND r.decision = 'comment' AND r.to_status = 'promotion_review' ORDER BY r.id DESC LIMIT 1");
    $query->execute([$applicationId, $round]);
    return $query->fetch() ?: null;
}

// The caller owns the transaction and cleans up attachment files on rollback.
function loan_document_review_set(PDO $db, array $actor, int $applicationId, string $version, string $decision, string $note, array $files, array &$storedAttachments): void
{
    if (!in_array($decision, ['verified', 'request_revision'], true)
        || !mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > 2000) {
        throw new DomainException('กรุณาระบุผลการตรวจและเขียนความเห็นไม่เกิน 2,000 ตัวอักษร');
    }

    $actorId = (int) $actor['id'];
    $loan = loan_promotion_application_for_update($db, $applicationId, $actorId);
    if (!$loan || !loan_document_can_review_set($actor, $loan)) {
        throw new DomainException('คำขอนี้ไม่อยู่ในขั้นตอนที่คุณตรวจชุดเอกสารได้');
    }
    if ($version !== (string) $loan['row_version']) {
        throw new DomainException('คำขอมีข้อมูลใหม่ กรุณาตรวจเอกสารอีกครั้งก่อนบันทึกผล');
    }
    $query = $db->prepare("SELECT id FROM loan_documents d WHERE d.loan_application_id = ? AND d.is_draft = 0
        AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id AND newer.is_draft = 0)
        ORDER BY d.id FOR UPDATE");
    $query->execute([$applicationId]);
    $documentIds = $query->fetchAll(PDO::FETCH_COLUMN);
    if (!$documentIds) {
        throw new DomainException('คำขอนี้ยังไม่มีชุดเอกสารให้ตรวจ');
    }

    $round = (int) $loan['review_round'];
    if (loan_document_set_review_for_round($db, $applicationId, $round)) {
        throw new DomainException('บันทึกผลตรวจชุดเอกสารรอบนี้แล้ว กรุณาเปิดข้อมูลคำขอเพื่อดำเนินการต่อ');
    }
    $uploads = loan_review_attachment_uploads($files);
    if ($note === '' && !$uploads) {
        throw new DomainException('กรุณาเขียนความเห็นหรือแนบไฟล์ประกอบผลการตรวจ');
    }
    $note = $note !== '' ? $note : 'รายละเอียดอยู่ในไฟล์แนบ';
    $verified = $decision === 'verified';
    $toStatus = $verified ? 'promotion_review' : 'revision_requested';
    if ($verified) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $query = $db->prepare("UPDATE loan_documents SET status = 'verified', review_note = NULL, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
            WHERE id IN ({$placeholders}) AND status <> 'verified'");
        $query->execute(array_merge([$actorId], $documentIds));
    }
    $db->prepare('UPDATE loan_applications SET status = ?, promotion_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')
        ->execute([$toStatus, $applicationId]);
    $review = $db->prepare("INSERT INTO loan_reviews
        (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by)
        VALUES (?, ?, 'promotion', ?, ?, ?, ?, ?)");
    $review->execute([$applicationId, $round, $verified ? 'comment' : 'request_revision', $loan['status'], $toStatus, $note, $actorId]);
    $reviewId = (int) $db->lastInsertId();
    loan_review_save_attachments($db, $reviewId, $uploads, $storedAttachments);
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash)
        VALUES (?, 'loan_document_set.review', 'loan_application', ?, ?, ?)");
    $audit->execute([$actorId, $applicationId, json_encode(['review_id' => $reviewId, 'result' => $decision, 'document_ids' => $documentIds]), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);

    $url = 'dashboard.php?page=loan-documents&loan=' . $applicationId;
    if ($verified) {
        $users = notification_active_user_ids($db, 'cooperative_user', null, (int) $loan['cooperative_id']);
        notification_create_for_users($db, $users, 'loan', 'ชุดเอกสารผ่านการตรวจแล้ว', mb_substr($note, 0, 180, 'UTF-8'),
            'normal', $url, 'loan:' . $applicationId . ':document-set:' . $round, 'loan_application', $applicationId);
    } else {
        tracking_promotion_reviewed($db, $applicationId, $actorId, 'request_revision', $note, $url);
    }
}

function loan_document_access_row(PDO $db, array $user, int $documentId): ?array
{
    $archiveScope = loan_document_archive_schema_ready($db) ? ' AND d.archived_at IS NULL' : '';
    $draftScope = loan_document_can_view_drafts($user) ? '' : ' AND d.is_draft = 0';
    $query = $db->prepare('SELECT d.*, a.cooperative_id, a.assigned_officer_id, c.province_id FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id JOIN cooperatives c ON c.id = a.cooperative_id WHERE d.id = ?' . $archiveScope . $draftScope);
    $query->execute([$documentId]);
    $document = $query->fetch();
    return $document && loan_document_user_can_access($db, $user, $document) ? $document : null;
}

function loan_document_can_view_drafts(array $user): bool
{
    return $user['role_code'] === 'cooperative_user' || account_is_admin($user);
}

function loan_post_approval_document_kinds(): array
{
    return ['contract' => 'สัญญาเงินกู้', 'supporting' => 'เอกสารประกอบหลังอนุมัติ'];
}

function loan_post_approval_can_upload(array $actor, array $loan): bool
{
    return role_is_provincial($actor['role_code']) && account_can($actor, 'contracts.create') && account_can($actor, 'contracts.view')
        && $actor['province_id'] !== null && (int) $actor['province_id'] === (int) $loan['province_id']
        && $loan['status'] === 'approved';
}

// The caller commits the batch and removes newly stored files if the transaction rolls back.
function loan_post_approval_store(PDO $db, array $actor, int $applicationId, string $kind, array $files, array &$storedFiles): int
{
    if (!isset(loan_post_approval_document_kinds()[$kind])) {
        throw new DomainException('กรุณาเลือกประเภทเอกสารหลังอนุมัติ');
    }
    $query = $db->prepare('SELECT a.*, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ? FOR UPDATE');
    $query->execute([$applicationId]);
    $loan = $query->fetch();
    if (!$loan || !loan_post_approval_can_upload($actor, $loan)) {
        throw new DomainException('เฉพาะเจ้าหน้าที่จังหวัดที่รับผิดชอบเท่านั้นที่แนบไฟล์ได้หลังคำขอได้รับอนุมัติ');
    }
    if ($loan['admin_unlocked_at'] !== null) {
        throw new DomainException('คำขอนี้กำลังแก้ไขข้อมูลที่อนุมัติ กรุณาแนบไฟล์หลังระบบล็อกข้อมูลอีกครั้ง');
    }
    $uploads = loan_document_prepare_batch($files, 0);
    $existing = $db->prepare('SELECT id FROM loan_post_approval_documents WHERE loan_application_id = ? AND document_kind = ? AND file_hash = ?');
    $insert = $db->prepare('INSERT INTO loan_post_approval_documents
        (loan_application_id, document_kind, original_name, stored_name, mime_type, file_size, file_hash, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash)
        VALUES (?, 'loan.post_approval_document.upload', 'loan_post_approval_document', ?, ?, ?)");
    $actorId = (int) $actor['id'];
    foreach ($uploads as $upload) {
        $existing->execute([$applicationId, $kind, $upload['file_hash']]);
        if ($existing->fetchColumn()) {
            continue;
        }
        $storedName = loan_document_save_upload($upload);
        $storedFiles[] = $storedName;
        $insert->execute([$applicationId, $kind, $upload['original_name'], $storedName, $upload['mime_type'],
            $upload['file_size'], $upload['file_hash'], $actorId]);
        $documentId = (int) $db->lastInsertId();
        $audit->execute([$actorId, $documentId, json_encode(['loan_application_id' => $applicationId, 'document_kind' => $kind,
                'file_hash' => $upload['file_hash']], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
    }
    if ($kind === 'contract' && $storedFiles) {
        $query = $db->prepare('SELECT id FROM loan_contracts WHERE loan_application_id = ? FOR UPDATE');
        $query->execute([$applicationId]);
        if (!$query->fetchColumn()) {
            $insertContract = $db->prepare("INSERT INTO loan_contracts
                (loan_application_id, cooperative_id, principal_amount, interest_rate, term_months, maturity_date, outstanding_principal, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_preparation', ?)");
            $insertContract->execute([$applicationId, $loan['cooperative_id'], $loan['approved_amount'], $loan['approved_interest_rate'],
                $loan['term_months'], $loan['repayment_due_date'], $loan['approved_amount'], $actorId]);
        }
    }
    return count($storedFiles);
}

function loan_post_approval_document_access_row(PDO $db, array $user, int $documentId): ?array
{
    if (!account_can($user, 'contracts.view')) {
        return null;
    }
    $query = $db->prepare("SELECT d.*, a.cooperative_id, a.assigned_officer_id, c.province_id
        FROM loan_post_approval_documents d JOIN loan_applications a ON a.id = d.loan_application_id
        JOIN cooperatives c ON c.id = a.cooperative_id WHERE d.id = ? AND a.status = 'approved'");
    $query->execute([$documentId]);
    $document = $query->fetch();
    return $document && loan_document_user_can_access($db, $user, $document) ? $document : null;
}

function loan_review_attachment_access_row(PDO $db, array $user, int $attachmentId): ?array
{
    $query = $db->prepare("SELECT f.*, a.cooperative_id, a.assigned_officer_id, c.province_id
        FROM loan_review_attachments f JOIN loan_reviews r ON r.id = f.loan_review_id
        JOIN loan_applications a ON a.id = r.loan_application_id JOIN cooperatives c ON c.id = a.cooperative_id
        WHERE f.id = ?");
    $query->execute([$attachmentId]);
    $attachment = $query->fetch();
    return $attachment && loan_document_user_can_access($db, $user, $attachment) ? $attachment : null;
}

function loan_document_user_can_access(PDO $db, array $user, array $document): bool
{
    if (!account_can($user, 'loans.view')) {
        return false;
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
    return $allowed;
}
