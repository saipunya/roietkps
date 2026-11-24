<?php
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/loan-documents.php';
require_once __DIR__ . '/includes/loan-conversations.php';

$currentUser = require_account();
$downloadType = isset($_GET['post_approval']) ? 'post_approval'
    : (isset($_GET['message_attachment']) ? 'message_attachment' : (isset($_GET['attachment']) ? 'attachment' : 'id'));
$documentId = filter_var($_GET[$downloadType] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) {
    http_response_code(404);
    exit('ไม่พบเอกสาร');
}

try {
    $db = app_db();
    if ($downloadType === 'post_approval') {
        $document = loan_post_approval_document_access_row($db, $currentUser, (int) $documentId);
        $fileNamePrefix = 'post-approval-';
    } elseif ($downloadType === 'message_attachment') {
        $document = loan_conversation_attachment_access_row($db, $currentUser, (int) $documentId);
        $fileNamePrefix = 'message-attachment-';
    } elseif ($downloadType === 'attachment') {
        $document = loan_review_attachment_access_row($db, $currentUser, (int) $documentId);
        $fileNamePrefix = 'review-attachment-';
    } else {
        $document = loan_document_access_row($db, $currentUser, (int) $documentId);
        $fileNamePrefix = 'loan-document-';
    }
    if (!$document) {
        http_response_code(404);
        exit('ไม่พบเอกสารหรือคุณไม่มีสิทธิ์เข้าถึง');
    }
    $storagePath = realpath(loan_document_storage_path());
    $filePath = $storagePath !== false ? realpath($storagePath . DIRECTORY_SEPARATOR . $document['stored_name']) : false;
    if ($storagePath === false || $filePath === false || strpos($filePath, $storagePath . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('ไม่พบไฟล์เอกสาร');
    }
    $extension = strtolower((string) pathinfo($document['original_name'], PATHINFO_EXTENSION));
    $fallbackName = $fileNamePrefix . (int) $documentId . ($extension !== '' ? '.' . $extension : '');
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open loan document.');
    }
    fpassthru($handle);
    fclose($handle);
    exit;
} catch (Throwable $exception) {
    error_log('Loan document download failed (code: ' . $exception->getCode() . ')');
    if (!headers_sent()) {
        http_response_code(503);
    }
    exit('ไม่สามารถดาวน์โหลดเอกสารได้ในขณะนี้');
}
