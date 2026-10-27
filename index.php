<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/data.php';

$publicPages = ['dashboard', 'documents'];
$requestedPage = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard';
if (!in_array($requestedPage, $publicPages, true)) {
    $query = $_GET;
    $query['page'] = $requestedPage;
    $destination = 'dashboard.php?' . http_build_query($query);
    $statusCode = $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 303;
    header('Location: ' . $destination, true, $statusCode);
    exit;
}

$pages = [
    'dashboard' => ['title' => 'ภาพรวมการบริหารกองทุนพัฒนาสหกรณ์', 'description' => 'ข้อมูลสำคัญและข่าวสารของกองทุนพัฒนาสหกรณ์', 'icon' => 'dashboard', 'public' => true],
    'documents' => ['title' => 'คู่มือและเอกสาร', 'description' => 'ค้นหาและดาวน์โหลดเอกสารเผยแพร่ของระบบ', 'icon' => 'folder', 'public' => true],
];
$page = $requestedPage;
$pageTitle = $pages[$page]['title'];
$isDashboard = $page === 'dashboard';
$isWorkspace = false;
$workspaceUrl = 'index.php';
$hasPublicCharts = $isDashboard;
$loginModalFeedback = isset($_SESSION['login_modal_feedback']) && is_array($_SESSION['login_modal_feedback'])
    ? $_SESSION['login_modal_feedback']
    : [];
unset($_SESSION['login_modal_feedback']);
$loginNotice = isset($_SESSION['registration_complete'])
    ? 'สมัครสมาชิกสำเร็จ บัญชีของคุณกำลังรอ Admin อนุมัติ'
    : '';
unset($_SESSION['registration_complete']);

try {
    $currentUser = current_account();
    if ($currentUser && (int) $currentUser['must_change_password'] === 1) {
        header('Location: change-password.php', true, 303);
        exit;
    }

    if ($page === 'dashboard') {
        define('PUBLIC_DOCUMENTS_LIBRARY_ONLY', true);
        require_once __DIR__ . '/includes/public-documents.php';
        try {
            $latestPublicDocuments = public_documents_latest(app_db(), 10);
        } catch (Throwable $exception) {
            error_log('Latest public documents unavailable (code: ' . $exception->getCode() . ')');
            $latestPublicDocuments = [];
        }
    } else {
        define('PUBLIC_DOCUMENTS_READ_ONLY', true);
        require __DIR__ . '/includes/public-documents.php';
    }
} catch (Throwable $exception) {
    error_log('Public page unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ระบบยังไม่พร้อมใช้งาน กรุณาลองใหม่อีกครั้ง');
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
      <button class="btn icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="เปิดเมนูหลัก"><?= app_icon('menu') ?></button>
      <span class="topbar-agency-icon" aria-hidden="true"><?= app_icon('building') ?></span>
      <span class="topbar-title">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</span>
    </div>
    <div class="topbar-actions">
      <?php if ($currentUser): ?>
      <span class="signed-in-name"><?= e($currentUser['full_name']) ?></span>
      <a class="btn btn-primary login-link" href="dashboard.php"><?= app_icon('dashboard') ?><span>เข้าสู่ Dashboard</span></a>
      <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-primary login-link" type="submit">ออกจากระบบ</button></form>
      <?php else: ?>
      <a class="btn btn-primary login-link" href="login.php" data-bs-toggle="modal" data-bs-target="#loginModal"><?= app_icon('login') ?><span>เข้าสู่ระบบ</span></a>
      <?php endif; ?>
    </div>
  </header>
  <main id="main-content" class="main-content" tabindex="-1">
    <div class="page-heading">
      <div><h1><?= e($pageTitle) ?></h1><p class="page-description"><?= e($pages[$page]['description']) ?></p></div>
      <?php if ($isDashboard): ?>
        <?php if ($currentUser): ?><a class="btn btn-primary" href="dashboard.php"><?= app_icon('dashboard') ?> เข้าสู่ Dashboard</a>
        <?php else: ?><a class="btn btn-primary" href="login.php" data-bs-toggle="modal" data-bs-target="#loginModal"><?= app_icon('login') ?> เข้าสู่ระบบ</a><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($isDashboard) {
        require __DIR__ . '/views/dashboard.php';
    } else {
        require __DIR__ . '/views/documents.php';
    } ?>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
