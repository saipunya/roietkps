<?php
// First-administrator bootstrap. This script is deliberately inaccessible over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/accounts.php';
$options = getopt('', ['username:', 'name:', 'sql']);
$username = $options['username'] ?? 'admin';
$name = $options['name'] ?? 'ผู้ดูแลระบบ';
if (!is_string($username) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{3,49}\z/', $username) || !is_string($name) || mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 150) {
    fwrite(STDERR, "Invalid --username or --name.\n");
    exit(1);
}
$terminal = function_exists('stream_isatty') && stream_isatty(STDIN);
$terminalState = null;
$exitCode = 0;
try {
    if ($terminal) {
        $terminalState = trim((string) shell_exec('stty -g'));
        if ($terminalState === '') {
            throw new RuntimeException('Cannot hide password input; use a secure stdin pipe.');
        }
        shell_exec('stty -echo');
    }
    fwrite(STDERR, "Password (at least 15 characters): ");
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($terminal) {
        fwrite(STDERR, "\nConfirm password: ");
        if ($password !== rtrim((string) fgets(STDIN), "\r\n")) {
            throw new RuntimeException('Passwords do not match.');
        }
    }
    if ($error = password_error($password)) {
        throw new RuntimeException($error);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    unset($password);
    $hex = function (string $value): string { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)'; };
    $sql = "INSERT INTO users (username, password_hash, full_name, role_id, data_scope, status, must_change_password, password_changed_at)\nSELECT " . $hex($username) . ', ' . $hex($hash) . ', ' . $hex($name) . ", r.id, 'none', 'active', 0, UTC_TIMESTAMP()\nFROM roles r LEFT JOIN users existing_admin ON existing_admin.role_id = r.id\nWHERE r.code = 'system_admin' AND r.data_scope = 'none' AND r.is_active = 1 AND existing_admin.id IS NULL;\n";
    if (array_key_exists('sql', $options)) {
        echo "-- Select the application database, then run this SQL once to create its first admin.\n", $sql;
    } else {
        $db = app_db();
        if ((int) $db->query("SELECT GET_LOCK('roietkps_first_admin', 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Cannot obtain bootstrap lock.');
        }
        try {
            if ($db->exec($sql) !== 1) {
                throw new RuntimeException('An administrator already exists, or its role is unavailable.');
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('roietkps_first_admin')");
        }
        echo 'Created first administrator: ', $username, PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Admin creation failed: ' . ($exception instanceof PDOException ? 'database operation failed' : $exception->getMessage()) . "\n");
    $exitCode = 1;
} finally {
    if ($terminalState) {
        shell_exec('stty ' . escapeshellarg($terminalState));
        fwrite(STDERR, "\n");
    }
}
exit($exitCode);
