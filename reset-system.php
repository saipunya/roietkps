<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/system-reset.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $currentUser = require_account();
    if (!account_is_admin($currentUser)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    $db = app_db();
} catch (Throwable $exception) {
    error_log('System reset page unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ระบบไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง');
}

$pageTitle = 'ล้างข้อมูลทดสอบ';
$isAuthPage = true;
$formError = '';
$resetResult = isset($_SESSION['system_reset_result']) && is_array($_SESSION['system_reset_result'])
    ? $_SESSION['system_reset_result']
    : null;
unset($_SESSION['system_reset_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = post_password('current_password');
    try {
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new DomainException('แบบฟอร์มไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง');
        }
        if (post_text('confirmation') !== 'RESET ROIETKPS' || post_text('acknowledge') !== '1') {
            throw new DomainException('กรุณาพิมพ์ RESET ROIETKPS และยืนยันว่าคุณเข้าใจผลกระทบ');
        }
        if (!auth_limit($db, 'system-reset', $_SERVER['REMOTE_ADDR'] ?? '', 5, 900)) {
            throw new DomainException('ยืนยันรหัสผ่านไม่สำเร็จหลายครั้ง กรุณารอ 15 นาที');
        }

        $result = system_reset_execute($db, $currentUser, $password);
        session_regenerate_id(true);
        $_SESSION = [
            'auth_user_id' => (int) $currentUser['id'],
            'auth_version' => (int) $currentUser['auth_version'],
            'auth_started_at' => time(),
            'auth_last_seen' => time(),
            'csrf_token' => bin2hex(random_bytes(32)),
            'system_reset_result' => $result,
        ];
        header('Location: reset-system.php?completed=1', true, 303);
        exit;
    } catch (DomainException $exception) {
        $formError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('System reset failed (code: ' . $exception->getCode() . ')');
        http_response_code(500);
        $formError = 'ยังไม่สามารถล้างข้อมูลได้ ระบบยกเลิกรายการและไม่ได้เปลี่ยนแปลงฐานข้อมูล กรุณาลองใหม่หรือตรวจสอบฐานข้อมูล';
    } finally {
        unset($password);
    }
}

try {
    $impact = system_reset_impact($db, (int) $currentUser['id']);
} catch (Throwable $exception) {
    error_log('System reset impact unavailable (code: ' . $exception->getCode() . ')');
    http_response_code(503);
    exit('ไม่สามารถตรวจสอบข้อมูลที่จะล้างได้ กรุณาตรวจสอบโครงสร้างฐานข้อมูล');
}

$impactLabels = [
    'users' => 'ผู้ใช้งานอื่น',
    'cooperatives' => 'สหกรณ์/กลุ่มเกษตรกร',
    'loan_applications' => 'คำขอกู้',
    'loan_contracts' => 'สัญญาเงินกู้',
    'repayments' => 'รายการรับชำระ',
    'organization_documents' => 'เอกสารหน่วยงาน',
    'loan_documents' => 'เอกสารคำขอกู้',
    'public_documents' => 'เอกสารเผยแพร่',
    'workflow_tasks' => 'งานติดตาม',
    'notifications' => 'การแจ้งเตือน',
    'audit_logs' => 'ประวัติการดำเนินงาน',
];
require __DIR__ . '/header.php';
?>
<div class="auth-page reset-page">
  <header class="auth-topbar">
    <a class="back-link" href="dashboard.php"><?= app_icon('arrow') ?> กลับแดชบอร์ด</a>
    <span class="preview-badge"><span class="status-dot"></span>สำหรับ Admin</span>
  </header>
  <main class="auth-main" id="main-content" tabindex="-1">
    <section class="auth-card reset-card" aria-labelledby="resetTitle">
      <div class="reset-heading-icon" aria-hidden="true"><?= app_icon('alert') ?></div>
      <div class="auth-heading">
        <p class="registration-form-kicker">เครื่องมือดูแลระบบ</p>
        <h1 id="resetTitle">ล้างข้อมูลทดสอบ</h1>
        <p>ล้างข้อมูลการใช้งานเพื่อเริ่มระบบใหม่ โดยคงบัญชี Admin ปัจจุบันและค่าระบบไว้</p>
      </div>

      <?php if ($resetResult): ?>
      <div class="alert alert-success auth-feedback" role="status">
        <strong>ล้างข้อมูลทดสอบเรียบร้อยแล้ว</strong><br>
        ลบไฟล์ที่จัดเก็บ <?= number_format((int) ($resetResult['files_removed'] ?? 0)) ?> ไฟล์
      </div>
      <?php if (!empty($resetResult['files_failed'])): ?><div class="alert alert-warning" role="alert">มีไฟล์ตกค้าง <?= number_format((int) $resetResult['files_failed']) ?> ไฟล์ กรุณาตรวจสอบสิทธิ์โฟลเดอร์จัดเก็บ</div><?php endif; ?>
      <?php endif; ?>
      <?php if ($formError !== ''): ?><div class="alert alert-danger auth-feedback" role="alert"><?= e($formError) ?></div><?php endif; ?>

      <div class="reset-preserved" role="note">
        <?= app_icon('lock') ?>
        <div><strong>ข้อมูลที่จะเก็บไว้</strong><p>บัญชี <?= e($currentUser['username']) ?> และรหัสผ่านเดิม · บทบาทและสิทธิ์ · จังหวัด · กฎการแจ้งเตือน</p></div>
      </div>

      <div class="reset-impact">
        <h2>ข้อมูลที่จะถูกลบ</h2>
        <div class="reset-impact-grid">
          <?php foreach ($impactLabels as $key => $label): ?>
          <div><span><?= e($label) ?></span><strong><?= number_format((int) ($impact[$key] ?? 0)) ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="alert alert-danger reset-warning" role="alert"><strong>คำสั่งนี้ย้อนกลับไม่ได้</strong> ข้อมูลในฐานข้อมูลและไฟล์ที่อัปโหลดจะถูกลบ โปรดตรวจสอบจำนวนรายการด้านบนก่อนดำเนินการ</div>

      <form class="auth-form" action="reset-system.php" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="auth-field">
          <label class="form-label" for="resetConfirmation">พิมพ์ <strong>RESET ROIETKPS</strong> เพื่อยืนยัน</label>
          <input class="form-control" type="text" id="resetConfirmation" name="confirmation" maxlength="14" value="<?= e(post_text('confirmation')) ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
        </div>
        <div class="auth-field">
          <label class="form-label" for="resetPassword">รหัสผ่าน Admin ปัจจุบัน</label>
          <input class="form-control" type="password" id="resetPassword" name="current_password" maxlength="72" autocomplete="current-password" required>
        </div>
        <div class="form-check reset-acknowledge">
          <input class="form-check-input" type="checkbox" value="1" id="resetAcknowledge" name="acknowledge" required>
          <label class="form-check-label" for="resetAcknowledge">ฉันตรวจสอบแล้วและยืนยันว่าข้อมูลทั้งหมดเป็นข้อมูลทดสอบ</label>
        </div>
        <button class="btn btn-danger auth-submit" type="submit" onclick="return window.confirm('ยืนยันล้างข้อมูลทดสอบทั้งหมดหรือไม่?')"><?= app_icon('alert') ?> ล้างข้อมูลทดสอบทั้งหมด</button>
      </form>
    </section>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
