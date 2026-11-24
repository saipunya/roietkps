<?php

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

require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/executive-dashboard.php';

$checks = 0;
function executive_dashboard_check($condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$suffix = bin2hex(random_bytes(4));
$provinceId = (int) $db->query("SELECT id FROM provinces WHERE code = '45'")->fetchColumn();
$roles = [];
foreach ($db->query("SELECT id, code FROM roles WHERE code IN ('cooperative_user', 'promotion_officer', 'provincial_director')") as $role) {
    $roles[$role['code']] = (int) $role['id'];
}
executive_dashboard_check($provinceId > 0 && count($roles) === 3, 'Required reference data was not imported.');

$db->beginTransaction();
try {
    $insertCooperative = $db->prepare("INSERT INTO cooperatives (code, name, organization_type, province_id, profile_status, profile_completed_at) VALUES (?, ?, 'cooperative', ?, 'complete', UTC_TIMESTAMP())");
    $insertCooperative->execute(['EXEC-' . $suffix, 'สหกรณ์ทดสอบภาพรวมผู้บริหาร', $provinceId]);
    $cooperativeId = (int) $db->lastInsertId();

    $insertUser = $db->prepare("INSERT INTO users (username, password_hash, full_name, role_id, data_scope, province_id, cooperative_id, status, must_change_password, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, UTC_TIMESTAMP())");
    $passwordHash = password_hash('temporary-password', PASSWORD_BCRYPT);
    $insertUser->execute(['exec-coop-' . $suffix, $passwordHash, 'ผู้ใช้สหกรณ์ทดสอบ', $roles['cooperative_user'], 'cooperative', $provinceId, $cooperativeId]);
    $cooperativeUserId = (int) $db->lastInsertId();
    $insertUser->execute(['exec-promotion-' . $suffix, $passwordHash, 'เจ้าหน้าที่ส่งเสริมทดสอบ', $roles['promotion_officer'], 'province', $provinceId, null]);
    $promotionUserId = (int) $db->lastInsertId();
    $insertUser->execute(['exec-director-' . $suffix, $passwordHash, 'ผู้บริหารทดสอบ', $roles['provincial_director'], 'province', $provinceId, null]);
    $directorUserId = (int) $db->lastInsertId();

    $insertApplication = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, assigned_officer_id, purpose, requested_amount, approved_amount, proposed_interest_rate, term_months, repayment_due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), ?)");
    $applicationIds = [];
    foreach ([
        ['submitted', 100000, null],
        ['promotion_review', 200000, null],
        ['revision_requested', 300000, null],
        ['province_review', 400000, null],
        ['approved', 500000, 450000],
    ] as $index => [$status, $requestedAmount, $approvedAmount]) {
        $applicationNo = 'EXEC-' . strtoupper($suffix) . '-' . ($index + 1);
        $insertApplication->execute([$applicationNo, $cooperativeId, $cooperativeUserId, $promotionUserId, 'คำขอทดสอบ ' . $status, $requestedAmount, $approvedAmount, $status]);
        $applicationIds[$status] = (int) $db->lastInsertId();
    }
    $insertContract = $db->prepare("INSERT INTO loan_contracts (loan_application_id, cooperative_id, contract_no, principal_amount, interest_rate, term_months, maturity_date, outstanding_principal, status, created_by) VALUES (?, ?, ?, 450000, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), 450000, 'awaiting_preparation', ?)");
    $insertContract->execute([$applicationIds['approved'], $cooperativeId, 'CT-EXEC-' . strtoupper($suffix), $directorUserId]);

    $director = ['id' => $directorUserId, 'role_code' => 'provincial_director', 'province_id' => $provinceId, 'permissions' => ['loans.view']];
    $dashboard = load_executive_dashboard($db, $director);
    $stats = array_column($dashboard['stats'], null, 'label');
    executive_dashboard_check($stats['อนุมัติแล้ว']['value'] === 1, 'Approved application count is incorrect.');
    executive_dashboard_check($stats['รอผู้บริหารพิจารณา']['value'] === 1, 'Provincial review count is incorrect.');
    executive_dashboard_check($stats['ส่งกลับแก้ไข']['value'] === 1, 'Revision count is incorrect.');
    executive_dashboard_check($stats['สัญญารอดำเนินการ']['value'] === 1, 'Pending contract count is incorrect.');
    $approvedRow = array_values(array_filter($dashboard['recent'], static function (array $row) use ($applicationIds): bool {
        return (int) $row['id'] === $applicationIds['approved'];
    }))[0] ?? null;
    executive_dashboard_check($approvedRow !== null && $approvedRow['stage']['label'] === 'อนุมัติแล้ว · รอจัดทำสัญญา', 'Contract stage was not displayed for an approved application.');
    executive_dashboard_check($dashboard['workflow'][3]['status'] === 'province_review' && $dashboard['workflow'][3]['count'] === 1, 'Workflow province-review step is incorrect.');
    try {
        load_executive_dashboard($db, ['role_code' => 'provincial_officer', 'province_id' => $provinceId, 'permissions' => ['loans.view']]);
        throw new RuntimeException('Provincial officer was incorrectly allowed into the executive page.');
    } catch (DomainException $exception) {
        executive_dashboard_check(strpos($exception->getMessage(), 'ผู้บริหาร') !== false, 'Executive access denial was unclear.');
    }

    echo 'PASS: ' . $checks . " executive dashboard checks.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
