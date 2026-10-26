<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
header('Cache-Control: no-store');
$pageTitle = 'สมัครสมาชิก';
$isAuthPage = true;
$values = ['full_name' => '', 'username' => '', 'email' => '', 'organization' => '', 'requested_role' => 'cooperative_user'];
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = post_text($key);
    }
    $password = post_password();
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $formError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        foreach (['full_name' => 150, 'organization' => 200] as $field => $maximum) {
            if (!mb_check_encoding($values[$field], 'UTF-8') || mb_strlen($values[$field], 'UTF-8') < 2 || mb_strlen($values[$field], 'UTF-8') > $maximum) {
                $errors[$field] = 'กรุณากรอกข้อมูล 2–' . $maximum . ' ตัวอักษร';
            }
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{3,49}\z/', $values['username'])) {
            $errors['username'] = 'ใช้ภาษาอังกฤษ ตัวเลข . _ หรือ - จำนวน 4–50 ตัว';
        }
        if (strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'กรุณากรอกอีเมลให้ถูกต้อง';
        }
        if (!array_key_exists($values['requested_role'], request_roles())) {
            $errors['requested_role'] = 'กรุณาเลือกประเภทผู้ใช้งาน';
        }
        if ($error = password_error($password)) {
            $errors['password'] = $error;
        }
        if ($password !== post_password('password_confirmation')) {
            $errors['password_confirmation'] = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
        }
        if ($errors) {
            http_response_code(422);
        } else {
            try {
                $db = app_db();
                if (!auth_limit($db, 'register', $_SERVER['REMOTE_ADDR'] ?? '', 10, 3600)) {
                    http_response_code(429);
                    $formError = 'มีการสมัครหลายครั้ง กรุณาลองใหม่ภายหลัง';
                } else {
                    register_account($db, $values, $password);
                    $_SESSION['registration_complete'] = true;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: login.php', true, 303);
                    exit;
                }
            } catch (DomainException $exception) {
                $formError = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Registration unavailable (code: ' . $exception->getCode() . ')');
                http_response_code(503);
                $formError = 'ยังไม่สามารถสมัครได้ กรุณาติดต่อผู้ดูแลระบบหรือลองใหม่ภายหลัง';
            }
        }
    }
    unset($password);
}
// Bound reflected values even for manually crafted requests.
foreach ($values as $key => $value) {
    $values[$key] = mb_substr($value, 0, 254, 'UTF-8');
}
require __DIR__ . '/header.php';
?>
<div class="auth-page">
  <header class="auth-topbar"><a class="back-link" href="login.php"><?= app_icon('arrow') ?> กลับหน้าเข้าสู่ระบบ</a><span class="preview-badge">สมัครสมาชิกใหม่</span></header>
  <main class="auth-main" id="main-content" tabindex="-1">
    <section class="auth-card registration-card" aria-labelledby="registerTitle">
      <a class="auth-brand" href="index.php"><span class="brand-mark"><?= app_icon('building') ?></span><strong>RoietKPS</strong></a>
      <div class="auth-heading"><h1 id="registerTitle">สมัครสมาชิก</h1><p>ส่งข้อมูลให้ Admin ตรวจสอบและกำหนดสิทธิ์</p></div>
      <div class="auth-preview-note"><?= app_icon('info') ?><span>หลังสมัคร บัญชีจะอยู่ในสถานะรออนุมัติ และยังเข้าสู่ระบบไม่ได้จนกว่า Admin จะอนุมัติ</span></div>
      <?php if ($formError !== ''): ?><div class="alert alert-danger auth-feedback" role="alert"><?= e($formError) ?></div><?php endif; ?>
      <form class="auth-form" action="register.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="registration-grid">
          <?php foreach (['full_name' => ['ชื่อ–นามสกุล', 'text', 150, 'name'], 'username' => ['ชื่อผู้ใช้งาน', 'text', 50, 'username'], 'email' => ['อีเมล', 'email', 254, 'email'], 'organization' => ['หน่วยงาน / ชื่อสหกรณ์', 'text', 200, 'organization']] as $key => $field): ?>
            <div class="auth-field"><label class="form-label" for="register-<?= e($key) ?>"><?= e($field[0]) ?></label><input class="form-control<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" id="register-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($field[1]) ?>" maxlength="<?= $field[2] ?>" autocomplete="<?= e($field[3]) ?>" value="<?= e($values[$key]) ?>" required<?= isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="error-' . e($key) . '"' : '' ?><?= $key === 'username' ? ' autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9][A-Za-z0-9._-]{3,49}"' : '' ?>><?php if (isset($errors[$key])): ?><p class="auth-field-error" id="error-<?= e($key) ?>"><?= e($errors[$key]) ?></p><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <div class="auth-field"><label class="form-label" for="requestedRole">ประเภทผู้ใช้งานที่ขอ</label><select class="form-select" name="requested_role" id="requestedRole" required><?php foreach (request_roles() as $code => $name): ?><option value="<?= e($code) ?>"<?= $values['requested_role'] === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select><?php if (isset($errors['requested_role'])): ?><p class="auth-field-error" role="alert"><?= e($errors['requested_role']) ?></p><?php endif; ?></div>
        <div class="registration-grid">
          <?php foreach (['password' => 'รหัสผ่าน', 'password_confirmation' => 'ยืนยันรหัสผ่าน'] as $key => $label): ?>
          <div class="auth-field"><label class="form-label" for="register-<?= e($key) ?>"><?= e($label) ?></label><div class="password-input-wrap auth-input-wrap plain-password"><input class="form-control<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" id="register-<?= e($key) ?>" type="password" name="<?= e($key) ?>" autocomplete="new-password" minlength="15" maxlength="72" required aria-describedby="passwordHint<?= isset($errors[$key]) ? ' error-' . e($key) : '' ?>"<?= isset($errors[$key]) ? ' aria-invalid="true"' : '' ?>><button class="password-toggle" type="button" data-toggle-password="register-<?= e($key) ?>" aria-label="แสดง<?= e($label) ?>" aria-controls="register-<?= e($key) ?>" aria-pressed="false" hidden><?= app_icon('eye') ?></button></div><?php if (isset($errors[$key])): ?><p class="auth-field-error" id="error-<?= e($key) ?>"><?= e($errors[$key]) ?></p><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <p class="field-help" id="passwordHint">ใช้รหัสผ่านอย่างน้อย 15 ตัวอักษร ชื่อผู้ใช้ใช้ภาษาอังกฤษหรือตัวเลข 4–50 ตัว</p>
        <button class="btn btn-primary auth-submit" type="submit">ส่งคำขอสมัครสมาชิก <?= app_icon('arrow') ?></button>
      </form>
      <div class="auth-help"><a class="btn auth-help-toggle" href="login.php">มีบัญชีแล้ว? เข้าสู่ระบบ</a></div>
    </section>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
