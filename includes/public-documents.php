<?php

function public_document_categories(): array
{
    return [
        'manual' => 'คู่มือ',
        'form' => 'แบบฟอร์ม',
        'announcement' => 'ประกาศ/ประชาสัมพันธ์',
        'other' => 'เอกสารอื่น ๆ',
    ];
}

function public_document_allowed_files(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
}

function public_document_storage_path(): string
{
    $configuredPath = getenv('DOCUMENT_STORAGE_PATH');
    if (is_string($configuredPath) && trim($configuredPath) !== '') {
        return rtrim(trim($configuredPath), DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'roietkps-storage' . DIRECTORY_SEPARATOR . 'public-documents';
}

function public_document_can_publish(?array $user): bool
{
    return $user !== null
        && (in_array($user['role_code'], ['system_admin'], true) || role_is_provincial($user['role_code']))
        && account_can($user, 'public_documents.publish');
}

function public_document_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return number_format($bytes) . ' B';
}

function public_document_date(string $utcDate): string
{
    try {
        $date = new DateTime($utcDate, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $date->format('d/m/Y H:i') . ' น.';
    } catch (Throwable $exception) {
        return $utcDate;
    }
}

function public_document_is_new(string $utcDate): bool
{
    $publishedAt = strtotime($utcDate . ' UTC');
    return $publishedAt !== false && $publishedAt >= time() - (3 * 86400);
}

function public_documents_latest(PDO $db, int $limit = 10): array
{
    $limit = max(1, min(10, $limit));
    $query = $db->query(
        'SELECT d.id, d.title, d.description, d.category, d.original_name, d.file_extension, d.file_size, d.download_count, d.is_pinned, d.pinned_at, d.published_at, u.full_name AS uploader_name '
        . 'FROM public_documents d JOIN users u ON u.id = d.uploaded_by '
        . "WHERE d.is_published = 1 ORDER BY d.is_pinned DESC, COALESCE(d.pinned_at, d.published_at) DESC, d.published_at DESC, d.id DESC LIMIT {$limit}"
    );
    return $query->fetchAll();
}

function public_document_clean_original_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: '';
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') {
        return 'document';
    }
    return mb_substr($name, 0, 255, 'UTF-8');
}

function public_document_upload_error(int $code): string
{
    if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return 'ไฟล์มีขนาดเกินกำหนดสูงสุด 20 MB หรือเกินค่าที่เซิร์ฟเวอร์อนุญาต';
    }
    if ($code === UPLOAD_ERR_NO_FILE) {
        return 'กรุณาเลือกไฟล์ที่ต้องการเผยแพร่';
    }
    return 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่';
}

