<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/accounts.php';

try {
    $count = tracking_refresh_all_users(app_db());
    fwrite(STDOUT, 'Updated tracking and notifications for ' . $count . " active users.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Notification refresh failed (code: ' . $exception->getCode() . ").\n");
    exit(1);
}
