<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/loan-documents.php';

try {
    $db = app_db();
    $count = tracking_refresh_all_users($db);
    $purge = loan_document_purge_archived_files($db);
    fwrite(STDOUT, 'Updated tracking and notifications for ' . $count . ' active users. Purged ' . $purge['purged'] . " archived files.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Notification refresh failed (code: ' . $exception->getCode() . ").\n");
    exit(1);
}
