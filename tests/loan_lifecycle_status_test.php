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
require_once __DIR__ . '/../includes/loan-status.php';

$checks = 0;
function lifecycle_check($condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function lifecycle_rejects(callable $operation, string $messagePart, string $message): void
{
    try {
        $operation();
    } catch (DomainException $exception) {
        lifecycle_check(strpos($exception->getMessage(), $messagePart) !== false, $message . ': unexpected message');
        return;
    }
    throw new RuntimeException($message . ': operation was accepted');
}

$expectedLifecycle = [
    ['draft', null, 'draft'],
    ['submitted', null, 'pending_approval'],
    ['promotion_review', null, 'pending_approval'],
    ['province_review', null, 'pending_approval'],
    ['revision_requested', null, 'revision'],
    ['approved', 'awaiting_preparation', 'approved'],
    ['approved', 'completed', 'completed'],
    ['approved', 'closed', 'completed'],
    ['approved', 'cancelled', 'cancelled'],
    ['cancelled', null, 'cancelled'],
    ['rejected', null, 'rejected'],
];
foreach ($expectedLifecycle as $case) {
    lifecycle_check(loan_lifecycle_status($case[0], $case[1])['code'] === $case[2], 'Incorrect lifecycle mapping for ' . $case[0] . '/' . ($case[1] ?: 'none'));
}
lifecycle_check(notification_safe_action_url('dashboard.php?page=contracts&contract=15') === 'dashboard.php?page=contracts&contract=15', 'Contract notification link was not accepted.');
lifecycle_check(notification_safe_action_url('dashboard.php?page=loan-documents&loan=15') === 'dashboard.php?page=loan-documents&loan=15', 'Loan-document notification link was not accepted.');

$provinceId = (int) $db->query("SELECT id FROM provinces WHERE code = '45'")->fetchColumn();
$roles = [];
foreach ($db->query("SELECT id, code FROM roles WHERE code IN ('system_admin', 'provincial_officer', 'promotion_officer', 'cooperative_user')") as $role) {
    $roles[$role['code']] = (int) $role['id'];
}
lifecycle_check($provinceId > 0 && count($roles) === 4, 'Required reference data was not imported.');
lifecycle_check((int) $db->query("SELECT COUNT(*) FROM permissions WHERE code = 'loan_applications.cancel'")->fetchColumn() === 1, 'Cancellation permission is missing.');

$suffix = bin2hex(random_bytes(4));
$insertCooperative = $db->prepare("INSERT INTO cooperatives (code, name, organization_type, province_id, profile_status, profile_completed_at) VALUES (?, ?, 'cooperative', ?, 'complete', UTC_TIMESTAMP())");
$insertCooperative->execute(['LIFE-' . $suffix, 'สหกรณ์ทดสอบสถานะวงจรงาน', $provinceId]);
$cooperativeId = (int) $db->lastInsertId();

$passwordHash = password_hash('temporary-password', PASSWORD_BCRYPT);
$insertUser = $db->prepare("INSERT INTO users (username, password_hash, full_name, role_id, data_scope, province_id, cooperative_id, status, must_change_password, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, UTC_TIMESTAMP())");
$insertUser->execute(['life-admin-' . $suffix, $passwordHash, 'ผู้ดูแลทดสอบ', $roles['system_admin'], 'none', null, null]);
$adminId = (int) $db->lastInsertId();
$insertUser->execute(['life-province-' . $suffix, $passwordHash, 'เจ้าหน้าที่จังหวัดทดสอบ', $roles['provincial_officer'], 'province', $provinceId, null]);
$provincialId = (int) $db->lastInsertId();
$insertUser->execute(['life-officer-' . $suffix, $passwordHash, 'เจ้าหน้าที่ส่งเสริมทดสอบ', $roles['promotion_officer'], 'province', $provinceId, null]);
$officerId = (int) $db->lastInsertId();
$insertUser->execute(['life-coop-' . $suffix, $passwordHash, 'ผู้ใช้สหกรณ์ทดสอบ', $roles['cooperative_user'], 'cooperative', $provinceId, $cooperativeId]);
$cooperativeUserId = (int) $db->lastInsertId();

$admin = ['id' => $adminId, 'role_code' => 'system_admin', 'province_id' => null, 'cooperative_id' => null, 'permissions' => ['users.manage', 'loan_applications.cancel']];
$provincial = ['id' => $provincialId, 'role_code' => 'provincial_officer', 'province_id' => $provinceId, 'cooperative_id' => null, 'permissions' => ['loan_applications.cancel']];
$officer = ['id' => $officerId, 'role_code' => 'promotion_officer', 'province_id' => $provinceId, 'cooperative_id' => null, 'permissions' => []];
$cooperative = ['id' => $cooperativeUserId, 'role_code' => 'cooperative_user', 'province_id' => $provinceId, 'cooperative_id' => $cooperativeId, 'permissions' => ['loan_applications.cancel']];

$insertApplication = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, assigned_officer_id, purpose, requested_amount, proposed_interest_rate, term_months, repayment_due_date, status, submitted_at) VALUES (?, ?, ?, ?, ?, 100000, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), ?, UTC_TIMESTAMP())");
$applicationNo = 'LIFE-' . strtoupper($suffix);
$insertApplication->execute([$applicationNo, $cooperativeId, $cooperativeUserId, $officerId, 'เงินทุนหมุนเวียนสำหรับทดสอบสถานะ', 'submitted']);
$applicationId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO loan_guarantors (loan_application_id, full_name, guarantee_amount) VALUES (?, 'ผู้ค้ำที่ต้องคงอยู่', 100000)")->execute([$applicationId]);
workflow_task_upsert($db, [
    'task_type' => 'promotion_review', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
    'cooperative_id' => $cooperativeId, 'province_id' => $provinceId, 'assigned_user_id' => $officerId,
    'title' => 'ตรวจคำขอกู้ ' . $applicationNo, 'description' => 'ข้อมูลทดสอบ', 'priority' => 'warning',
    'due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'action_url' => 'dashboard.php?page=loans&view=' . $applicationId,
    'source_key' => 'lifecycle:' . $applicationId,
]);

