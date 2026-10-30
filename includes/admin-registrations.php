<?php
// Included by dashboard.php only after require_account() and the users.manage permission check.
if (!isset($currentUser) || !account_is_admin($currentUser)) {
    http_response_code(403);
    exit;
}
$approvalError = '';
$approvalNotice = $_SESSION['approval_notice'] ?? '';
unset($_SESSION['approval_notice']);
$createdAccountCredentials = isset($_SESSION['created_account_credentials']) && is_array($_SESSION['created_account_credentials'])
    ? $_SESSION['created_account_credentials']
    : null;
unset($_SESSION['created_account_credentials']);
$db = app_db();
$managementFailedUserId = 0;
$showCreateAccount = isset($_GET['action']) && $_GET['action'] === 'new';
$defaultAdminProvinceId = (int) $db->query("SELECT id FROM provinces WHERE code = '45' OR name = 'ร้อยเอ็ด' ORDER BY CASE WHEN code = '45' THEN 0 ELSE 1 END, id LIMIT 1")->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $approvalError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            if ($operation === 'create_account') {
                if ($defaultAdminProvinceId < 1) {
                    throw new DomainException('ไม่พบข้อมูลจังหวัดร้อยเอ็ด กรุณาตรวจสอบตารางจังหวัด');
                }
                $password = random_initial_password(8);
                $username = post_text('username');
                create_managed_account($db, (int) $currentUser['id'], [
                    'username' => $username, 'full_name' => post_text('full_name'), 'position_title' => post_text('position_title'),
                    'role_code' => post_text('role_code'), 'province_id' => $defaultAdminProvinceId, 'cooperative_id' => post_text('cooperative_id'),
                ], $password);
                $_SESSION['created_account_credentials'] = ['username' => $username, 'password' => $password];
                $_SESSION['approval_notice'] = 'เพิ่มบัญชีผู้ใช้งานแล้ว กรุณาส่งชื่อผู้ใช้และรหัสผ่านชั่วคราวให้เจ้าของบัญชี';
                unset($password);
                header('Location: dashboard.php?page=users&status=active', true, 303);
                exit;
            }
            $userId = filter_var(post_text('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$userId) {
                throw new DomainException('คำขอไม่ถูกต้อง');
            }
            if ($operation === '' || $operation === 'review_registration') {
                $province = post_text('province_id');
                $cooperative = post_text('cooperative_id');
                $createOrganization = $cooperative === 'new';
                review_registration($db, (int) $currentUser['id'], (int) $userId, post_text('decision'), post_text('role_code'), $province === '' ? null : (int) $province, $createOrganization || $cooperative === '' ? null : (int) $cooperative, $createOrganization, post_text('review_note'));
                $_SESSION['approval_notice'] = post_text('decision') === 'approve' ? 'อนุมัติบัญชีแล้ว ผู้สมัครเข้าสู่ระบบได้ทันที' : 'บันทึกการไม่อนุมัติแล้ว';
                header('Location: dashboard.php?page=users', true, 303);
                exit;
            }
            if ($operation === 'update_account') {
                update_managed_account($db, (int) $currentUser['id'], (int) $userId, [
                    'username' => post_text('username'), 'full_name' => post_text('full_name'), 'position_title' => post_text('position_title'),
                    'role_code' => post_text('role_code'), 'province_id' => post_text('province_id'), 'cooperative_id' => post_text('cooperative_id'),
                ]);
                $returnStatus = in_array(post_text('return_status'), ['active', 'suspended'], true) ? post_text('return_status') : 'active';
                $_SESSION['approval_notice'] = 'บันทึกข้อมูลและสิทธิ์ของผู้ใช้งานแล้ว';
                header('Location: dashboard.php?page=users&status=' . rawurlencode($returnStatus), true, 303);
                exit;
            }
            if ($operation === 'set_account_status') {
                $newStatus = post_text('new_status');
                set_managed_account_status($db, (int) $currentUser['id'], (int) $userId, $newStatus, post_text('status_reason'));
                $_SESSION['approval_notice'] = $newStatus === 'suspended' ? 'ระงับบัญชีและยุติ session ที่ใช้งานอยู่แล้ว' : 'เปิดใช้งานบัญชีเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=users&status=' . rawurlencode($newStatus), true, 303);
                exit;
            }
            throw new DomainException('รายการดำเนินการไม่ถูกต้อง');
        } catch (DomainException $exception) {
            http_response_code(422);
            $approvalError = $exception->getMessage();
            if (isset($operation) && $operation === 'create_account') {
                $showCreateAccount = true;
            }
            if (isset($operation) && in_array($operation, ['update_account', 'set_account_status'], true)) {
                $managementFailedUserId = isset($userId) ? (int) $userId : 0;
            }
        }
    }
}

