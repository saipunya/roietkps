<?php
require_once __DIR__ . '/includes/ui.php';
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="th" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#245d4b">
  <?php if (!empty($isAuthPage) || !empty($isWorkspace)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <title><?= e(isset($pageTitle) ? $pageTitle : 'ระบบสารสนเทศ กพส.') ?> | RoietKPS</title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/app.css?v=12" rel="stylesheet">
</head>
<body<?= !empty($isAuthPage) ? ' class="auth-body"' : '' ?>>
  <a class="skip-link" href="#main-content">ข้ามไปเนื้อหาหลัก</a>
