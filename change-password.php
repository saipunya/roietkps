<?php
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/accounts.php';
header('Cache-Control: no-store');
try {
    $currentUser = require_account(true);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('ระบบไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง');
}
$pageTitle = 'เปลี่ยนรหัสผ่าน';
$isAuthPage = true;
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $formError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $password = post_password('new_password');
        $formError = password_error($password);
        if ($password !== post_password('password_confirmation')) {
            $formError = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
        }
        if ($formError === '') {
            try {
                $db = app_db();
                if (!auth_limit($db, 'change-password', $_SERVER['REMOTE_ADDR'] ?? '', 10, 900)) {
                    throw new DomainException('ลองเปลี่ยนรหัสผ่านหลายครั้ง กรุณารอ 15 นาที');
                }
                $query = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active'");
                $query->execute([$currentUser['id']]);
                $hash = $query->fetchColumn();
                $oldPassword = post_password('current_password');
                if (!$hash || strlen($oldPassword) > 72 || strpos($oldPassword, "\0") !== false || !password_verify($oldPassword, $hash)) {
                    throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
                }
                if (password_verify($password, $hash)) {
                    throw new DomainException('กรุณาตั้งรหัสผ่านใหม่ที่ต่างจากเดิม');
                }
                $query = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = UTC_TIMESTAMP(), failed_login_attempts = 0, locked_until = NULL, auth_version = auth_version + 1 WHERE id = ? AND status = 'active' AND password_hash = ?");
                $query->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $currentUser['id'], $hash]);
                if ($query->rowCount() !== 1) {
                    throw new DomainException('ข้อมูลบัญชีเปลี่ยนแปลง กรุณาลองใหม่');
                }
                session_regenerate_id(true);
                $_SESSION['auth_version'] = (int) $currentUser['auth_version'] + 1;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . ($currentUser['role_code'] === 'system_admin' ? 'index.php?page=users' : 'index.php'), true, 303);
                exit;
            } catch (DomainException $exception) {
                $formError = $exception->getMessage();
            } catch (Throwable $exception) {
                http_response_code(503);
                $formError = 'ยังไม่สามารถเปลี่ยนรหัสผ่านได้ กรุณาลองใหม่';
            }
        }
        unset($password, $oldPassword);
    }
}
require __DIR__ . '/header.php';
?>
<div class="auth-page"><main class="auth-main" id="main-content" tabindex="-1"><section class="auth-card"><div class="auth-heading"><h1>เปลี่ยนรหัสผ่าน</h1><p>ตั้งรหัสผ่านใหม่สำหรับบัญชีของคุณ</p></div>
<?php if ($formError): ?><div class="alert alert-danger" role="alert"><?= e($formError) ?></div><?php endif; ?>
<form class="auth-form" action="change-password.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<?php foreach (['current_password' => 'รหัสผ่านปัจจุบัน', 'new_password' => 'รหัสผ่านใหม่', 'password_confirmation' => 'ยืนยันรหัสผ่านใหม่'] as $key => $label): ?><div class="auth-field"><label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label><input class="form-control" type="password" id="<?= e($key) ?>" name="<?= e($key) ?>" maxlength="72" autocomplete="<?= $key === 'current_password' ? 'current-password' : 'new-password' ?>" required></div><?php endforeach; ?>
<p class="field-help">รหัสผ่านใหม่ต้องมีอย่างน้อย 15 ตัวอักษร</p><button class="btn btn-primary auth-submit" type="submit">บันทึกรหัสผ่าน</button></form>
<form class="auth-help" action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn auth-help-toggle" type="submit">ออกจากระบบ</button></form>
</section></main><?php require __DIR__ . '/footer.php'; ?>
