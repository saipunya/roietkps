<?php
require_once __DIR__ . '/includes/accounts.php';
define('PUBLIC_DOCUMENTS_LIBRARY_ONLY', true);
require_once __DIR__ . '/includes/public-documents.php';

$documentId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if (!$documentId) {
    http_response_code(404);
    exit('ไม่พบเอกสาร');
}

try {
    $db = app_db();
    $query = $db->prepare('SELECT id, original_name, stored_name, file_extension, mime_type, file_size FROM public_documents WHERE id = ? AND is_published = 1');
    $query->execute([(int) $documentId]);
    $document = $query->fetch();
    if (!$document) {
        http_response_code(404);
        exit('ไม่พบเอกสาร');
    }

    $storagePath = realpath(public_document_storage_path());
    $filePath = $storagePath !== false ? realpath($storagePath . DIRECTORY_SEPARATOR . $document['stored_name']) : false;
    if ($storagePath === false || $filePath === false || strpos($filePath, $storagePath . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('ไม่พบไฟล์เอกสาร');
    }

    $db->prepare('UPDATE public_documents SET download_count = download_count + 1 WHERE id = ?')->execute([(int) $documentId]);
    $fallbackName = 'document-' . (int) $documentId . '.' . $document['file_extension'];
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
        throw new RuntimeException('Unable to open document.');
    }
    fpassthru($handle);
    fclose($handle);
    exit;
} catch (Throwable $exception) {
    error_log('Public document download failed (code: ' . $exception->getCode() . ')');
    if (!headers_sent()) {
        http_response_code(503);
    }
    exit('ไม่สามารถดาวน์โหลดเอกสารได้ในขณะนี้');
}
