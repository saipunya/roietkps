<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/data.php';

$pages = [
    'dashboard' => ['title' => 'ภาพรวมการบริหารกองทุนพัฒนาสหกรณ์', 'description' => 'ข้อมูลสำคัญและรายการที่คุณควรติดตาม', 'icon' => 'dashboard', 'permission' => 'dashboard.view'],
    'registry' => ['title' => 'ทะเบียนสหกรณ์', 'description' => 'ค้นหาข้อมูลสหกรณ์และพื้นที่รับผิดชอบ', 'icon' => 'building', 'permission' => 'cooperatives.view'],
    'loans' => ['title' => 'ทะเบียนเงินกู้', 'description' => 'ติดตามคำขอกู้และผลการพิจารณาในที่เดียว', 'icon' => 'document', 'permission' => 'loans.view'],
    'debt' => ['title' => 'ติดตามหนี้', 'description' => 'ตรวจสอบกำหนดชำระและรายการที่ต้องติดตาม', 'icon' => 'clock', 'permission' => 'debt.view'],
    'reports' => ['title' => 'รายงาน', 'description' => 'เลือกชุดข้อมูลที่ต้องการตรวจสอบหรือส่งออก', 'icon' => 'chart', 'permission' => 'reports.view'],
    'users' => ['title' => 'ผู้ใช้งานและสิทธิ์', 'description' => 'ตรวจคำขอสมัครสมาชิกและกำหนดสิทธิ์เข้าใช้งาน', 'icon' => 'users', 'permission' => 'users.manage'],
    'notifications' => ['title' => 'งานและการแจ้งเตือน', 'description' => 'ติดตามงานที่ต้องดำเนินการ กำหนดเวลา และข่าวสารของคุณ', 'icon' => 'bell', 'permission' => 'notifications.view', 'hidden' => true],
];
$requestedPage = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard';
$page = array_key_exists($requestedPage, $pages) ? $requestedPage : 'dashboard';
$pageTitle = $pages[$page]['title'];
$isDashboard = $page === 'dashboard';
$loginModalFeedback = isset($_SESSION['login_modal_feedback']) && is_array($_SESSION['login_modal_feedback'])
    ? $_SESSION['login_modal_feedback']
    : [];
unset($_SESSION['login_modal_feedback']);
$loginNotice = isset($_SESSION['registration_complete'])
    ? 'สมัครสมาชิกสำเร็จ บัญชีของคุณกำลังรอ Admin อนุมัติ'
    : '';
unset($_SESSION['registration_complete']);
$notificationMenu = ['unread' => 0, 'items' => []];

