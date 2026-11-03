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

$storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roietkps-loan-clear-' . bin2hex(random_bytes(6));
if (!mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
    throw new RuntimeException('Unable to create disposable storage.');
}
putenv('LOAN_DOCUMENT_STORAGE_PATH=' . $storagePath);

require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/loan-documents.php';

$checks = 0;
function selective_clear_check($condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

try {
    $provinceId = (int) $db->query("SELECT id FROM provinces WHERE code = '45'")->fetchColumn();
    $roles = [];
    foreach ($db->query("SELECT id, code FROM roles WHERE code IN ('system_admin', 'promotion_officer', 'cooperative_user')") as $role) {
        $roles[$role['code']] = (int) $role['id'];
    }
    selective_clear_check($provinceId > 0 && count($roles) === 3, 'Required reference data was not imported.');

    $suffix = bin2hex(random_bytes(4));
    $insertCooperative = $db->prepare("INSERT INTO cooperatives (code, name, organization_type, province_id, profile_status, profile_completed_at) VALUES (?, ?, 'cooperative', ?, 'complete', UTC_TIMESTAMP())");
    $insertCooperative->execute(['CLEAR-' . $suffix, 'สหกรณ์สำหรับตรวจสอบการล้างข้อมูล', $provinceId]);
    $cooperativeId = (int) $db->lastInsertId();

    $passwordHash = password_hash('temporary-password', PASSWORD_BCRYPT);
    $insertUser = $db->prepare("INSERT INTO users (username, password_hash, full_name, role_id, data_scope, province_id, cooperative_id, status, must_change_password, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, UTC_TIMESTAMP())");
    $insertUser->execute(['clear-admin-' . $suffix, $passwordHash, 'ผู้ดูแลระบบตรวจสอบ', $roles['system_admin'], 'none', null, null]);
    $adminId = (int) $db->lastInsertId();
    $insertUser->execute(['clear-officer-' . $suffix, $passwordHash, 'เจ้าหน้าที่ตรวจสอบ', $roles['promotion_officer'], 'province', $provinceId, null]);
    $officerId = (int) $db->lastInsertId();
    $insertUser->execute(['clear-coop-' . $suffix, $passwordHash, 'ผู้ใช้สหกรณ์ตรวจสอบ', $roles['cooperative_user'], 'cooperative', $provinceId, $cooperativeId]);
    $cooperativeUserId = (int) $db->lastInsertId();

    $applicationNo = 'CLEAR-' . strtoupper($suffix);
    $insertApplication = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, assigned_officer_id, purpose, requested_amount, proposed_interest_rate, term_months, repayment_due_date, status, review_round, submitted_at, promotion_reviewed_at) VALUES (?, ?, ?, ?, ?, 100000, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), 'revision_requested', 2, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $insertApplication->execute([$applicationNo, $cooperativeId, $cooperativeUserId, $officerId, 'ใช้เป็นเงินทุนหมุนเวียนของสมาชิก']);
    $applicationId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO loan_guarantors (loan_application_id, full_name, guarantee_amount) VALUES (?, 'ผู้ค้ำที่ต้องเก็บไว้', 100000)")->execute([$applicationId]);

    $storedNames = ['first-' . $suffix . '.pdf', 'second-' . $suffix . '.pdf'];
    foreach ($storedNames as $storedName) {
        file_put_contents($storagePath . DIRECTORY_SEPARATOR . $storedName, 'disposable document');
    }
    $insertDocument = $db->prepare("INSERT INTO loan_documents (loan_application_id, replaces_document_id, document_type, title, original_name, stored_name, mime_type, file_size, file_hash, status, uploaded_by, reviewed_by, review_note, reviewed_at) VALUES (?, ?, 'application_form', ?, ?, ?, 'application/pdf', 19, ?, ?, ?, ?, ?, ?)");
    $insertDocument->execute([$applicationId, null, 'แบบคำขอกู้', 'first.pdf', $storedNames[0], hash('sha256', 'first'), 'needs_revision', $cooperativeUserId, $officerId, 'แก้ไขเอกสาร', gmdate('Y-m-d H:i:s')]);
    $firstDocumentId = (int) $db->lastInsertId();
    $insertDocument->execute([$applicationId, $firstDocumentId, 'แบบคำขอกู้', 'second.pdf', $storedNames[1], hash('sha256', 'second'), 'uploaded', $cooperativeUserId, null, null, null]);
    $secondDocumentId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO loan_document_reviews (loan_document_id, decision, from_status, to_status, note, reviewed_by) VALUES (?, 'needs_revision', 'uploaded', 'needs_revision', 'แก้ไขเอกสาร', ?)")->execute([$firstDocumentId, $officerId]);
    $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, 1, 'promotion', 'request_revision', 'submitted', 'revision_requested', 'แก้ไขเอกสาร', ?)")->execute([$applicationId, $officerId]);

    workflow_task_upsert($db, [
        'task_type' => 'loan_revision', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
        'cooperative_id' => $cooperativeId, 'province_id' => $provinceId, 'assigned_user_id' => $cooperativeUserId,
        'title' => 'แก้ไขคำขอกู้', 'description' => 'ข้อมูลสำหรับตรวจสอบ', 'priority' => 'urgent',
        'due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'action_url' => 'dashboard.php?page=loans&view=' . $applicationId,
        'source_key' => 'selective-clear:' . $applicationId,
    ]);
    notification_create($db, $officerId, 'loan_document', 'มีเอกสารใหม่', 'ข้อมูลสำหรับตรวจสอบ', 'warning', 'dashboard.php?page=loans&view=' . $applicationId, 'selective-document:' . $firstDocumentId, 'loan_document', $firstDocumentId);
    notification_create($db, $cooperativeUserId, 'loan_revision', 'แก้ไขคำขอกู้', 'ข้อมูลสำหรับตรวจสอบ', 'urgent', 'dashboard.php?page=loans&view=' . $applicationId, 'selective-application:' . $applicationId, 'loan_application', $applicationId);
    notification_create($db, $adminId, 'assignment', 'การแจ้งเตือนที่ต้องเก็บไว้', 'ไม่เกี่ยวกับการส่งเอกสาร', 'normal', 'dashboard.php?page=registry&view=' . $cooperativeId, 'selective-keep:' . $cooperativeId, 'cooperative', $cooperativeId);

    $baseline = [
        'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'cooperatives' => (int) $db->query('SELECT COUNT(*) FROM cooperatives')->fetchColumn(),
        'applications' => (int) $db->query('SELECT COUNT(*) FROM loan_applications')->fetchColumn(),
        'guarantors' => (int) $db->query('SELECT COUNT(*) FROM loan_guarantors')->fetchColumn(),
    ];
    $actor = ['id' => $adminId, 'role_code' => 'system_admin', 'permissions' => ['users.manage']];

    try {
        loan_document_admin_clear($db, $actor, $applicationId, 'WRONG-NUMBER', 'ล้างข้อมูลที่ใช้ตรวจสอบระบบ');
        throw new RuntimeException('Incorrect confirmation was accepted.');
    } catch (DomainException $exception) {
        selective_clear_check(strpos($exception->getMessage(), 'เลขที่คำขอ') !== false, 'Incorrect confirmation did not fail safely.');
    }
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM loan_documents')->fetchColumn() === 2, 'Failed confirmation changed document data.');

    $result = loan_document_admin_clear($db, $actor, $applicationId, $applicationNo, 'ล้างข้อมูลที่ใช้ตรวจสอบการส่งเอกสาร');
    selective_clear_check((int) $result['documents'] === 2 && (int) $result['document_reviews'] === 1, 'Unexpected cleanup summary.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM loan_documents')->fetchColumn() === 0, 'Loan documents were not cleared.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM loan_document_reviews')->fetchColumn() === 0, 'Document reviews were not cleared.');
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM loan_reviews WHERE loan_application_id = {$applicationId} AND review_stage = 'promotion'")->fetchColumn() === 0, 'Promotion reviews were not cleared.');
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM workflow_tasks WHERE entity_type = 'loan_application' AND entity_id = {$applicationId}")->fetchColumn() === 0, 'Related workflow tasks were not cleared.');
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM notifications WHERE (entity_type = 'loan_document' AND entity_id IN ({$firstDocumentId}, {$secondDocumentId})) OR (entity_type = 'loan_application' AND entity_id = {$applicationId})")->fetchColumn() === 0, 'Related notifications were not cleared.');
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM notifications WHERE entity_type = 'cooperative' AND entity_id = {$cooperativeId}")->fetchColumn() === 1, 'Unrelated notification was removed.');

    $application = $db->query("SELECT status, review_round, submitted_at, promotion_reviewed_at, purpose FROM loan_applications WHERE id = {$applicationId}")->fetch();
    selective_clear_check($application && $application['status'] === 'draft' && (int) $application['review_round'] === 1, 'Application was not returned to draft.');
    selective_clear_check($application['submitted_at'] === null && $application['promotion_reviewed_at'] === null, 'Submission timestamps were not reset.');
    selective_clear_check($application['purpose'] === 'ใช้เป็นเงินทุนหมุนเวียนของสมาชิก', 'Loan application details were changed.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $baseline['users'], 'A user was removed.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM cooperatives')->fetchColumn() === $baseline['cooperatives'], 'A cooperative was removed.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM loan_applications')->fetchColumn() === $baseline['applications'], 'A loan application was removed.');
    selective_clear_check((int) $db->query('SELECT COUNT(*) FROM loan_guarantors')->fetchColumn() === $baseline['guarantors'], 'A guarantor was removed.');
    selective_clear_check(!file_exists($storagePath . DIRECTORY_SEPARATOR . $storedNames[0]) && !file_exists($storagePath . DIRECTORY_SEPARATOR . $storedNames[1]), 'Stored files were not removed.');
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'loan_documents.admin_clear' AND entity_id = {$applicationId}")->fetchColumn() === 1, 'Cleanup audit record was not created.');

    $protectedApplicationNo = 'PROTECTED-' . strtoupper($suffix);
    $insertApplication = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, assigned_officer_id, purpose, requested_amount, proposed_interest_rate, term_months, repayment_due_date, status) VALUES (?, ?, ?, ?, 'คำขอที่เข้าสู่ระดับจังหวัด', 50000, 3.5, 12, DATE_ADD(CURRENT_DATE(), INTERVAL 12 MONTH), 'province_review')");
    $insertApplication->execute([$protectedApplicationNo, $cooperativeId, $cooperativeUserId, $officerId]);
    $protectedApplicationId = (int) $db->lastInsertId();
    $insertDocument = $db->prepare("INSERT INTO loan_documents (loan_application_id, document_type, title, original_name, stored_name, mime_type, file_size, file_hash, status, uploaded_by) VALUES (?, 'application_form', 'เอกสารที่ห้ามล้าง', 'protected.pdf', 'protected.pdf', 'application/pdf', 10, ?, 'verified', ?)");
    $insertDocument->execute([$protectedApplicationId, hash('sha256', 'protected'), $cooperativeUserId]);
    $protectedState = loan_document_admin_clear_state($db, $protectedApplicationId);
    selective_clear_check($protectedState['allowed'] === false, 'Province-review application was incorrectly marked as clearable.');
    try {
        loan_document_admin_clear($db, $actor, $protectedApplicationId, $protectedApplicationNo, 'พยายามล้างรายการที่เข้าสู่จังหวัด');
        throw new RuntimeException('Province-review application was cleared.');
    } catch (DomainException $exception) {
        selective_clear_check(strpos($exception->getMessage(), 'ระดับจังหวัด') !== false, 'Protected application did not fail with the expected rule.');
    }
    selective_clear_check((int) $db->query("SELECT COUNT(*) FROM loan_documents WHERE loan_application_id = {$protectedApplicationId}")->fetchColumn() === 1, 'Protected document was changed.');
    try {
        loan_document_admin_clear($db, ['id' => $cooperativeUserId, 'role_code' => 'cooperative_user', 'permissions' => ['documents.upload']], $protectedApplicationId, $protectedApplicationNo, 'ผู้ใช้ทั่วไปต้องล้างไม่ได้');
        throw new RuntimeException('Non-admin account was allowed to clear documents.');
    } catch (DomainException $exception) {
        selective_clear_check(strpos($exception->getMessage(), 'Admin') !== false, 'Non-admin denial was not enforced.');
    }

    echo 'PASS: ' . $checks . " selective loan-document cleanup checks.\n";
} finally {
    foreach (glob($storagePath . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($storagePath);
}