lifecycle_rejects(function () use ($db, $cooperative, $applicationId) {
    loan_application_cancel($db, $cooperative, $applicationId, 'WRONG', 'ยกเลิกเพื่อทดสอบการยืนยัน');
}, 'เลขที่คำขอ', 'Incorrect confirmation must fail');
lifecycle_check($db->query("SELECT status FROM loan_applications WHERE id = {$applicationId}")->fetchColumn() === 'submitted', 'Failed confirmation changed the application.');

loan_application_cancel($db, $cooperative, $applicationId, $applicationNo, 'ยกเลิกเพื่อทดสอบกระบวนการครบถ้วน');
$cancelled = $db->query("SELECT status, cancelled_by, cancellation_reason, cancelled_at FROM loan_applications WHERE id = {$applicationId}")->fetch();
lifecycle_check($cancelled['status'] === 'cancelled' && (int) $cancelled['cancelled_by'] === $cooperativeUserId && $cancelled['cancelled_at'] !== null, 'Cancellation metadata was not stored.');
lifecycle_check($cancelled['cancellation_reason'] === 'ยกเลิกเพื่อทดสอบกระบวนการครบถ้วน', 'Cancellation reason was not stored.');
lifecycle_check((int) $db->query("SELECT COUNT(*) FROM loan_guarantors WHERE loan_application_id = {$applicationId}")->fetchColumn() === 1, 'Cancellation removed related business data.');
lifecycle_check($db->query("SELECT status FROM workflow_tasks WHERE entity_type = 'loan_application' AND entity_id = {$applicationId}")->fetchColumn() === 'cancelled', 'Open workflow task was not cancelled.');
lifecycle_check((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'loan.cancel' AND entity_id = {$applicationId}")->fetchColumn() === 1, 'Cancellation audit was not created.');
lifecycle_check((int) $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$officerId} AND entity_type = 'loan_application' AND entity_id = {$applicationId}")->fetchColumn() === 1, 'Responsible officer was not notified.');

$provinceApplicationNo = 'PROV-' . strtoupper($suffix);
$insertApplication->execute([$provinceApplicationNo, $cooperativeId, $cooperativeUserId, $officerId, 'คำขอในขั้นจังหวัด', 'province_review']);
$provinceApplicationId = (int) $db->lastInsertId();
lifecycle_check(loan_application_can_cancel($provincial, ['status' => 'province_review', 'organization_province_id' => $provinceId, 'cooperative_id' => $cooperativeId, 'contract_id' => null]), 'Provincial cancellation rule was not allowed.');
loan_application_cancel($db, $provincial, $provinceApplicationId, $provinceApplicationNo, 'ยกเลิกโดยเจ้าหน้าที่จังหวัดเพื่อทดสอบ');
lifecycle_check($db->query("SELECT status FROM loan_applications WHERE id = {$provinceApplicationId}")->fetchColumn() === 'cancelled', 'Provincial cancellation was not applied.');

$deniedApplicationNo = 'DENY-' . strtoupper($suffix);
$insertApplication->execute([$deniedApplicationNo, $cooperativeId, $cooperativeUserId, $officerId, 'คำขอที่เจ้าหน้าที่ส่งเสริมห้ามยกเลิก', 'submitted']);
$deniedApplicationId = (int) $db->lastInsertId();
lifecycle_rejects(function () use ($db, $officer, $deniedApplicationId, $deniedApplicationNo) {
    loan_application_cancel($db, $officer, $deniedApplicationId, $deniedApplicationNo, 'พยายามยกเลิกโดยไม่มีสิทธิ์');
}, 'ไม่อยู่ในสถานะ', 'Promotion officer must not cancel an application');

$approvedApplicationNo = 'APPROVED-' . strtoupper($suffix);
$insertApplication->execute([$approvedApplicationNo, $cooperativeId, $cooperativeUserId, $officerId, 'คำขออนุมัติที่มีสัญญาแล้ว', 'approved']);
$approvedApplicationId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO loan_contracts (loan_application_id, cooperative_id, principal_amount, interest_rate, term_months, maturity_date, outstanding_principal, status, created_by) VALUES (?, ?, 100000, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), 100000, 'awaiting_preparation', ?)")
    ->execute([$approvedApplicationId, $cooperativeId, $provincialId]);
lifecycle_rejects(function () use ($db, $admin, $approvedApplicationId, $approvedApplicationNo) {
    loan_application_cancel($db, $admin, $approvedApplicationId, $approvedApplicationNo, 'พยายามยกเลิกคำขอที่มีสัญญาแล้ว');
}, 'ไม่อยู่ในสถานะ', 'Application with a contract must not be cancelled');
lifecycle_check($db->query("SELECT status FROM loan_applications WHERE id = {$approvedApplicationId}")->fetchColumn() === 'approved', 'Protected approved application was changed.');

echo 'PASS: ' . $checks . " loan lifecycle and cancellation checks.\n";
