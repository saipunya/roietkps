<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/data.php';

$pages = workspace_page_definitions();

$requestedPage = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard';
$page = array_key_exists($requestedPage, $pages) ? $requestedPage : 'dashboard';
$pageTitle = $pages[$page]['title'];
$isDashboard = $page === 'dashboard';
$isWorkspace = true;
$workspaceUrl = 'dashboard.php';
$hasPublicCharts = false;
$notificationMenu = ['unread' => 0, 'items' => []];

try {
    $currentUser = require_account();
    $permission = $pages[$page]['permission'];
    if ($permission !== null && !account_can($currentUser, $permission)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    if (account_can($currentUser, 'notifications.view')) {
        $lastTrackingRefresh = isset($_SESSION['tracking_refreshed_at']) ? (int) $_SESSION['tracking_refreshed_at'] : 0;
        if (time() - $lastTrackingRefresh >= 300 || $page === 'notifications') {
            tracking_refresh_user(app_db(), $currentUser);
            $_SESSION['tracking_refreshed_at'] = time();
        }
        $notificationMenu = notification_menu_data(app_db(), (int) $currentUser['id']);
    }

    if ($page === 'users') {
        require __DIR__ . '/includes/admin-registrations.php';
    } elseif ($page === 'registry') {
        require __DIR__ . '/includes/registry.php';
    } elseif (in_array($page, ['loans', 'loan-documents'], true)) {
        require __DIR__ . '/includes/loans.php';
    } elseif (in_array($page, ['contracts', 'debt'], true)) {
        require __DIR__ . '/includes/tracking.php';
    } elseif ($page === 'documents') {
        require __DIR__ . '/includes/public-documents.php';
    } elseif ($page === 'notifications') {
        require __DIR__ . '/includes/notification-center.php';
    } elseif ($page === 'dashboard') {
        require_once __DIR__ . '/includes/dashboard-data.php';
        $roleDashboard = load_role_dashboard(app_db(), $currentUser);
        define('PUBLIC_DOCUMENTS_LIBRARY_ONLY', true);
        require_once __DIR__ . '/includes/public-documents.php';
        $latestPublicDocuments = public_documents_latest(app_db(), 10);
    }
} catch (Throwable $exception) {
    error_log('Workspace page unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ระบบบัญชีผู้ใช้ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
}

header('Cache-Control: no-store');
require __DIR__ . '/header.php';
require __DIR__ . '/nav.php';
?>
<div class="app-workspace">
  <header class="topbar">
    <div class="topbar-leading">
      <button class="btn icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="เปิดเมนูหลัก"><?= app_icon('menu') ?></button>
      <span class="topbar-agency-icon" aria-hidden="true"><?= app_icon('building') ?></span>
      <span class="topbar-title">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</span>
    </div>
    <div class="topbar-actions">
      <?php if (account_can($currentUser, 'notifications.view')): ?>
      <div class="dropdown notification-dropdown">
        <button class="btn icon-button notification-button" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="การแจ้งเตือนที่ยังไม่อ่าน <?= (int) $notificationMenu['unread'] ?> รายการ">
          <?= app_icon('bell') ?><?php if ($notificationMenu['unread'] > 0): ?><span class="notification-count"><?= e(min(99, $notificationMenu['unread'])) ?><?= $notificationMenu['unread'] > 99 ? '+' : '' ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notification-menu">
          <div class="notification-menu-heading"><strong>การแจ้งเตือน</strong><a href="dashboard.php?page=notifications">ดูทั้งหมด</a></div>
          <?php if (!$notificationMenu['items']): ?><p class="notification-menu-empty">ยังไม่มีการแจ้งเตือน</p><?php else: ?>
          <div class="notification-menu-items">
            <?php foreach ($notificationMenu['items'] as $menuNotification): ?>
            <form action="dashboard.php?page=notifications" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="open_notification"><input type="hidden" name="notification_id" value="<?= (int) $menuNotification['id'] ?>"><button class="notification-menu-item" type="submit"><span class="notification-menu-icon tone-<?= $menuNotification['priority'] === 'urgent' ? 'red' : ($menuNotification['priority'] === 'warning' ? 'amber' : 'green') ?>"><?= app_icon($menuNotification['priority'] === 'urgent' ? 'alert' : 'bell') ?></span><span><strong><?= e($menuNotification['title']) ?></strong><small><?= e(mb_substr($menuNotification['message'], 0, 90, 'UTF-8')) ?></small><time><?= e(notification_time_label($menuNotification['created_at'])) ?></time></span></button></form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <span class="signed-in-name"><?= e($currentUser['full_name']) ?></span>
      <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-primary login-link" type="submit">ออกจากระบบ</button></form>
    </div>
  </header>
  <main id="main-content" class="main-content" tabindex="-1">
    <div class="page-heading">
      <div><h1><?= e($pageTitle) ?></h1><p class="page-description"><?= e($pages[$page]['description']) ?></p></div>
      <?php if ($isDashboard && account_can($currentUser, 'loans.view')): ?>
      <a class="btn btn-primary" href="dashboard.php?page=loans"><?= app_icon('document') ?> ดูทะเบียนเงินกู้</a>
      <?php elseif (in_array($page, ['registry', 'loans', 'loan-documents', 'contracts', 'debt'], true)): ?>
      <button class="btn btn-outline-primary" type="button" data-export-table="recordsTable" data-export-name="<?= e($page) ?>.csv"><?= app_icon('download') ?> ส่งออก CSV</button>
      <?php endif; ?>
    </div>
    <?php
    if ($isDashboard) {
        require __DIR__ . '/views/dashboard-role.php';
        require_once __DIR__ . '/views/dashboard-documents.php';
    } elseif ($page === 'reports') {
        require __DIR__ . '/views/reports.php';
    } elseif ($page === 'registry') {
        require __DIR__ . '/views/registry.php';
    } elseif ($page === 'loans') {
        require __DIR__ . '/views/loans.php';
    } elseif ($page === 'loan-documents') {
        require __DIR__ . '/views/loan-documents.php';
    } elseif ($page === 'contracts') {
        require __DIR__ . '/views/contracts.php';
    } elseif ($page === 'debt') {
        require __DIR__ . '/views/debt.php';
    } elseif ($page === 'users') {
        require __DIR__ . '/views/users.php';
    } elseif ($page === 'notifications') {
        require __DIR__ . '/views/notifications.php';
    } elseif ($page === 'documents') {
        require __DIR__ . '/views/documents.php';
    } else {
        require __DIR__ . '/views/records.php';
    }
    ?>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