try {
    $currentUser = current_account();
    if ($currentUser && (int) $currentUser['must_change_password'] === 1) {
        header('Location: change-password.php', true, 303);
        exit;
    }
    if ($currentUser && account_can($currentUser, 'notifications.view')) {
        $lastTrackingRefresh = isset($_SESSION['tracking_refreshed_at']) ? (int) $_SESSION['tracking_refreshed_at'] : 0;
        if (time() - $lastTrackingRefresh >= 300 || $page === 'notifications') {
            tracking_refresh_user(app_db(), $currentUser);
            $_SESSION['tracking_refreshed_at'] = time();
        }
        $notificationMenu = notification_menu_data(app_db(), (int) $currentUser['id']);
    }
    if ($page !== 'dashboard') {
        $currentUser = require_account();
        if (!account_can($currentUser, $pages[$page]['permission'])) {
            http_response_code(403);
            exit('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }
    }
    if ($page === 'users') {
        header('Cache-Control: no-store');
        require __DIR__ . '/includes/admin-registrations.php';
    } elseif ($page === 'registry') {
        header('Cache-Control: no-store');
        require __DIR__ . '/includes/registry.php';
    } elseif ($page === 'loans') {
        header('Cache-Control: no-store');
        require __DIR__ . '/includes/loans.php';
    } elseif ($page === 'notifications') {
        header('Cache-Control: no-store');
        require __DIR__ . '/includes/notification-center.php';
    }
} catch (Throwable $exception) {
    error_log('Account page unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ระบบบัญชีผู้ใช้ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
}
if ($currentUser) {
    header('Cache-Control: no-store');
}
$openLoginModal = !$currentUser && (isset($_GET['login']) || $loginModalFeedback || $loginNotice !== '');

require __DIR__ . '/header.php';
require __DIR__ . '/nav.php';
?>
<div class="app-workspace">
  <header class="topbar">
    <div class="topbar-leading">
      <button class="btn icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar"
        aria-controls="appSidebar" aria-label="เปิดเมนูหลัก"><?= app_icon('menu') ?></button>
      <span class="topbar-agency-icon" aria-hidden="true"><?= app_icon('building') ?></span>
      <span class="topbar-title">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</span>
    </div>
    <div class="topbar-actions">
      <?php if ($currentUser): ?>
      <div class="dropdown notification-dropdown">
        <button class="btn icon-button notification-button" type="button" data-bs-toggle="dropdown"
          data-bs-auto-close="outside" aria-expanded="false" aria-label="การแจ้งเตือนที่ยังไม่อ่าน <?= (int) $notificationMenu['unread'] ?> รายการ">
          <?= app_icon('bell') ?><?php if ($notificationMenu['unread'] > 0): ?><span class="notification-count"><?= e(min(99, $notificationMenu['unread'])) ?><?= $notificationMenu['unread'] > 99 ? '+' : '' ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notification-menu">
          <div class="notification-menu-heading"><strong>การแจ้งเตือน</strong><a href="index.php?page=notifications">ดูทั้งหมด</a></div>
          <?php if (!$notificationMenu['items']): ?><p class="notification-menu-empty">ยังไม่มีการแจ้งเตือน</p><?php else: ?>
          <div class="notification-menu-items">
            <?php foreach ($notificationMenu['items'] as $menuNotification): ?>
            <form action="index.php?page=notifications" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="open_notification"><input type="hidden" name="notification_id" value="<?= (int) $menuNotification['id'] ?>"><button class="notification-menu-item" type="submit"><span class="notification-menu-icon tone-<?= $menuNotification['priority'] === 'urgent' ? 'red' : ($menuNotification['priority'] === 'warning' ? 'amber' : 'green') ?>"><?= app_icon($menuNotification['priority'] === 'urgent' ? 'alert' : 'bell') ?></span><span><strong><?= e($menuNotification['title']) ?></strong><small><?= e(mb_substr($menuNotification['message'], 0, 90, 'UTF-8')) ?></small><time><?= e(notification_time_label($menuNotification['created_at'])) ?></time></span></button></form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <span class="signed-in-name"><?= e($currentUser['full_name']) ?></span>
      <form action="logout.php" method="post"><input type="hidden" name="csrf_token"
          value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-primary login-link"
          type="submit">ออกจากระบบ</button></form>
      <?php else: ?>
      <a class="btn btn-primary login-link" href="login.php" data-bs-toggle="modal"
        data-bs-target="#loginModal"><?= app_icon('login') ?><span>เข้าสู่ระบบ</span></a>
      <?php endif; ?>
    </div>
  </header>
  <main id="main-content" class="main-content" tabindex="-1">
    <div class="page-heading">
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="page-description"><?= e($pages[$page]['description']) ?></p>
      </div>
      <?php if ($isDashboard): ?>
      <a class="btn btn-primary" href="index.php?page=loans"><?= app_icon('document') ?> ดูทะเบียนเงินกู้</a>
      <?php elseif (in_array($page, ['registry', 'loans', 'debt'], true)): ?>
      <button class="btn btn-outline-primary" type="button" data-export-table="recordsTable"
        data-export-name="<?= e($page) ?>.csv"><?= app_icon('download') ?> ส่งออก CSV</button>
      <?php endif; ?>
    </div>
    <?php
    if ($isDashboard) {
        require __DIR__ . '/views/dashboard.php';
    } elseif ($page === 'reports') {
        require __DIR__ . '/views/reports.php';
    } elseif ($page === 'registry') {
        require __DIR__ . '/views/registry.php';
    } elseif ($page === 'loans') {
        require __DIR__ . '/views/loans.php';
    } elseif ($page === 'users') {
        require __DIR__ . '/views/users.php';
    } elseif ($page === 'notifications') {
        require __DIR__ . '/views/notifications.php';
    } else {
        require __DIR__ . '/views/records.php';
    }
?>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
