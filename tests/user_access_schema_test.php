<?php
// Integration checks against an explicitly selected disposable test database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dsn = getenv('TEST_DB_DSN');
if ($dsn === false || strpos($dsn, 'mysql:') !== 0) {
    fwrite(STDERR, "Set TEST_DB_DSN to an imported, disposable MySQL/MariaDB database.\n");
    exit(1);
}

$db = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$databaseName = $db->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($databaseName) || strpos($databaseName, 'roietkps_test_') !== 0) {
    fwrite(STDERR, "Refusing to write: database name must start with roietkps_test_.\n");
    exit(1);
}

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function rejects(callable $operation, array $expectedCodes, $message)
{
    try {
        $operation();
    } catch (PDOException $exception) {
        check(isset($exception->errorInfo[1]) && in_array((int) $exception->errorInfo[1], $expectedCodes, true), $message . ': unexpected database error');
        return;
    }
    throw new RuntimeException($message . ': invalid data was accepted');
}

$db->beginTransaction();
try {
    check((int) $db->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 4, 'Expected four roles');
    check((int) $db->query('SELECT COUNT(*) FROM permissions')->fetchColumn() === 14, 'Expected fourteen permissions');
    check((int) $db->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() === 25, 'Expected twenty-five permission assignments');

    $suffix = bin2hex(random_bytes(4));
    $provinceInsert = $db->prepare('INSERT INTO provinces (code, name) VALUES (?, ?)');
    $provinceInsert->execute(['A' . $suffix, 'จังหวัดทดสอบ ก']);
    $provinceA = (int) $db->lastInsertId();
    $provinceInsert->execute(['B' . $suffix, 'จังหวัดทดสอบ ข']);
    $provinceB = (int) $db->lastInsertId();

    $coopInsert = $db->prepare('INSERT INTO cooperatives (code, name, province_id) VALUES (?, ?, ?)');
    $coopInsert->execute(['TEST-' . $suffix, 'สหกรณ์ทดสอบ จำกัด', $provinceA]);
    $cooperative = (int) $db->lastInsertId();

    $roles = [];
    foreach ($db->query('SELECT id, code, data_scope FROM roles') as $role) {
        $roles[$role['code']] = $role;
    }

    $passwordHash = password_hash('disposable-test-password', PASSWORD_DEFAULT);
    $insert = $db->prepare('INSERT INTO users (username, password_hash, full_name, role_id, data_scope, province_id, cooperative_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $create = function ($name, $role, $scope, $province = null, $coop = null) use ($db, $insert, $roles, $passwordHash, $suffix) {
        $insert->execute([$name . '-' . $suffix, $passwordHash, 'ผู้ใช้ทดสอบ', $roles[$role]['id'], $scope, $province, $coop]);
        return (int) $db->lastInsertId();
    };

    $admin = $create('admin', 'system_admin', 'none');
    $central = $create('central', 'central_officer', 'all');
    $provincial = $create('province', 'provincial_officer', 'province', $provinceA);
    $coopUser = $create('coop', 'cooperative_user', 'cooperative', $provinceA, $cooperative);
    check($admin > 0 && $central > 0 && $provincial > 0 && $coopUser > 0, 'Valid role scopes must be accepted');

    $accountQuery = $db->prepare('SELECT password_hash, status, must_change_password FROM users WHERE id = ?');
    $accountQuery->execute([$admin]);
    $account = $accountQuery->fetch();
    check($account['status'] === 'pending', 'New accounts must default to pending');
    check((int) $account['must_change_password'] === 1, 'New accounts must require password change');
    check(password_verify('disposable-test-password', $account['password_hash']), 'Password hash must remain verifiable');

    rejects(function () use ($create) { $create('ADMIN', 'system_admin', 'none'); }, [1062], 'Duplicate username regardless of letter case');
    rejects(function () use ($create) { $create('no-province', 'provincial_officer', 'province'); }, [1644], 'Province role requires a province');
    rejects(function () use ($create, $provinceA) { $create('no-coop', 'cooperative_user', 'cooperative', $provinceA); }, [1644], 'Cooperative role requires a cooperative');
    rejects(function () use ($create, $cooperative) { $create('no-coop-province', 'cooperative_user', 'cooperative', null, $cooperative); }, [1644], 'Cooperative role requires a province');
    rejects(function () use ($create, $provinceA) { $create('bad-role-scope', 'central_officer', 'province', $provinceA); }, [1452], 'Role and data_scope must match');
    rejects(function () use ($create, $provinceB, $cooperative) { $create('wrong-province', 'cooperative_user', 'cooperative', $provinceB, $cooperative); }, [1452], 'Cooperative must belong to selected province');
    rejects(function () use ($create, $provinceA) { $create('admin-province', 'system_admin', 'none', $provinceA); }, [1644], 'System administrator must not inherit a province scope');
    rejects(function () use ($create, $provinceA, $cooperative) { $create('province-with-coop', 'provincial_officer', 'province', $provinceA, $cooperative); }, [1644], 'Province role must not include cooperative assignment');

    rejects(function () use ($db, $provincial) {
        $db->prepare('UPDATE users SET province_id = NULL WHERE id = ?')->execute([$provincial]);
    }, [1644], 'UPDATE must enforce scope shape');
    rejects(function () use ($db, $coopUser, $provinceB) {
        $db->prepare('UPDATE users SET province_id = ? WHERE id = ?')->execute([$provinceB, $coopUser]);
    }, [1452], 'UPDATE must reject cooperative/province mismatch');
    rejects(function () use ($db, $central, $roles) {
        $db->prepare('UPDATE users SET role_id = ? WHERE id = ?')->execute([$roles['system_admin']['id'], $central]);
    }, [1452], 'UPDATE role requires matching data scope');
    rejects(function () use ($db, $admin) {
        $db->prepare('UPDATE users SET must_change_password = 2 WHERE id = ?')->execute([$admin]);
    }, [1644], 'Invalid password change flag');
    rejects(function () use ($db, $provinceA) {
        $db->prepare('DELETE FROM provinces WHERE id = ?')->execute([$provinceA]);
    }, [1451], 'Referenced province must not be deleted');

    $permissionQuery = $db->prepare('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id JOIN roles r ON r.id = rp.role_id WHERE r.code = ?');
    $permissionQuery->execute(['system_admin']);
    $adminPermissions = $permissionQuery->fetchAll(PDO::FETCH_COLUMN);
    sort($adminPermissions);
    check($adminPermissions === ['roles.manage', 'roles.view', 'users.manage', 'users.view'], 'Administrator must have only account/role permissions');
    $permissionQuery->execute(['cooperative_user']);
    $cooperativePermissions = $permissionQuery->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('loans.submit', $cooperativePermissions, true), 'Cooperative may submit a request');
    check(!in_array('users.manage', $cooperativePermissions, true) && !in_array('loans.review', $cooperativePermissions, true), 'Cooperative must not manage accounts or review requests');
    check((int) $db->query("SELECT COUNT(*) FROM permissions WHERE code IN ('loans.approve', 'payments.record', 'disbursements.record')")->fetchColumn() === 0, 'Financial approval and posting must await workflow design');

    echo 'PASS: ' . $checks . " user/access schema checks; disposable records will be rolled back.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