$creationCooperatives = [];
if ($showCreateAccount) {
    $query = $db->prepare("SELECT id, name, province_id FROM cooperatives WHERE status = 'active' AND province_id = ? ORDER BY name");
    $query->execute([$defaultAdminProvinceId]);
    $creationCooperatives = $query->fetchAll();
}

$userStatuses = ['pending' => 'รออนุมัติ', 'active' => 'ใช้งาน', 'suspended' => 'ไม่อนุมัติ / ระงับ'];
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) && array_key_exists($_GET['status'], $userStatuses) ? $_GET['status'] : 'pending';
$searchUsers = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '';
$where = 'u.status = ?';
$parameters = [$filterStatus];
if ($searchUsers !== '') {
    $like = '%' . strtr($searchUsers, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $where .= " AND (u.full_name LIKE ? ESCAPE '!' OR u.position_title LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!' OR q.organization LIKE ? ESCAPE '!')";
    array_push($parameters, $like, $like, $like, $like);
}
$query = $db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN registration_requests q ON q.user_id = u.id WHERE ' . $where);
$query->execute($parameters);
$userCount = (int) $query->fetchColumn();
$userPage = isset($_GET['p']) && is_scalar($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$totalUserPages = max(1, (int) ceil($userCount / 20));
$userPage = min($userPage, $totalUserPages);
$query = $db->prepare('SELECT u.id, u.username, u.full_name, u.position_title, u.status, u.data_scope, u.province_id, u.cooperative_id, r.code AS role_code, r.name AS role_name, q.organization, q.organization_type, q.requested_role, q.reviewed_at, q.review_note, a.full_name AS reviewer_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN registration_requests q ON q.user_id = u.id LEFT JOIN users a ON a.id = q.reviewed_by WHERE ' . $where . ' ORDER BY u.created_at, u.id LIMIT 20 OFFSET ' . (($userPage - 1) * 20));
$query->execute($parameters);
$accountRows = $query->fetchAll();
$reviewId = isset($_GET['review']) && is_scalar($_GET['review']) ? (int) $_GET['review'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $approvalError !== '') {
    $reviewId = (int) post_text('user_id');
}
$reviewRequest = null;
$approvalProvinces = [];
$approvalCooperatives = [];
$defaultApprovalProvince = '';
$defaultApprovalCooperative = '';
if ($reviewId > 0) {
    $query = $db->prepare("SELECT u.id, u.username, u.full_name, q.organization, q.organization_type, q.requested_role FROM users u JOIN registration_requests q ON q.user_id = u.id WHERE u.id = ? AND u.status = 'pending' AND q.reviewed_at IS NULL");
    $query->execute([$reviewId]);
    $reviewRequest = $query->fetch();
    $approvalProvinces = $db->query('SELECT id, name FROM provinces ORDER BY name')->fetchAll();
    $approvalCooperatives = $db->query("SELECT id, name, province_id FROM cooperatives WHERE status = 'active' ORDER BY name")->fetchAll();
    if (count($approvalProvinces) === 1) {
        $defaultApprovalProvince = (string) $approvalProvinces[0]['id'];
    }
    if ($reviewRequest && $reviewRequest['requested_role'] === 'cooperative_user') {
        $defaultApprovalCooperative = 'new';
        $matchingOrganization = $db->prepare("SELECT id, province_id FROM cooperatives WHERE name = ? AND status = 'active' ORDER BY id LIMIT 1");
        $matchingOrganization->execute([$reviewRequest['organization']]);
        if ($existingOrganization = $matchingOrganization->fetch()) {
            $defaultApprovalCooperative = (string) $existingOrganization['id'];
            $defaultApprovalProvince = (string) $existingOrganization['province_id'];
        }
    }
}

$manageId = isset($_GET['edit']) && is_scalar($_GET['edit']) ? (int) $_GET['edit'] : $managementFailedUserId;
$managedAccount = null;
$managementProvinces = [];
$managementCooperatives = [];
if ($manageId > 0) {
    $query = $db->prepare("SELECT u.id, u.username, u.full_name, u.position_title, u.status, u.data_scope, u.province_id, u.cooperative_id, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status IN ('active', 'suspended')");
    $query->execute([$manageId]);
    $managedAccount = $query->fetch() ?: null;
    if ($managedAccount) {
        $managementProvinces = $db->query('SELECT id, name FROM provinces ORDER BY name')->fetchAll();
        $managementCooperatives = $db->query("SELECT id, name, province_id FROM cooperatives WHERE status = 'active' ORDER BY name")->fetchAll();
    }
}
