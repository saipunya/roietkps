<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';

header('Cache-Control: no-store');

$pageTitle = 'เข้าสู่ระบบ';
$isAuthPage = true;
$username = '';
$errors = [];
$formError = '';
$notice = isset($_SESSION['registration_complete']) ? 'สมัครสมาชิกสำเร็จ บัญชีของคุณกำลังรอ Admin อนุมัติ' : '';
unset($_SESSION['registration_complete']);
$isModalRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['login_context'])
    && $_POST['login_context'] === 'modal';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: index.php?login=1', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

    if (!csrf_valid($token)) {
        http_response_code(403);
        $formError = 'ไม่สามารถส่งแบบฟอร์มได้ กรุณาลองอีกครั้ง';
    } else {
        if ($username === '') {
            $errors['username'] = 'กรุณากรอกชื่อผู้ใช้งาน';
        } elseif (strlen($username) > 400) {
            $errors['username'] = 'ชื่อผู้ใช้งานยาวเกินไป กรุณาตรวจสอบอีกครั้ง';
        }

        if ($password === '') {
            $errors['password'] = 'กรุณากรอกรหัสผ่าน';
        } elseif (strlen($password) > 512) {
            $errors['password'] = 'รหัสผ่านยาวเกินไป กรุณาตรวจสอบอีกครั้ง';
        }

        if ($errors) {
            http_response_code(422);
        } else {
            try {
                $db = app_db();
                if (!auth_limit($db, 'login', $_SERVER['REMOTE_ADDR'] ?? '', 30, 900)) {
                    http_response_code(429);
                    $formError = 'มีการเข้าสู่ระบบหลายครั้ง กรุณาลองใหม่ใน 15 นาที';
                } else {
                    $user = authenticate_account($db, $username, $password);
                    $destination = (int) $user['must_change_password'] === 1 ? 'change-password.php' : 'dashboard.php';
                    header('Location: ' . $destination, true, 303);
                    exit;
                }
            } catch (DomainException $exception) {
                $formError = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Login unavailable (code: ' . $exception->getCode() . ')');
                http_response_code(503);
                $formError = 'ระบบเข้าสู่ระบบไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
            }
        }
    }

    if (strlen($username) > 400) {
        $username = '';
    }
    unset($password);

    if ($isModalRequest) {
        $_SESSION['login_modal_feedback'] = [
            'username' => $username,
            'errors' => $errors,
            'form_error' => $formError,
        ];
        header('Location: index.php?login=1', true, 303);
        exit;
    }
}

require __DIR__ . '/header.php';
?>
<div class="auth-page">
  <header class="auth-topbar">
    <a class="back-link" href="index.php"><?= app_icon('arrow') ?><span>กลับหน้าภาพรวม</span></a>
    <span class="preview-badge"><span class="status-dot"></span>สำหรับสมาชิก</span>
  </header>
  <main class="auth-main" id="main-content" tabindex="-1">
    <section class="auth-card" aria-labelledby="loginTitle">
      <a class="auth-brand" href="index.php" aria-label="RoietKPS หน้าภาพรวม"><span class="brand-mark"><?= app_icon('building') ?></span><strong>RoietKPS</strong></a>
      <p class="auth-system-name">ระบบสารสนเทศกองทุนพัฒนาสหกรณ์</p>
      <div class="auth-heading"><h1 id="loginTitle">เข้าสู่ระบบ</h1><p>สำหรับเจ้าหน้าที่และสหกรณ์</p></div>

      <div class="auth-preview-note" id="loginPreviewNote"><?= app_icon('info') ?><span>เข้าสู่ระบบได้เมื่อบัญชีได้รับการอนุมัติจาก Admin แล้ว</span></div>

      <?php if ($formError !== ''): ?><div class="alert alert-danger auth-feedback" role="alert"><?= e($formError) ?></div><?php endif; ?>
      <?php if ($notice !== ''): ?><div class="alert auth-feedback auth-info" role="status"><?= e($notice) ?></div><?php endif; ?>

      <form class="auth-form" action="login.php" method="post" aria-describedby="loginPreviewNote">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="auth-field">
          <label class="form-label" for="loginUsername">ชื่อผู้ใช้งาน</label>
          <div class="auth-input-wrap">
            <?= app_icon('user') ?>
            <input class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>" type="text" id="loginUsername" name="username" value="<?= e($username) ?>" placeholder="กรอกชื่อผู้ใช้งาน" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="100" required<?= isset($errors['username']) ? ' aria-invalid="true" aria-describedby="usernameError"' : '' ?>>
          </div>
          <?php if (isset($errors['username'])): ?><p class="auth-field-error" id="usernameError"><?= e($errors['username']) ?></p><?php endif; ?>
        </div>
        <div class="auth-field">
          <label class="form-label" for="loginPassword">รหัสผ่าน</label>
          <div class="auth-input-wrap password-input-wrap">
            <?= app_icon('lock') ?>
            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" type="password" id="loginPassword" name="password" placeholder="กรอกรหัสผ่าน" autocomplete="current-password" maxlength="128" required<?= isset($errors['password']) ? ' aria-invalid="true" aria-describedby="passwordError"' : '' ?>>
            <button class="password-toggle" id="passwordToggle" type="button" aria-label="แสดงรหัสผ่าน" aria-controls="loginPassword" aria-pressed="false" hidden><span data-password-show><?= app_icon('eye') ?></span><span data-password-hide hidden><?= app_icon('eye-off') ?></span></button>
          </div>
          <?php if (isset($errors['password'])): ?><p class="auth-field-error" id="passwordError"><?= e($errors['password']) ?></p><?php endif; ?>
        </div>
        <button class="btn btn-primary auth-submit" type="submit">เข้าสู่ระบบ <?= app_icon('login') ?></button>
      </form>

      <div class="auth-help">
        <p class="auth-signup-link">ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a></p>
        <button class="btn auth-help-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#passwordHelp" aria-expanded="false" aria-controls="passwordHelp">ลืมรหัสผ่าน</button>
        <div class="collapse" id="passwordHelp"><div class="auth-help-content">ติดต่อผู้ดูแลระบบของหน่วยงาน เพื่อขอเปิดบัญชีหรือยืนยันตัวตนสำหรับเปลี่ยนรหัสผ่าน</div></div>
      </div>
    </section>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
