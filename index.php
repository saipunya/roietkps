<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/demo-data.php';

$pages = [
    'dashboard' => ['title' => 'ภาพรวมกองทุน', 'description' => 'ข้อมูลสำคัญและรายการที่คุณควรติดตาม', 'icon' => 'dashboard'],
    'registry' => ['title' => 'ทะเบียนสหกรณ์', 'description' => 'ค้นหาข้อมูลสหกรณ์และพื้นที่รับผิดชอบ', 'icon' => 'building'],
    'loans' => ['title' => 'ทะเบียนเงินกู้', 'description' => 'ติดตามคำขอกู้และผลการพิจารณาในที่เดียว', 'icon' => 'document'],
    'debt' => ['title' => 'ติดตามหนี้', 'description' => 'ตรวจสอบกำหนดชำระและรายการที่ต้องติดตาม', 'icon' => 'clock'],
    'reports' => ['title' => 'รายงาน', 'description' => 'เลือกชุดข้อมูลที่ต้องการตรวจสอบหรือส่งออก', 'icon' => 'chart'],
    'users' => ['title' => 'ผู้ใช้งานและสิทธิ์', 'description' => 'ตรวจคำขอสมัครสมาชิกและกำหนดสิทธิ์เข้าใช้งาน', 'icon' => 'users'],
];
$requestedPage = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard';
$page = array_key_exists($requestedPage, $pages) ? $requestedPage : 'dashboard';
$pageTitle = $pages[$page]['title'];
$isDashboard = $page === 'dashboard';

try {
    $currentUser = current_account();
    if ($currentUser && (int) $currentUser['must_change_password'] === 1) {
        header('Location: change-password.php', true, 303);
        exit;
    }
    if ($page === 'users') {
        $currentUser = require_account();
        if (!account_is_admin($currentUser)) {
            http_response_code(403);
            exit('คุณไม่มีสิทธิ์เข้าถึงการจัดการผู้ใช้งาน');
        }
        header('Cache-Control: no-store');
        require __DIR__ . '/includes/admin-registrations.php';
    }
} catch (Throwable $exception) {
    error_log('Account page unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ระบบบัญชีผู้ใช้ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
}
if ($currentUser) {
    header('Cache-Control: no-store');
}

require __DIR__ . '/header.php';
require __DIR__ . '/nav.php';
?>
<div class="app-workspace">
  <header class="topbar">
    <div class="topbar-leading">
      <button class="btn icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar"
        aria-controls="appSidebar" aria-label="เปิดเมนูหลัก"><?= app_icon('menu') ?></button>
      <span class="topbar-title">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</span>
    </div>
    <div class="topbar-actions">
      <span class="preview-badge"><span class="status-dot"></span>ตัวอย่างหน้าจอ</span>
      <?php if ($currentUser): ?>
        <span class="signed-in-name"><?= e($currentUser['full_name']) ?></span>
        <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-primary login-link" type="submit">ออกจากระบบ</button></form>
      <?php else: ?>
        <a class="btn btn-primary login-link" href="login.php"><?= app_icon('login') ?><span>เข้าสู่ระบบ</span></a>
      <?php endif; ?>
    </div>
  </header>
  <main id="main-content" class="main-content" tabindex="-1">
    <div class="page-heading">
      <div>
        <p class="eyebrow">กองทุนพัฒนาสหกรณ์ · กพส.</p>
        <h1><?= e($pageTitle) ?></h1>
        <p class="page-description"><?= e($pages[$page]['description']) ?></p>
      </div>
      <?php if ($isDashboard): ?>
      <a class="btn btn-primary" href="index.php?page=loans"><?= app_icon('document') ?> ดูทะเบียนเงินกู้</a>
      <?php elseif (in_array($page, ['registry', 'loans', 'debt'], true)): ?>
      <button class="btn btn-outline-primary" type="button" data-export-table="recordsTable"
        data-export-name="<?= e($page) ?>-demo.csv"><?= app_icon('download') ?> ส่งออก CSV</button>
      <?php endif; ?>
    </div>
    <?php if ($page !== 'users'): ?><div class="demo-notice"><?= app_icon('info') ?><span>ข้อมูลกองทุนในหน้านี้เป็นข้อมูลสมมติสำหรับทดลองหน้าจอ</span></div><?php endif; ?>
    <?php
    if ($isDashboard) {
        require __DIR__ . '/views/dashboard.php';
    } elseif ($page === 'reports') {
        require __DIR__ . '/views/reports.php';
    } elseif ($page === 'users') {
        require __DIR__ . '/views/users.php';
    } else {
        require __DIR__ . '/views/records.php';
    }
?>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
