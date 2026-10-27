<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
header('Cache-Control: no-store');
$pageTitle = 'สมัครสมาชิก';
$isAuthPage = true;
$values = ['full_name' => '', 'username' => '', 'email' => '', 'organization' => '', 'organization_type' => '', 'requested_role' => 'cooperative_user'];
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = post_text($key);
    }
    $values['requested_role'] = $values['organization_type'] === 'provincial_office'
        ? 'provincial_officer'
        : 'cooperative_user';
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
        if (!array_key_exists($values['organization_type'], organization_type_labels())) {
            $errors['organization_type'] = 'กรุณาเลือกประเภทหน่วยงาน';
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
                    header('Location: index.php?login=1', true, 303);
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
<div class="auth-page registration-page">
  <main class="registration-screen" id="main-content" tabindex="-1">
    <aside class="registration-intro" aria-label="ขั้นตอนการสมัครสมาชิก">
      <a class="registration-brand" href="index.php"><span
          class="brand-mark"><?= app_icon('building') ?></span><span><strong>RoietKPS</strong><small>ระบบสารสนเทศกองทุนพัฒนาสหกรณ์</small></span></a>
      <div class="registration-intro-copy">
        <p class="registration-eyebrow">การลงทะเบียนผู้ใช้งาน</p>
        <h2>เริ่มต้นใช้งานระบบ<br>ได้อย่างมั่นใจ</h2>
        <p>สร้างบัญชีเพื่อเข้าถึงข้อมูลและบริการตามขอบเขตหน้าที่ของคุณ</p>
        <div class="registration-steps">
          <div class="registration-step"><span><?= app_icon('check') ?></span>
            <div><strong>กรอกข้อมูลให้ครบถ้วน</strong><small>ใช้ข้อมูลจริงเพื่อให้ตรวจสอบได้รวดเร็ว</small></div>
          </div>
          <div class="registration-step"><span><?= app_icon('users') ?></span>
            <div><strong>Admin ตรวจสอบสิทธิ์</strong><small>กำหนดบทบาทและพื้นที่รับผิดชอบ</small></div>
          </div>
          <div class="registration-step"><span><?= app_icon('login') ?></span>
            <div><strong>เข้าสู่ระบบหลังอนุมัติ</strong><small>เริ่มใช้งานด้วยบัญชีของคุณ</small></div>
          </div>
        </div>
      </div>
      <p class="registration-agency">สำนักงานสหกรณ์จังหวัดร้อยเอ็ด</p>
    </aside>

    <section class="registration-form-side">
      <nav class="registration-actions" aria-label="ทางลัดหน้าสมัครสมาชิก">
        <a class="back-link" href="index.php"><?= app_icon('arrow') ?> กลับหน้าหลัก</a>
        <a class="btn btn-outline-primary registration-login-link" href="login.php"><?= app_icon('login') ?>
          เข้าสู่ระบบ</a>
      </nav>
      <div class="registration-form-wrap">
        <section class="auth-card registration-card" aria-labelledby="registerTitle">
          <div class="auth-heading">
            <p class="registration-form-kicker">สร้างบัญชีใหม่</p>
            <h1 id="registerTitle">สมัครสมาชิก</h1>
            <p>ส่งข้อมูลให้ Admin ตรวจสอบและกำหนดสิทธิ์</p>
          </div>
          <div class="auth-preview-note"><?= app_icon('info') ?><span>หลังสมัคร บัญชีจะอยู่ในสถานะรออนุมัติ
              และยังเข้าสู่ระบบไม่ได้จนกว่า Admin จะอนุมัติ</span></div>
          <?php if ($formError !== ''): ?><div class="alert alert-danger auth-feedback" role="alert">
            <?= e($formError) ?></div><?php endif; ?>
          <form class="auth-form" action="register.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="registration-grid">
              <?php foreach (['full_name' => ['ชื่อ–นามสกุล', 'text', 150, 'name'], 'username' => ['ชื่อผู้ใช้งาน', 'text', 50, 'username'], 'email' => ['อีเมล', 'email', 254, 'email'], 'organization' => ['หน่วยงาน / ชื่อสหกรณ์', 'text', 200, 'organization']] as $key => $field): ?>
              <div class="auth-field"><label class="form-label"
                  for="register-<?= e($key) ?>"><?= e($field[0]) ?></label><input
                  class="form-control<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" id="register-<?= e($key) ?>"
                  name="<?= e($key) ?>" type="<?= e($field[1]) ?>" maxlength="<?= $field[2] ?>"
                  autocomplete="<?= e($field[3]) ?>" value="<?= e($values[$key]) ?>"
                  <?= $key === 'username' ? 'placeholder="กรุณาใช้ภาษาอังกฤษ"' : '' ?>
                  required<?= isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="error-' . e($key) . '"' : '' ?><?= $key === 'username' ? ' autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9][A-Za-z0-9._-]{3,49}"' : '' ?>><?php if (isset($errors[$key])): ?>
                <p class="auth-field-error" id="error-<?= e($key) ?>"><?= e($errors[$key]) ?></p><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
            <div class="auth-field"><label class="form-label" for="organizationType">ประเภทหน่วยงาน</label><select
                class="form-select<?= isset($errors['organization_type']) ? ' is-invalid' : '' ?>"
                name="organization_type" id="organizationType"
                required><option value="" <?= $values['organization_type'] === '' ? 'selected' : '' ?> disabled>โปรดเลือก</option><?php foreach (organization_type_labels() as $code => $label): ?><option
                  value="<?= e($code) ?>" <?= $values['organization_type'] === $code ? ' selected' : '' ?>>
                  <?= e($label) ?></option>
                <?php endforeach; ?></select><?php if (isset($errors['organization_type'])): ?><p
                class="auth-field-error" role="alert"><?= e($errors['organization_type']) ?></p><?php endif; ?></div>
            <div class="registration-grid">
              <?php foreach (['password' => 'รหัสผ่าน', 'password_confirmation' => 'ยืนยันรหัสผ่าน'] as $key => $label): ?>
              <div class="auth-field"><label class="form-label" for="register-<?= e($key) ?>"><?= e($label) ?></label>
                <div class="password-input-wrap auth-input-wrap plain-password"><input
                    class="form-control<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" id="register-<?= e($key) ?>"
                    type="password" name="<?= e($key) ?>" autocomplete="new-password" minlength="8" maxlength="72"
                    required aria-describedby="passwordHint<?= isset($errors[$key]) ? ' error-' . e($key) : '' ?>"
                    <?= isset($errors[$key]) ? ' aria-invalid="true"' : '' ?>><button class="password-toggle"
                    type="button" data-toggle-password="register-<?= e($key) ?>" aria-label="แสดง<?= e($label) ?>"
                    aria-controls="register-<?= e($key) ?>" aria-pressed="false" hidden><?= app_icon('eye') ?></button>
                </div><?php if (isset($errors[$key])): ?><p class="auth-field-error" id="error-<?= e($key) ?>">
                  <?= e($errors[$key]) ?></p><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <p class="field-help" id="passwordHint">ใช้รหัสผ่านอย่างน้อย 8 ตัวอักษร ชื่อผู้ใช้ใช้ภาษาอังกฤษหรือตัวเลข
              4–50 ตัว</p>
            <button class="btn btn-primary auth-submit" type="submit">ส่งคำขอสมัครสมาชิก
              <?= app_icon('arrow') ?></button>
          </form>
        </section>
      </div>
    </section>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
  integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="assets/js/app.js?v=7"></script>
</body>

</html>
