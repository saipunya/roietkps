<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/notifications.php';

function app_db(): PDO
{
    static $connection;
    if (!$connection) {
        require __DIR__ . '/../config/db.php';
        $pdo->exec("SET time_zone = '+00:00'");
        $connection = $pdo;
    }
    return $connection;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid($token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function post_text(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

function post_password(string $key = 'password'): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function password_error(string $password): string
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 8) {
        return 'กรุณาใช้รหัสผ่านอย่างน้อย 8 ตัวอักษร';
    }
    // PHP 7.4 PASSWORD_DEFAULT uses bcrypt: reject, rather than truncate, long input.
    return strlen($password) > 72 || strpos($password, "\0") !== false ? 'รหัสผ่านยาวเกินกำหนดหรือมีอักขระที่ไม่รองรับ' : '';
}

function request_roles(): array
{
    return [
        'cooperative_user' => 'สหกรณ์ / กลุ่มเกษตรกร',
        'provincial_officer' => 'เจ้าหน้าที่จังหวัด',
    ];
}

function organization_type_labels(): array
{
    return [
        'cooperative' => 'สหกรณ์',
        'farmer_group' => 'กลุ่มเกษตรกร',
        'provincial_office' => 'สำนักงานสหกรณ์จังหวัดร้อยเอ็ด',
    ];
}

function assignable_roles(): array
{
    return [
        'cooperative_user' => 'สหกรณ์ / กลุ่มเกษตรกร',
        'promotion_officer' => 'เจ้าหน้าที่ส่งเสริม',
        'provincial_officer' => 'เจ้าหน้าที่จังหวัด',
        'central_officer' => 'เจ้าหน้าที่ส่วนกลาง',
    ];
}

function auth_limit(PDO $db, string $purpose, string $address, int $maximum, int $seconds): bool
{
    $now = time();
    $key = hash('sha256', $purpose . '|' . $address);
    $query = $db->prepare('INSERT INTO auth_rate_limits (bucket_hash, attempt_count, window_started_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempt_count = IF(window_started_at <= ?, 1, LEAST(attempt_count + 1, 65535)), window_started_at = IF(window_started_at <= ?, ?, window_started_at)');
    $query->execute([$key, $now, $now - $seconds, $now - $seconds, $now]);
    $query = $db->prepare('SELECT attempt_count FROM auth_rate_limits WHERE bucket_hash = ?');
    $query->execute([$key]);
    return (int) $query->fetchColumn() <= $maximum;
}

function register_account(PDO $db, array $values, string $password): int
{
    $db->beginTransaction();
    try {
        $role = $db->query("SELECT id FROM roles WHERE code = 'applicant' AND data_scope = 'none' AND is_active = 1")->fetchColumn();
        if (!$role || !array_key_exists($values['requested_role'], request_roles())) {
            throw new RuntimeException('Registration is unavailable.');
        }
        $query = $db->prepare("INSERT INTO users (username, password_hash, full_name, email, role_id, data_scope, status, must_change_password, password_changed_at) VALUES (?, ?, ?, ?, ?, 'none', 'pending', 0, UTC_TIMESTAMP())");
        $query->execute([$values['username'], password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $values['full_name'], $values['email'], $role]);
        $id = (int) $db->lastInsertId();
        $query = $db->prepare('INSERT INTO registration_requests (user_id, organization, organization_type, requested_role) VALUES (?, ?, ?, ?)');
        $query->execute([$id, $values['organization'], $values['organization_type'], $values['requested_role']]);
        tracking_registration_submitted($db, $id, $values['organization']);
        $db->commit();
        return $id;
    } catch (Throwable $exception) {
        $db->rollBack();
        if ($exception instanceof PDOException && isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
            throw new DomainException('ชื่อผู้ใช้งานนี้ไม่สามารถใช้ได้ กรุณาเลือกชื่ออื่น');
        }
        throw $exception;
    }
}

function authenticate_account(PDO $db, string $username, string $password): array
{
    $query = $db->prepare('SELECT u.*, r.code AS role_code, r.is_active AS role_active FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?');
    $query->execute([$username]);
    $user = $query->fetch();
    $dummyHash = '$2y$12$rP5d5Fpvkg80ZElYHMdbTe2ULdvFwrcSZvkOuRwt.OGIBflxqfu6O';
    $valid = strlen($password) <= 72 && strpos($password, "\0") === false && password_verify($password, $user ? $user['password_hash'] : $dummyHash);
    $failure = 'ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกระงับชั่วคราว';
    $locked = $user && $user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time();
    if (!$user || !$valid || $locked) {
        if ($user && !$locked) {
            $db->prepare('UPDATE users SET failed_login_attempts = IF(locked_until IS NOT NULL AND locked_until <= UTC_TIMESTAMP(), 1, LEAST(failed_login_attempts + 1, 65535)), locked_until = IF(failed_login_attempts >= 5, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), NULL) WHERE id = ?')->execute([$user['id']]);
        }
        throw new DomainException($failure);
    }
    if ($user['status'] === 'pending') {
        throw new DomainException('บัญชีของคุณกำลังรอ Admin อนุมัติ กรุณาลองเข้าสู่ระบบภายหลัง');
    }
    if ($user['status'] !== 'active' || (int) $user['role_active'] !== 1 || $user['role_code'] === 'applicant') {
        throw new DomainException($failure);
    }
    $query = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'active' AND password_hash = ? AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())");
    $query->execute([$user['id'], $user['password_hash']]);
    if ($query->rowCount() === 0) {
        $check = $db->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND password_hash = ? AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())");
        $check->execute([$user['id'], $user['password_hash']]);
        if (!$check->fetchColumn()) {
            throw new DomainException($failure);
        }
    }
    session_regenerate_id(true);
    $_SESSION = ['auth_user_id' => (int) $user['id'], 'auth_version' => (int) $user['auth_version'], 'auth_started_at' => time(), 'auth_last_seen' => time(), 'csrf_token' => bin2hex(random_bytes(32))];
    return $user;
}