function public_document_store(PDO $db, array $actor, array $values, array $file): int
{
    if (!public_document_can_publish($actor)) {
        throw new DomainException('คุณไม่มีสิทธิ์เผยแพร่เอกสาร');
    }

    $title = trim((string) ($values['title'] ?? ''));
    $description = trim((string) ($values['description'] ?? ''));
    $category = (string) ($values['category'] ?? '');
    if (mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 200) {
        throw new DomainException('กรุณาระบุหัวข้อเอกสาร 3–200 ตัวอักษร');
    }
    if (mb_strlen($description, 'UTF-8') > 1000) {
        throw new DomainException('รายละเอียดเอกสารต้องไม่เกิน 1,000 ตัวอักษร');
    }
    if (!array_key_exists($category, public_document_categories())) {
        throw new DomainException('กรุณาเลือกประเภทเอกสาร');
    }

    $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new DomainException(public_document_upload_error($uploadError));
    }
    $temporaryPath = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > 20 * 1024 * 1024) {
        throw new DomainException('ไฟล์ไม่ถูกต้องหรือมีขนาดเกิน 20 MB');
    }

    $originalName = public_document_clean_original_name((string) ($file['name'] ?? 'document'));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedFiles = public_document_allowed_files();
    if (!isset($allowedFiles[$extension])) {
        throw new DomainException('รองรับเฉพาะ PDF, Word, Excel, PowerPoint, CSV, TXT และ ZIP');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($temporaryPath);
    if (!in_array($mimeType, $allowedFiles[$extension], true)) {
        throw new DomainException('ชนิดของไฟล์ไม่ตรงกับนามสกุลหรือไม่รองรับ');
    }

    $storagePath = public_document_storage_path();
    if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
        throw new RuntimeException('ไม่สามารถเตรียมพื้นที่จัดเก็บเอกสารได้');
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
        $query = $db->prepare('INSERT INTO public_documents (title, description, category, original_name, stored_name, file_extension, mime_type, file_size, file_hash, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$title, $description !== '' ? $description : null, $category, $originalName, $storedName, $extension, $mimeType, $size, $hash, (int) $actor['id']]);
        $documentId = (int) $db->lastInsertId();
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'public_document.upload', 'public_document', ?, ?, ?)");
        $audit->execute([(int) $actor['id'], $documentId, json_encode(['title' => $title, 'category' => $category, 'original_name' => $originalName, 'file_size' => $size], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
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

function public_document_set_pinned(PDO $db, array $actor, int $documentId, bool $isPinned): void
{
    if (!public_document_can_publish($actor)) {
        throw new DomainException('คุณไม่มีสิทธิ์ปักหมุดเอกสาร');
    }
    if ($documentId < 1) {
        throw new DomainException('ไม่พบเอกสารที่เลือก');
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id, title, is_pinned FROM public_documents WHERE id = ? AND is_published = 1 FOR UPDATE');
        $query->execute([$documentId]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('ไม่พบเอกสารที่เลือก');
        }

        $query = $db->prepare('UPDATE public_documents SET is_pinned = ?, pinned_at = ?, pinned_by = ? WHERE id = ?');
        $query->execute([
            $isPinned ? 1 : 0,
            $isPinned ? gmdate('Y-m-d H:i:s') : null,
            $isPinned ? (int) $actor['id'] : null,
            $documentId,
        ]);
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, 'public_document', ?, ?, ?, ?)");
        $audit->execute([
            (int) $actor['id'],
            $isPinned ? 'public_document.pin' : 'public_document.unpin',
            $documentId,
            json_encode(['is_pinned' => (int) $document['is_pinned']], JSON_UNESCAPED_UNICODE),
            json_encode(['is_pinned' => $isPinned ? 1 : 0], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function public_document_update(PDO $db, array $actor, int $documentId, array $values, array $file): void
{
    if (!public_document_can_publish($actor) || $documentId < 1) {
        throw new DomainException('คุณไม่มีสิทธิ์แก้ไขเอกสาร');
    }
    $title = trim((string) ($values['title'] ?? ''));
    $description = trim((string) ($values['description'] ?? ''));
    $category = (string) ($values['category'] ?? '');
    if (mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 200 || mb_strlen($description, 'UTF-8') > 1000 || !array_key_exists($category, public_document_categories())) {
        throw new DomainException('กรุณาตรวจสอบหัวข้อ ประเภท และรายละเอียดเอกสาร');
    }

    $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    $replacement = null;
    if ($uploadError !== UPLOAD_ERR_NO_FILE) {
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new DomainException(public_document_upload_error($uploadError));
        }
        $temporaryPath = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > 20 * 1024 * 1024) {
            throw new DomainException('ไฟล์ไม่ถูกต้องหรือมีขนาดเกิน 20 MB');
        }
        $originalName = public_document_clean_original_name((string) ($file['name'] ?? 'document'));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedFiles = public_document_allowed_files();
        if (!isset($allowedFiles[$extension])) {
            throw new DomainException('รองรับเฉพาะ PDF, Word, Excel, PowerPoint, CSV, TXT และ ZIP');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($temporaryPath);
        if (!in_array($mimeType, $allowedFiles[$extension], true)) {
            throw new DomainException('ชนิดของไฟล์ไม่ตรงกับนามสกุลหรือไม่รองรับ');
        }
        $storagePath = public_document_storage_path();
        if (!is_dir($storagePath) && !mkdir($storagePath, 0750, true) && !is_dir($storagePath)) {
            throw new RuntimeException('ไม่สามารถเตรียมพื้นที่จัดเก็บเอกสารได้');
        }
        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $storagePath . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์บนเซิร์ฟเวอร์ได้');
        }
        @chmod($destination, 0640);
        $fileHash = hash_file('sha256', $destination);
        if (!is_string($fileHash) || strlen($fileHash) !== 64) {
            @unlink($destination);
            throw new RuntimeException('ไม่สามารถตรวจสอบความสมบูรณ์ของไฟล์ได้');
        }
        $replacement = ['original_name' => $originalName, 'stored_name' => $storedName, 'file_extension' => $extension, 'mime_type' => $mimeType, 'file_size' => $size, 'file_hash' => $fileHash, 'destination' => $destination];
    }

    $oldStoredName = null;
    try {
        $db->beginTransaction();
        $query = $db->prepare('SELECT * FROM public_documents WHERE id = ? FOR UPDATE');
        $query->execute([$documentId]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('ไม่พบเอกสารที่ต้องการแก้ไข');
        }
        $oldStoredName = $document['stored_name'];
        if ($replacement) {
            $query = $db->prepare('UPDATE public_documents SET title = ?, description = ?, category = ?, original_name = ?, stored_name = ?, file_extension = ?, mime_type = ?, file_size = ?, file_hash = ? WHERE id = ?');
            $query->execute([$title, $description ?: null, $category, $replacement['original_name'], $replacement['stored_name'], $replacement['file_extension'], $replacement['mime_type'], $replacement['file_size'], $replacement['file_hash'], $documentId]);
        } else {
            $query = $db->prepare('UPDATE public_documents SET title = ?, description = ?, category = ? WHERE id = ?');
            $query->execute([$title, $description ?: null, $category, $documentId]);
        }
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'public_document.update', 'public_document', ?, ?, ?, ?)");
        $audit->execute([(int) $actor['id'], $documentId, json_encode(['title' => $document['title'], 'category' => $document['category'], 'original_name' => $document['original_name']], JSON_UNESCAPED_UNICODE), json_encode(['title' => $title, 'category' => $category, 'original_name' => $replacement['original_name'] ?? $document['original_name']], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($replacement) {
            @unlink($replacement['destination']);
        }
        throw $exception;
    }
    if ($replacement && $oldStoredName && basename($oldStoredName) === $oldStoredName) {
        @unlink(public_document_storage_path() . DIRECTORY_SEPARATOR . $oldStoredName);
    }
}

function public_document_set_published(PDO $db, array $actor, int $documentId, bool $isPublished): void
{
    if (!public_document_can_publish($actor) || $documentId < 1) {
        throw new DomainException('คุณไม่มีสิทธิ์เปลี่ยนสถานะเอกสาร');
    }
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id, title, is_published FROM public_documents WHERE id = ? FOR UPDATE');
        $query->execute([$documentId]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('ไม่พบเอกสารที่เลือก');
        }
        $query = $db->prepare('UPDATE public_documents SET is_published = ?, published_at = IF(? = 1, UTC_TIMESTAMP(), published_at), is_pinned = IF(? = 1, is_pinned, 0), pinned_at = IF(? = 1, pinned_at, NULL), pinned_by = IF(? = 1, pinned_by, NULL) WHERE id = ?');
        $publishedValue = $isPublished ? 1 : 0;
        $query->execute([$publishedValue, $publishedValue, $publishedValue, $publishedValue, $publishedValue, $documentId]);
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, 'public_document', ?, ?, ?, ?)");
        $audit->execute([(int) $actor['id'], $isPublished ? 'public_document.publish' : 'public_document.unpublish', $documentId, json_encode(['is_published' => (int) $document['is_published']]), json_encode(['is_published' => $publishedValue]), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function public_document_delete(PDO $db, array $actor, int $documentId): void
{
    if (!public_document_can_publish($actor) || $documentId < 1) {
        throw new DomainException('คุณไม่มีสิทธิ์ลบเอกสาร');
    }
    $storedName = null;
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM public_documents WHERE id = ? AND is_published = 0 FOR UPDATE');
        $query->execute([$documentId]);
        $document = $query->fetch();
        if (!$document) {
            throw new DomainException('ต้องยกเลิกเผยแพร่เอกสารก่อนจึงจะลบได้');
        }
        $storedName = $document['stored_name'];
        $db->prepare('DELETE FROM public_documents WHERE id = ?')->execute([$documentId]);
        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, ip_hash) VALUES (?, 'public_document.delete', 'public_document', ?, ?, ?)");
        $audit->execute([(int) $actor['id'], $documentId, json_encode(['title' => $document['title'], 'original_name' => $document['original_name']], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    if ($storedName && basename($storedName) === $storedName) {
        @unlink(public_document_storage_path() . DIRECTORY_SEPARATOR . $storedName);
    }
}

if (defined('PUBLIC_DOCUMENTS_LIBRARY_ONLY')) {
    return;
}

$documentsError = '';
$documentsNotice = isset($_SESSION['public_document_notice']) ? (string) $_SESSION['public_document_notice'] : '';
unset($_SESSION['public_document_notice']);
$publicDocumentsReadOnly = defined('PUBLIC_DOCUMENTS_READ_ONLY') && PUBLIC_DOCUMENTS_READ_ONLY;
$canPublishDocuments = !$publicDocumentsReadOnly && public_document_can_publish($currentUser);
$db = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($publicDocumentsReadOnly) {
        http_response_code(405);
        exit('หน้าสาธารณะไม่รับการแก้ไขข้อมูล');
    }
    if (!$currentUser) {
        header('Location: login.php', true, 303);
        exit;
    }
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $documentsError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            if ($operation === 'upload_public_document') {
                public_document_store($db, $currentUser, [
                    'title' => post_text('title'),
                    'description' => post_text('description'),
                    'category' => post_text('category'),
                ], isset($_FILES['document_file']) && is_array($_FILES['document_file']) ? $_FILES['document_file'] : []);
                $_SESSION['public_document_notice'] = 'เผยแพร่เอกสารเรียบร้อยแล้ว';
            } elseif ($operation === 'update_public_document') {
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                public_document_update($db, $currentUser, $documentId, [
                    'title' => post_text('title'),
                    'description' => post_text('description'),
                    'category' => post_text('category'),
                ], isset($_FILES['document_file']) && is_array($_FILES['document_file']) ? $_FILES['document_file'] : []);
                $_SESSION['public_document_notice'] = 'แก้ไขข้อมูลเอกสารเรียบร้อยแล้ว';
            } elseif ($operation === 'toggle_public_document_pin') {
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $pinAction = post_text('pin_action');
                if (!$documentId || !in_array($pinAction, ['pin', 'unpin'], true)) {
                    throw new DomainException('ข้อมูลการปักหมุดไม่ถูกต้อง');
                }
                public_document_set_pinned($db, $currentUser, (int) $documentId, $pinAction === 'pin');
                $_SESSION['public_document_notice'] = $pinAction === 'pin' ? 'ปักหมุดเอกสารแล้ว' : 'ยกเลิกปักหมุดเอกสารแล้ว';
            } elseif ($operation === 'set_public_document_published') {
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $publishAction = post_text('publish_action');
                if (!$documentId || !in_array($publishAction, ['publish', 'unpublish'], true)) {
                    throw new DomainException('ข้อมูลสถานะเอกสารไม่ถูกต้อง');
                }
                public_document_set_published($db, $currentUser, $documentId, $publishAction === 'publish');
                $_SESSION['public_document_notice'] = $publishAction === 'publish' ? 'เผยแพร่เอกสารอีกครั้งแล้ว' : 'ยกเลิกเผยแพร่เอกสารแล้ว';
            } elseif ($operation === 'delete_public_document') {
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                public_document_delete($db, $currentUser, $documentId);
                $_SESSION['public_document_notice'] = 'ลบเอกสารและไฟล์ออกจากระบบแล้ว';
            } else {
                throw new DomainException('ไม่พบคำสั่งที่ต้องการดำเนินการ');
            }
            header('Location: ' . ($workspaceUrl ?? 'dashboard.php') . '?page=documents', true, 303);
            exit;
        } catch (DomainException $exception) {
            http_response_code(422);
            $documentsError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Public document operation failed (code: ' . $exception->getCode() . ')');
            http_response_code(500);
            $documentsError = 'ไม่สามารถดำเนินการกับเอกสารได้ในขณะนี้ กรุณาลองใหม่';
        }
    }
}

$documentSearch = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '';
$documentCategory = isset($_GET['category']) && is_string($_GET['category']) && array_key_exists($_GET['category'], public_document_categories()) ? $_GET['category'] : '';
$documentStatus = $canPublishDocuments && isset($_GET['status']) && in_array($_GET['status'], ['published', 'unpublished'], true) ? $_GET['status'] : '';
$documentPage = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$documentsPerPage = 20;
$documentWhere = [$canPublishDocuments ? '1 = 1' : 'd.is_published = 1'];
$documentParameters = [];
if ($documentStatus !== '') {
    $documentWhere[] = 'd.is_published = ?';
    $documentParameters[] = $documentStatus === 'published' ? 1 : 0;
}
if ($documentSearch !== '') {
    $documentWhere[] = '(d.title LIKE ? OR d.description LIKE ? OR d.original_name LIKE ?)';
    $term = '%' . $documentSearch . '%';
    array_push($documentParameters, $term, $term, $term);
}
if ($documentCategory !== '') {
    $documentWhere[] = 'd.category = ?';
    $documentParameters[] = $documentCategory;
}
$documentWhereSql = implode(' AND ', $documentWhere);
$countQuery = $db->prepare("SELECT COUNT(*) FROM public_documents d WHERE {$documentWhereSql}");
$countQuery->execute($documentParameters);
$documentTotal = (int) $countQuery->fetchColumn();
$documentPageCount = max(1, (int) ceil($documentTotal / $documentsPerPage));
$documentPage = min($documentPage, $documentPageCount);
$documentOffset = ($documentPage - 1) * $documentsPerPage;
$documentListQuery = $db->prepare(
    'SELECT d.id, d.title, d.description, d.category, d.original_name, d.file_extension, d.file_size, d.download_count, d.is_published, d.is_pinned, d.pinned_at, d.published_at, u.full_name AS uploader_name '
    . 'FROM public_documents d JOIN users u ON u.id = d.uploaded_by '
    . "WHERE {$documentWhereSql} ORDER BY d.is_pinned DESC, COALESCE(d.pinned_at, d.published_at) DESC, d.published_at DESC, d.id DESC LIMIT {$documentsPerPage} OFFSET {$documentOffset}"
);
$documentListQuery->execute($documentParameters);
$publicDocuments = $documentListQuery->fetchAll();
$editingPublicDocument = null;
if ($canPublishDocuments && isset($_GET['edit']) && is_scalar($_GET['edit'])) {
    $editDocumentId = (int) $_GET['edit'];
    $query = $db->prepare('SELECT * FROM public_documents WHERE id = ?');
    $query->execute([$editDocumentId]);
    $editingPublicDocument = $query->fetch() ?: null;
}
