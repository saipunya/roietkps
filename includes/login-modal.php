<?php
$modalUsername = isset($loginModalFeedback['username']) && is_string($loginModalFeedback['username'])
    ? mb_substr($loginModalFeedback['username'], 0, 100, 'UTF-8')
    : '';
$modalErrors = isset($loginModalFeedback['errors']) && is_array($loginModalFeedback['errors'])
    ? $loginModalFeedback['errors']
    : [];
$modalFormError = isset($loginModalFeedback['form_error']) && is_string($loginModalFeedback['form_error'])
    ? $loginModalFeedback['form_error']
    : '';
?>
<div class="modal fade login-modal" id="loginModal" tabindex="-1" aria-labelledby="loginModalTitle" aria-hidden="true"<?= !empty($openLoginModal) ? ' data-auto-open="true"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered login-modal-dialog">
    <div class="modal-content login-modal-content">
      <div class="modal-header login-modal-header">
        <div class="login-modal-brand"><span class="brand-mark"><?= app_icon('building') ?></span><span><strong>RoietKPS</strong><small>ระบบสารสนเทศกองทุนพัฒนาสหกรณ์</small></span></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างเข้าสู่ระบบ"></button>
      </div>
      <div class="modal-body login-modal-body">
        <div class="login-modal-heading"><p>สำหรับสมาชิก</p><h2 id="loginModalTitle">เข้าสู่ระบบ</h2><span>ใช้บัญชีที่ได้รับอนุมัติจาก Admin แล้ว</span></div>

        <?php if ($modalFormError !== ''): ?><div class="alert alert-danger auth-feedback" role="alert"><?= e($modalFormError) ?></div><?php endif; ?>
        <?php if ($loginNotice !== ''): ?><div class="alert auth-feedback auth-info" role="status"><?= e($loginNotice) ?></div><?php endif; ?>

        <form class="auth-form" action="login.php" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="login_context" value="modal">
          <div class="auth-field">
            <label class="form-label" for="modalLoginUsername">ชื่อผู้ใช้งาน</label>
            <div class="auth-input-wrap">
              <?= app_icon('user') ?>
              <input class="form-control<?= isset($modalErrors['username']) ? ' is-invalid' : '' ?>" type="text" id="modalLoginUsername" name="username" value="<?= e($modalUsername) ?>" placeholder="กรอกชื่อผู้ใช้งาน" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="100" required<?= isset($modalErrors['username']) ? ' aria-invalid="true" aria-describedby="modalUsernameError"' : '' ?>>
            </div>
            <?php if (isset($modalErrors['username'])): ?><p class="auth-field-error" id="modalUsernameError"><?= e($modalErrors['username']) ?></p><?php endif; ?>
          </div>
          <div class="auth-field">
            <label class="form-label" for="modalLoginPassword">รหัสผ่าน</label>
            <div class="auth-input-wrap password-input-wrap">
              <?= app_icon('lock') ?>
              <input class="form-control<?= isset($modalErrors['password']) ? ' is-invalid' : '' ?>" type="password" id="modalLoginPassword" name="password" placeholder="กรอกรหัสผ่าน" autocomplete="current-password" maxlength="72" required<?= isset($modalErrors['password']) ? ' aria-invalid="true" aria-describedby="modalPasswordError"' : '' ?>>
              <button class="password-toggle" type="button" data-toggle-password="modalLoginPassword" aria-label="แสดงรหัสผ่าน" aria-controls="modalLoginPassword" aria-pressed="false" hidden><?= app_icon('eye') ?></button>
            </div>
            <?php if (isset($modalErrors['password'])): ?><p class="auth-field-error" id="modalPasswordError"><?= e($modalErrors['password']) ?></p><?php endif; ?>
          </div>
          <button class="btn btn-primary auth-submit" type="submit">เข้าสู่ระบบ <?= app_icon('login') ?></button>
        </form>

        <div class="login-modal-footer"><span>ยังไม่มีบัญชี?</span><a href="register.php">สมัครสมาชิก</a></div>
      </div>
    </div>
  </div>
</div>
