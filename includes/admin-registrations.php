<?php
// Included by index.php only after require_account() and account_is_admin().
if (!isset($currentUser) || !account_is_admin($currentUser)) {
    http_response_code(403);
    exit;
}
$approvalError = '';
$approvalNotice = $_SESSION['approval_notice'] ?? '';
unset($_SESSION['approval_notice']);
$db = app_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $approvalError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $userId = filter_var(post_text('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$userId) {
                throw new DomainException('คำขอไม่ถูกต้อง');
            }
            $province = post_text('province_id');
            $cooperative = post_text('cooperative_id');
            $createOrganization = $cooperative === 'new';
            review_registration($db, (int) $currentUser['id'], (int) $userId, post_text('decision'), post_text('role_code'), $province === '' ? null : (int) $province, $createOrganization || $cooperative === '' ? null : (int) $cooperative, $createOrganization, post_text('review_note'));
            $_SESSION['approval_notice'] = post_text('decision') === 'approve' ? 'อนุมัติบัญชีแล้ว ผู้สมัครเข้าสู่ระบบได้ทันที' : 'บันทึกการไม่อนุมัติแล้ว';
            header('Location: index.php?page=users', true, 303);
            exit;
        } catch (DomainException $exception) {
            http_response_code(422);
            $approvalError = $exception->getMessage();
        }
    }
}

$userStatuses = ['pending' => 'รออนุมัติ', 'active' => 'ใช้งาน', 'suspended' => 'ไม่อนุมัติ / ระงับ'];
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) && array_key_exists($_GET['status'], $userStatuses) ? $_GET['status'] : 'pending';
$searchUsers = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '';
$where = 'u.status = ?';
$parameters = [$filterStatus];
if ($searchUsers !== '') {
    $like = '%' . strtr($searchUsers, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $where .= " AND (u.full_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!' OR q.organization LIKE ? ESCAPE '!')";
    array_push($parameters, $like, $like, $like);
}
$query = $db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN registration_requests q ON q.user_id = u.id WHERE ' . $where);
$query->execute($parameters);
$userCount = (int) $query->fetchColumn();
$userPage = isset($_GET['p']) && is_scalar($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$totalUserPages = max(1, (int) ceil($userCount / 20));
$userPage = min($userPage, $totalUserPages);
$query = $db->prepare('SELECT u.id, u.username, u.full_name, u.email, u.status, r.name AS role_name, q.organization, q.organization_type, q.requested_role, q.reviewed_at, q.review_note, a.full_name AS reviewer_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN registration_requests q ON q.user_id = u.id LEFT JOIN users a ON a.id = q.reviewed_by WHERE ' . $where . ' ORDER BY u.created_at, u.id LIMIT 20 OFFSET ' . (($userPage - 1) * 20));
$query->execute($parameters);
$accountRows = $query->fetchAll();
$reviewId = isset($_GET['review']) && is_scalar($_GET['review']) ? (int) $_GET['review'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $approvalError !== '') {
    $reviewId = (int) post_text('user_id');
}
$reviewRequest = null;
$approvalProvinces = [];
$approvalCooperatives = [];
if ($reviewId > 0) {
    $query = $db->prepare("SELECT u.id, u.username, u.full_name, u.email, q.organization, q.organization_type, q.requested_role FROM users u JOIN registration_requests q ON q.user_id = u.id WHERE u.id = ? AND u.status = 'pending' AND q.reviewed_at IS NULL");
    $query->execute([$reviewId]);
    $reviewRequest = $query->fetch();
    $approvalProvinces = $db->query('SELECT id, name FROM provinces ORDER BY name')->fetchAll();
    $approvalCooperatives = $db->query("SELECT id, name, province_id FROM cooperatives WHERE status = 'active' ORDER BY name")->fetchAll();
}