function current_account(): ?array
{
    if (empty($_SESSION['auth_user_id'])) {
        return null;
    }
    if (time() - ($_SESSION['auth_last_seen'] ?? 0) > 1800 || time() - ($_SESSION['auth_started_at'] ?? 0) > 28800) {
        $_SESSION = [];
        return null;
    }
    $db = app_db();
    $query = $db->prepare("SELECT u.id, u.username, u.full_name, u.role_id, u.data_scope, u.province_id, u.cooperative_id, u.must_change_password, u.auth_version, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active' AND r.is_active = 1 AND r.code <> 'applicant'");
    $query->execute([$_SESSION['auth_user_id']]);
    $user = $query->fetch();
    if (!$user || (int) $user['auth_version'] !== ($_SESSION['auth_version'] ?? 0)) {
        $_SESSION = [];
        return null;
    }
    $query = $db->prepare('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?');
    $query->execute([$user['role_id']]);
    $user['permissions'] = $query->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['auth_last_seen'] = time();
    return $user;
}

function require_account(bool $allowPasswordChange = false): array
{
    $user = current_account();
    if (!$user) {
        header('Location: login.php', true, 303);
        exit;
    }
    if (!$allowPasswordChange && (int) $user['must_change_password'] === 1) {
        header('Location: change-password.php', true, 303);
        exit;
    }
    return $user;
}

function account_is_admin(array $user): bool
{
    return $user['role_code'] === 'system_admin' && in_array('users.manage', $user['permissions'], true);
}

function account_can(array $user, string $permission): bool
{
    return in_array($permission, $user['permissions'], true);
}

function review_registration(PDO $db, int $actorId, int $userId, string $decision, string $roleCode, ?int $province, ?int $cooperative, bool $createOrganization, string $note): void
{
    $db->beginTransaction();
    try {
        // Recheck the administrator inside the transaction; never trust POSTed role claims.
        $query = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE u.id = ? AND u.status = 'active' AND u.must_change_password = 0 AND r.is_active = 1 AND r.code = 'system_admin' AND p.code = 'users.manage' FOR UPDATE");
        $query->execute([$actorId]);
        if (!$query->fetchColumn() || $actorId === $userId) {
            throw new DomainException('คุณไม่มีสิทธิ์อนุมัติรายการนี้');
        }
        $query = $db->prepare("SELECT u.id, q.organization, q.organization_type FROM users u JOIN registration_requests q ON q.user_id = u.id WHERE u.id = ? AND u.status = 'pending' AND q.reviewed_at IS NULL FOR UPDATE");
        $query->execute([$userId]);
        $request = $query->fetch();
        if (!$request) {
            throw new DomainException('คำขอนี้ถูกดำเนินการแล้วหรือไม่พบรายการ');
        }
        if ($decision === 'approve') {
            if (!array_key_exists($roleCode, assignable_roles())) {
                throw new DomainException('กรุณาเลือกบทบาทที่อนุญาต');
            }
            $query = $db->prepare('SELECT id, data_scope FROM roles WHERE code = ? AND is_active = 1');
            $query->execute([$roleCode]);
            $role = $query->fetch();
            $expectedScope = ['central_officer' => 'all', 'provincial_officer' => 'province', 'promotion_officer' => 'province', 'cooperative_user' => 'cooperative'];
            if (!$role || $role['data_scope'] !== $expectedScope[$roleCode] || ($role['data_scope'] === 'all' && ($province !== null || $cooperative !== null)) || ($role['data_scope'] === 'province' && (!$province || $cooperative !== null)) || ($role['data_scope'] === 'cooperative' && (!$province || !$cooperative))) {
                if (!($role && $role['data_scope'] === 'cooperative' && $province && $createOrganization && $cooperative === null)) {
                    throw new DomainException('กรุณากำหนดจังหวัดและหน่วยงานให้ตรงกับบทบาท');
                }
            }
            if ($province !== null) {
                $query = $db->prepare('SELECT id FROM provinces WHERE id = ?');
                $query->execute([$province]);
                if (!$query->fetchColumn()) {
                    throw new DomainException('ไม่พบจังหวัดที่เลือก');
                }
            }
            if ($role['data_scope'] === 'cooperative') {
                if ($createOrganization) {
                    if (!in_array($request['organization_type'], ['cooperative', 'farmer_group'], true)) {
                        throw new DomainException('ประเภทหน่วยงานนี้ไม่สามารถสร้างเป็นทะเบียนสหกรณ์หรือกลุ่มเกษตรกรได้');
                    }
                    $query = $db->prepare("SELECT id FROM cooperatives WHERE name = ? AND province_id = ? AND status = 'active'");
                    $query->execute([$request['organization'], $province]);
                    if ($query->fetchColumn()) {
                        throw new DomainException('มีหน่วยงานชื่อนี้แล้ว กรุณาเลือกหน่วยงานเดิมจากรายการ');
                    }
                    $code = 'ORG-' . gmdate('Ymd') . '-' . $userId;
                    $query = $db->prepare("INSERT INTO cooperatives (code, name, organization_type, province_id, status, profile_status) VALUES (?, ?, ?, ?, 'active', 'incomplete')");
                    $query->execute([$code, $request['organization'], $request['organization_type'], $province]);
                    $cooperative = (int) $db->lastInsertId();
                } else {
                    $query = $db->prepare("SELECT id FROM cooperatives WHERE id = ? AND province_id = ? AND status = 'active'");
                    $query->execute([$cooperative, $province]);
                    if (!$query->fetchColumn()) {
                        throw new DomainException('หน่วยงานไม่อยู่ในจังหวัดที่เลือกหรือถูกระงับ');
                    }
                }
            }
            $query = $db->prepare("UPDATE users SET role_id = ?, data_scope = ?, province_id = ?, cooperative_id = ?, status = 'active' WHERE id = ?");
            $query->execute([$role['id'], $role['data_scope'], $province, $cooperative, $userId]);
        } elseif ($decision === 'reject') {
            if ($note === '') {
                throw new DomainException('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
            }
            $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$userId]);
        } else {
            throw new DomainException('รายการดำเนินการไม่ถูกต้อง');
        }
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new DomainException('หมายเหตุต้องไม่เกิน 500 ตัวอักษร');
        }
        $db->prepare('UPDATE registration_requests SET reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = ? WHERE user_id = ?')->execute([$actorId, $note === '' ? null : $note, $userId]);
        tracking_registration_reviewed($db, $userId, $actorId, $decision);
        $audit = $db->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
        $audit->execute([$actorId, $decision === 'approve' ? 'account.approve' : 'account.reject', 'user', $userId, json_encode(['role' => $roleCode, 'province_id' => $province, 'cooperative_id' => $cooperative], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}
