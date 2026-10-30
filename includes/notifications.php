<?php

/**
 * In-app notifications and workflow tasks.
 * All timestamps are stored in UTC, consistently with the account workflow.
 */

function notification_schema_ready(PDO $db): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $query = $db->query("SHOW TABLES LIKE 'notifications'");
        $ready = (bool) $query->fetchColumn();
    } catch (Throwable $exception) {
        $ready = false;
    }
    return $ready;
}

function notification_safe_action_url(string $url): string
{
    if (strlen($url) > 500 || preg_match('/[\r\n]/', $url)) {
        return 'dashboard.php';
    }
    if (!preg_match('/\A(?:index|dashboard)\.php\?page=(?:dashboard|registry|loans|debt|reports|documents|users|notifications)(?:&[A-Za-z0-9_.=%-]+)*\z/', $url)) {
        return 'dashboard.php';
    }
    return preg_replace('/\Aindex\.php/', 'dashboard.php', $url) ?: 'dashboard.php';
}

function notification_rule_days(PDO $db, string $code, int $fallback): int
{
    if (!notification_schema_ready($db)) {
        return $fallback;
    }
    $query = $db->prepare('SELECT days_value FROM notification_rules WHERE code = ? AND is_active = 1');
    $query->execute([$code]);
    $value = $query->fetchColumn();
    return $value === false ? $fallback : max(0, (int) $value);
}

function notification_due_at(PDO $db, string $ruleCode, int $fallbackDays): string
{
    return gmdate('Y-m-d H:i:s', time() + notification_rule_days($db, $ruleCode, $fallbackDays) * 86400);
}

function notification_active_user_ids(PDO $db, string $roleCode, ?int $provinceId = null, ?int $cooperativeId = null): array
{
    $sql = "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND r.is_active = 1 AND r.code = ?";
    $parameters = [$roleCode];
    if ($provinceId !== null) {
        $sql .= ' AND u.province_id = ?';
        $parameters[] = $provinceId;
    }
    if ($cooperativeId !== null) {
        $sql .= ' AND u.cooperative_id = ?';
        $parameters[] = $cooperativeId;
    }
    $query = $db->prepare($sql . ' ORDER BY u.id');
    $query->execute($parameters);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function notification_provincial_user_ids(PDO $db, int $provinceId): array
{
    return array_values(array_unique(array_merge(
        notification_active_user_ids($db, 'provincial_officer', $provinceId),
        notification_active_user_ids($db, 'provincial_director', $provinceId)
    )));
}

function notification_create(
    PDO $db,
    int $userId,
    string $category,
    string $title,
    string $message,
    string $priority,
    string $actionUrl,
    string $dedupSource,
    ?string $entityType = null,
    ?int $entityId = null
): bool {
    if (!notification_schema_ready($db) || $userId < 1) {
        return false;
    }
    $priority = in_array($priority, ['normal', 'warning', 'urgent'], true) ? $priority : 'normal';
    $query = $db->prepare('INSERT IGNORE INTO notifications (user_id, category, entity_type, entity_id, title, message, priority, action_url, dedup_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $query->execute([
        $userId,
        mb_substr($category, 0, 80, 'UTF-8'),
        $entityType === null ? null : mb_substr($entityType, 0, 80, 'UTF-8'),
        $entityId,
        mb_substr($title, 0, 200, 'UTF-8'),
        mb_substr($message, 0, 1000, 'UTF-8'),
        $priority,
        notification_safe_action_url($actionUrl),
        hash('sha256', $dedupSource),
    ]);
    if ($query->rowCount() === 0) {
        return false;
    }
    $notificationId = (int) $db->lastInsertId();
    $delivery = $db->prepare("INSERT INTO notification_deliveries (notification_id, channel, status, attempt_count, last_attempt_at, delivered_at) VALUES (?, 'in_app', 'sent', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $delivery->execute([$notificationId]);
    return true;
}

function notification_create_for_users(
    PDO $db,
    array $userIds,
    string $category,
    string $title,
    string $message,
    string $priority,
    string $actionUrl,
    string $dedupSource,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        notification_create($db, $userId, $category, $title, $message, $priority, $actionUrl, $dedupSource . ':user:' . $userId, $entityType, $entityId);
    }
}

function workflow_task_upsert(PDO $db, array $task): int
{
    if (!notification_schema_ready($db)) {
        return 0;
    }
    $sourceKey = hash('sha256', (string) $task['source_key']);
    $priorityValue = $task['priority'] ?? 'normal';
    $priority = in_array($priorityValue, ['normal', 'warning', 'urgent'], true) ? $priorityValue : 'normal';
    $query = $db->prepare("INSERT INTO workflow_tasks (task_type, entity_type, entity_id, cooperative_id, province_id, assigned_user_id, title, description, priority, status, due_at, action_url, source_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), priority = IF(status IN ('completed', 'cancelled'), priority, VALUES(priority)), due_at = IF(status IN ('completed', 'cancelled'), due_at, VALUES(due_at)), action_url = VALUES(action_url)");
    $query->execute([
        $task['task_type'],
        $task['entity_type'],
        (int) $task['entity_id'],
        $task['cooperative_id'] ?? null,
        $task['province_id'] ?? null,
        (int) $task['assigned_user_id'],
        mb_substr((string) $task['title'], 0, 200, 'UTF-8'),
        isset($task['description']) ? mb_substr((string) $task['description'], 0, 500, 'UTF-8') : null,
        $priority,
        $task['due_at'] ?? null,
        notification_safe_action_url((string) $task['action_url']),
        $sourceKey,
    ]);
    $lookup = $db->prepare('SELECT id FROM workflow_tasks WHERE source_key = ?');
    $lookup->execute([$sourceKey]);
    return (int) $lookup->fetchColumn();
}

function workflow_tasks_for_users(PDO $db, array $userIds, array $task): void
{
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        $userTask = $task;
        $userTask['assigned_user_id'] = $userId;
        $userTask['source_key'] = $task['source_key'] . ':user:' . $userId;
        workflow_task_upsert($db, $userTask);
    }
}

function workflow_complete_tasks(PDO $db, string $taskType, string $entityType, int $entityId, int $actorId): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $query = $db->prepare("UPDATE workflow_tasks SET status = 'completed', completed_at = UTC_TIMESTAMP(), completed_by = ? WHERE task_type = ? AND entity_type = ? AND entity_id = ? AND status IN ('open', 'in_progress')");
    $query->execute([$actorId, $taskType, $entityType, $entityId]);
}

function tracking_loan_context(PDO $db, int $applicationId): ?array
{
    $query = $db->prepare('SELECT a.id, a.application_no, a.cooperative_id, a.assigned_officer_id, a.review_round, c.name AS organization_name, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ?');
    $query->execute([$applicationId]);
    $row = $query->fetch();
    return $row ?: null;
}

function tracking_registration_submitted(PDO $db, int $userId, string $organization): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $admins = notification_active_user_ids($db, 'system_admin');
    $dueAt = notification_due_at($db, 'registration_approval_sla', 2);
    workflow_tasks_for_users($db, $admins, [
        'task_type' => 'registration_approval', 'entity_type' => 'user', 'entity_id' => $userId,
        'title' => 'ตรวจคำขอสมัครสมาชิก', 'description' => $organization,
        'priority' => 'warning', 'due_at' => $dueAt,
        'action_url' => 'dashboard.php?page=users&review=' . $userId,
        'source_key' => 'registration:' . $userId,
    ]);
    notification_create_for_users($db, $admins, 'registration', 'มีคำขอสมัครสมาชิกใหม่', $organization . ' ส่งคำขอสมัครสมาชิกเข้าระบบ', 'warning', 'dashboard.php?page=users&review=' . $userId, 'registration:new:' . $userId, 'user', $userId);
}

function tracking_registration_reviewed(PDO $db, int $userId, int $actorId, string $decision): void
{
    workflow_complete_tasks($db, 'registration_approval', 'user', $userId, $actorId);
    $approved = $decision === 'approve';
    notification_create($db, $userId, 'registration', $approved ? 'บัญชีได้รับการอนุมัติแล้ว' : 'คำขอสมัครสมาชิกไม่ได้รับการอนุมัติ', $approved ? 'คุณสามารถเข้าสู่ระบบและเริ่มใช้งานตามสิทธิ์ที่ได้รับ' : 'กรุณาติดต่อผู้ดูแลระบบหากต้องการข้อมูลเพิ่มเติม', $approved ? 'normal' : 'urgent', 'dashboard.php?page=dashboard', 'registration:review:' . $userId . ':' . $decision, 'user', $userId);
}

function tracking_profile_completed(PDO $db, int $cooperativeId, int $actorId): void
{
    workflow_complete_tasks($db, 'profile_complete', 'cooperative', $cooperativeId, $actorId);
}

function tracking_officer_assigned(PDO $db, int $cooperativeId, int $officerId, int $actorId): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $query = $db->prepare('SELECT c.name, c.province_id FROM cooperatives c WHERE c.id = ?');
    $query->execute([$cooperativeId]);
    $organization = $query->fetch();
    if (!$organization) {
        return;
    }
    workflow_complete_tasks($db, 'officer_assignment', 'cooperative', $cooperativeId, $actorId);
    notification_create($db, $officerId, 'assignment', 'ได้รับมอบหมายหน่วยงานใหม่', 'คุณได้รับมอบหมายให้รับผิดชอบ ' . $organization['name'], 'normal', 'dashboard.php?page=registry&view=' . $cooperativeId, 'assignment:' . $cooperativeId . ':officer:' . $officerId . ':' . gmdate('Y-m-d'), 'cooperative', $cooperativeId);

    $loans = $db->prepare("SELECT id, application_no, review_round FROM loan_applications WHERE cooperative_id = ? AND assigned_officer_id = ? AND status IN ('submitted', 'promotion_review')");
    $loans->execute([$cooperativeId, $officerId]);
    foreach ($loans->fetchAll() as $loan) {
        workflow_task_upsert($db, [
            'task_type' => 'promotion_review', 'entity_type' => 'loan_application', 'entity_id' => (int) $loan['id'],
            'cooperative_id' => $cooperativeId, 'province_id' => (int) $organization['province_id'], 'assigned_user_id' => $officerId,
            'title' => 'ตรวจคำขอกู้ ' . $loan['application_no'], 'description' => $organization['name'], 'priority' => 'warning',
            'due_at' => notification_due_at($db, 'promotion_review_sla', 3), 'action_url' => 'dashboard.php?page=loans&view=' . (int) $loan['id'],
            'source_key' => 'loan:' . (int) $loan['id'] . ':promotion:round:' . (int) $loan['review_round'] . ':user:' . $officerId,
        ]);
    }
}

function tracking_loan_submitted(PDO $db, int $applicationId, int $actorId): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $loan = tracking_loan_context($db, $applicationId);
    if (!$loan) {
        return;
    }
    workflow_complete_tasks($db, 'loan_revision', 'loan_application', $applicationId, $actorId);
    if ($loan['assigned_officer_id']) {
        $users = [(int) $loan['assigned_officer_id']];
        $taskType = 'promotion_review';
        $taskEntityType = 'loan_application';
        $taskEntityId = $applicationId;
        $title = 'ตรวจคำขอกู้ ' . $loan['application_no'];
        $message = $loan['organization_name'] . ' ส่งคำขอกู้ให้ตรวจสอบแล้ว';
        $rule = 'promotion_review_sla';
        $days = 3;
        $source = 'loan:' . $applicationId . ':promotion:round:' . (int) $loan['review_round'];
    } else {
        $users = notification_provincial_user_ids($db, (int) $loan['province_id']);
        $taskType = 'officer_assignment';
        $taskEntityType = 'cooperative';
        $taskEntityId = (int) $loan['cooperative_id'];
        $title = 'มอบหมายเจ้าหน้าที่ให้คำขอกู้ ' . $loan['application_no'];
        $message = $loan['organization_name'] . ' ส่งคำขอกู้แล้ว แต่ยังไม่มีเจ้าหน้าที่ส่งเสริมรับผิดชอบ';
        $rule = 'officer_assignment_sla';
        $days = 2;
        $source = 'cooperative:' . (int) $loan['cooperative_id'] . ':assignment';
    }
    workflow_tasks_for_users($db, $users, [
        'task_type' => $taskType, 'entity_type' => $taskEntityType, 'entity_id' => $taskEntityId,
        'cooperative_id' => (int) $loan['cooperative_id'], 'province_id' => (int) $loan['province_id'],
        'title' => $title, 'description' => $loan['organization_name'], 'priority' => 'warning',
        'due_at' => notification_due_at($db, $rule, $days), 'action_url' => 'dashboard.php?page=loans&view=' . $applicationId,
        'source_key' => $source,
    ]);
    notification_create_for_users($db, $users, 'loan', $title, $message, 'warning', 'dashboard.php?page=loans&view=' . $applicationId, $source . ':notice', 'loan_application', $applicationId);
}

function tracking_promotion_reviewed(PDO $db, int $applicationId, int $actorId, string $decision, string $comment, ?string $revisionActionUrl = null): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $loan = tracking_loan_context($db, $applicationId);
    if (!$loan) {
        return;
    }
    workflow_complete_tasks($db, 'promotion_review', 'loan_application', $applicationId, $actorId);
    $shortComment = mb_substr($comment, 0, 180, 'UTF-8');
    if ($decision === 'request_revision') {
        $actionUrl = notification_safe_action_url($revisionActionUrl ?: 'dashboard.php?page=loans&edit=' . $applicationId);
        $users = notification_active_user_ids($db, 'cooperative_user', null, (int) $loan['cooperative_id']);
        $source = 'loan:' . $applicationId . ':revision:round:' . (int) $loan['review_round'];
        workflow_tasks_for_users($db, $users, [
            'task_type' => 'loan_revision', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
            'cooperative_id' => (int) $loan['cooperative_id'], 'province_id' => (int) $loan['province_id'],
            'title' => 'แก้ไขคำขอกู้ ' . $loan['application_no'], 'description' => $shortComment, 'priority' => 'urgent',
            'due_at' => notification_due_at($db, 'revision_sla', 5), 'action_url' => $actionUrl,
            'source_key' => $source,
        ]);
        notification_create_for_users($db, $users, 'loan_revision', 'คำขอกู้ถูกส่งกลับให้แก้ไข', $shortComment, 'urgent', $actionUrl, $source . ':notice', 'loan_application', $applicationId);
        return;
    }
    $users = notification_provincial_user_ids($db, (int) $loan['province_id']);
    $source = 'loan:' . $applicationId . ':province:round:' . (int) $loan['review_round'];
    workflow_tasks_for_users($db, $users, [
        'task_type' => 'province_review', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
        'cooperative_id' => (int) $loan['cooperative_id'], 'province_id' => (int) $loan['province_id'],
        'title' => 'พิจารณาคำขอกู้ ' . $loan['application_no'], 'description' => $loan['organization_name'], 'priority' => 'warning',
        'due_at' => notification_due_at($db, 'province_review_sla', 5), 'action_url' => 'dashboard.php?page=loans&view=' . $applicationId,
        'source_key' => $source,
    ]);
    notification_create_for_users($db, $users, 'loan', 'มีคำขอรอพิจารณาระดับจังหวัด', $loan['organization_name'] . ' ผ่านการตรวจจากเจ้าหน้าที่ส่งเสริมแล้ว', 'warning', 'dashboard.php?page=loans&view=' . $applicationId, $source . ':notice', 'loan_application', $applicationId);
}

function tracking_province_reviewed(PDO $db, int $applicationId, int $actorId, string $decision, string $comment): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $loan = tracking_loan_context($db, $applicationId);
    if (!$loan) {
        return;
    }
    workflow_complete_tasks($db, 'province_review', 'loan_application', $applicationId, $actorId);
    $cooperativeUsers = notification_active_user_ids($db, 'cooperative_user', null, (int) $loan['cooperative_id']);
    $officerUsers = $loan['assigned_officer_id'] ? [(int) $loan['assigned_officer_id']] : [];
    $shortComment = mb_substr($comment, 0, 180, 'UTF-8');
    if ($decision === 'request_revision') {
        $source = 'loan:' . $applicationId . ':revision:round:' . (int) $loan['review_round'];
        workflow_tasks_for_users($db, $cooperativeUsers, [
            'task_type' => 'loan_revision', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
            'cooperative_id' => (int) $loan['cooperative_id'], 'province_id' => (int) $loan['province_id'],
            'title' => 'แก้ไขคำขอกู้ ' . $loan['application_no'], 'description' => $shortComment, 'priority' => 'urgent',
            'due_at' => notification_due_at($db, 'revision_sla', 5), 'action_url' => 'dashboard.php?page=loans&edit=' . $applicationId,
            'source_key' => $source,
        ]);
        notification_create_for_users($db, $cooperativeUsers, 'loan_revision', 'จังหวัดส่งคำขอกู้กลับให้แก้ไข', $shortComment, 'urgent', 'dashboard.php?page=loans&edit=' . $applicationId, $source . ':notice', 'loan_application', $applicationId);
        notification_create_for_users($db, $officerUsers, 'loan_revision', 'จังหวัดส่งคำขอกู้กลับให้หน่วยงานแก้ไข', $loan['organization_name'] . ' ต้องแก้ไขข้อมูลเพิ่มเติม', 'warning', 'dashboard.php?page=loans&view=' . $applicationId, $source . ':officer-notice', 'loan_application', $applicationId);
        return;
    }
    if ($decision === 'approve') {
        $message = 'คำขอกู้ ' . $loan['application_no'] . ' ได้รับอนุมัติและเข้าสู่ขั้นตอนจัดทำสัญญา';
        notification_create_for_users($db, array_merge($cooperativeUsers, $officerUsers), 'loan_approved', 'คำขอกู้ได้รับอนุมัติแล้ว', $message, 'normal', 'dashboard.php?page=loans&view=' . $applicationId, 'loan:' . $applicationId . ':approved', 'loan_application', $applicationId);
        workflow_task_upsert($db, [
            'task_type' => 'contract_preparation', 'entity_type' => 'loan_application', 'entity_id' => $applicationId,
            'cooperative_id' => (int) $loan['cooperative_id'], 'province_id' => (int) $loan['province_id'], 'assigned_user_id' => $actorId,
            'title' => 'จัดทำสัญญา ' . $loan['application_no'], 'description' => $loan['organization_name'], 'priority' => 'warning',
            'due_at' => notification_due_at($db, 'contract_preparation_sla', 7), 'action_url' => 'dashboard.php?page=contracts',
            'source_key' => 'loan:' . $applicationId . ':contract:user:' . $actorId,
        ]);
        notification_create($db, $actorId, 'contract', 'มีสัญญารอจัดทำ', $loan['organization_name'] . ' ได้รับอนุมัติคำขอกู้แล้ว', 'warning', 'dashboard.php?page=contracts', 'loan:' . $applicationId . ':contract-notice:user:' . $actorId, 'loan_application', $applicationId);
        return;
    }
    notification_create_for_users($db, array_merge($cooperativeUsers, $officerUsers), 'loan_rejected', 'คำขอกู้ไม่ได้รับการอนุมัติ', $shortComment, 'urgent', 'dashboard.php?page=loans&view=' . $applicationId, 'loan:' . $applicationId . ':rejected', 'loan_application', $applicationId);
}

function tracking_sync_user_tasks(PDO $db, array $user): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    $userId = (int) $user['id'];
    $role = $user['role_code'];
    if ($role === 'system_admin') {
        $query = $db->query("SELECT u.id, u.created_at, q.organization FROM users u JOIN registration_requests q ON q.user_id = u.id WHERE u.status = 'pending' AND q.reviewed_at IS NULL");
        foreach ($query->fetchAll() as $row) {
            workflow_task_upsert($db, [
                'task_type' => 'registration_approval', 'entity_type' => 'user', 'entity_id' => (int) $row['id'], 'assigned_user_id' => $userId,
                'title' => 'ตรวจคำขอสมัครสมาชิก', 'description' => $row['organization'], 'priority' => 'warning',
                'due_at' => gmdate('Y-m-d H:i:s', strtotime($row['created_at'] . ' UTC') + notification_rule_days($db, 'registration_approval_sla', 2) * 86400),
                'action_url' => 'dashboard.php?page=users&review=' . (int) $row['id'], 'source_key' => 'registration:' . (int) $row['id'] . ':user:' . $userId,
            ]);
        }
        return;
    }
    if ($role === 'cooperative_user') {
        $cooperativeId = (int) $user['cooperative_id'];
        $profile = $db->prepare("SELECT id, name FROM cooperatives WHERE id = ? AND status = 'active' AND profile_status = 'incomplete'");
        $profile->execute([$cooperativeId]);
        if ($organization = $profile->fetch()) {
            workflow_task_upsert($db, [
                'task_type' => 'profile_complete', 'entity_type' => 'cooperative', 'entity_id' => $cooperativeId, 'cooperative_id' => $cooperativeId,
                'province_id' => (int) $user['province_id'], 'assigned_user_id' => $userId, 'title' => 'กรอกข้อมูลหน่วยงานให้ครบ',
                'description' => $organization['name'], 'priority' => 'warning', 'due_at' => null,
                'action_url' => 'dashboard.php?page=registry', 'source_key' => 'cooperative:' . $cooperativeId . ':profile:user:' . $userId,
            ]);
        }
        $query = $db->prepare("SELECT a.id, a.application_no, a.review_round, a.updated_at, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.cooperative_id = ? AND a.status = 'revision_requested'");
        $query->execute([$cooperativeId]);
        foreach ($query->fetchAll() as $loan) {
            workflow_task_upsert($db, [
                'task_type' => 'loan_revision', 'entity_type' => 'loan_application', 'entity_id' => (int) $loan['id'], 'cooperative_id' => $cooperativeId,
                'province_id' => (int) $loan['province_id'], 'assigned_user_id' => $userId, 'title' => 'แก้ไขคำขอกู้ ' . $loan['application_no'],
                'description' => 'ตรวจสอบความเห็นล่าสุดและส่งข้อมูลกลับ', 'priority' => 'urgent',
                'due_at' => gmdate('Y-m-d H:i:s', strtotime($loan['updated_at'] . ' UTC') + notification_rule_days($db, 'revision_sla', 5) * 86400),
                'action_url' => 'dashboard.php?page=loans&edit=' . (int) $loan['id'], 'source_key' => 'loan:' . (int) $loan['id'] . ':revision:round:' . (int) $loan['review_round'] . ':user:' . $userId,
            ]);
        }
        return;
    }
    if ($role === 'promotion_officer') {
        $query = $db->prepare("SELECT a.id, a.application_no, a.review_round, a.submitted_at, a.cooperative_id, c.name, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.assigned_officer_id = ? AND a.status IN ('submitted', 'promotion_review')");
        $query->execute([$userId]);
        foreach ($query->fetchAll() as $loan) {
            workflow_task_upsert($db, [
                'task_type' => 'promotion_review', 'entity_type' => 'loan_application', 'entity_id' => (int) $loan['id'], 'cooperative_id' => (int) $loan['cooperative_id'],
                'province_id' => (int) $loan['province_id'], 'assigned_user_id' => $userId, 'title' => 'ตรวจคำขอกู้ ' . $loan['application_no'],
                'description' => $loan['name'], 'priority' => 'warning',
                'due_at' => gmdate('Y-m-d H:i:s', strtotime(($loan['submitted_at'] ?: 'now') . ' UTC') + notification_rule_days($db, 'promotion_review_sla', 3) * 86400),
                'action_url' => 'dashboard.php?page=loans&view=' . (int) $loan['id'], 'source_key' => 'loan:' . (int) $loan['id'] . ':promotion:round:' . (int) $loan['review_round'] . ':user:' . $userId,
            ]);
        }
        return;
    }
    if (role_is_provincial($role)) {
        $provinceId = (int) $user['province_id'];
        $unassigned = $db->prepare("SELECT DISTINCT c.id, c.name FROM cooperatives c JOIN loan_applications a ON a.cooperative_id = c.id WHERE c.province_id = ? AND a.assigned_officer_id IS NULL AND a.status IN ('submitted', 'promotion_review')");
        $unassigned->execute([$provinceId]);
        foreach ($unassigned->fetchAll() as $organization) {
            workflow_task_upsert($db, [
                'task_type' => 'officer_assignment', 'entity_type' => 'cooperative', 'entity_id' => (int) $organization['id'], 'cooperative_id' => (int) $organization['id'],
                'province_id' => $provinceId, 'assigned_user_id' => $userId, 'title' => 'มอบหมายเจ้าหน้าที่ส่งเสริม', 'description' => $organization['name'],
                'priority' => 'warning', 'due_at' => notification_due_at($db, 'officer_assignment_sla', 2),
                'action_url' => 'dashboard.php?page=registry&view=' . (int) $organization['id'], 'source_key' => 'cooperative:' . (int) $organization['id'] . ':assignment:user:' . $userId,
            ]);
        }
        $reviews = $db->prepare("SELECT a.id, a.application_no, a.review_round, a.updated_at, a.cooperative_id, c.name FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE c.province_id = ? AND a.status = 'province_review'");
        $reviews->execute([$provinceId]);
        foreach ($reviews->fetchAll() as $loan) {
            workflow_task_upsert($db, [
                'task_type' => 'province_review', 'entity_type' => 'loan_application', 'entity_id' => (int) $loan['id'], 'cooperative_id' => (int) $loan['cooperative_id'],
                'province_id' => $provinceId, 'assigned_user_id' => $userId, 'title' => 'พิจารณาคำขอกู้ ' . $loan['application_no'], 'description' => $loan['name'],
                'priority' => 'warning', 'due_at' => gmdate('Y-m-d H:i:s', strtotime($loan['updated_at'] . ' UTC') + notification_rule_days($db, 'province_review_sla', 5) * 86400),
                'action_url' => 'dashboard.php?page=loans&view=' . (int) $loan['id'], 'source_key' => 'loan:' . (int) $loan['id'] . ':province:round:' . (int) $loan['review_round'] . ':user:' . $userId,
            ]);
        }
        $contracts = $db->prepare("SELECT a.id, a.application_no, a.cooperative_id, a.approved_at, c.name, lc.id AS contract_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id JOIN loan_contracts lc ON lc.loan_application_id = a.id WHERE c.province_id = ? AND a.approved_by = ? AND lc.status = 'awaiting_preparation'");
        $contracts->execute([$provinceId, $userId]);
        foreach ($contracts->fetchAll() as $loan) {
            workflow_task_upsert($db, [
                'task_type' => 'contract_preparation', 'entity_type' => 'loan_application', 'entity_id' => (int) $loan['id'], 'cooperative_id' => (int) $loan['cooperative_id'],
                'province_id' => $provinceId, 'assigned_user_id' => $userId, 'title' => 'จัดทำสัญญา ' . $loan['application_no'], 'description' => $loan['name'],
                'priority' => 'warning', 'due_at' => gmdate('Y-m-d H:i:s', strtotime(($loan['approved_at'] ?: 'now') . ' UTC') + notification_rule_days($db, 'contract_preparation_sla', 7) * 86400),
                'action_url' => 'dashboard.php?page=contracts&contract=' . (int) $loan['contract_id'], 'source_key' => 'loan:' . (int) $loan['id'] . ':contract:user:' . $userId,
            ]);
        }
    }
}

function tracking_sync_repayments(PDO $db, array $user): void
{
    $role = $user['role_code'];
    $parameters = [];
    if ($role === 'cooperative_user') {
        $scope = 'lc.cooperative_id = ?';
        $parameters[] = (int) $user['cooperative_id'];
    } elseif ($role === 'promotion_officer') {
        $scope = 'EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = lc.cooperative_id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()))';
        $parameters[] = (int) $user['id'];
    } elseif (role_is_provincial($role)) {
        $scope = 'c.province_id = ?';
        $parameters[] = (int) $user['province_id'];
    } else {
        return;
    }
    $query = $db->prepare("SELECT rs.id, rs.installment_no, rs.due_date, DATEDIFF(rs.due_date, DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 HOUR))) AS days_until_due, lc.id AS contract_id, lc.contract_no, lc.cooperative_id, c.name, c.province_id FROM repayment_schedules rs JOIN loan_contracts lc ON lc.id = rs.contract_id JOIN cooperatives c ON c.id = lc.cooperative_id WHERE {$scope} AND lc.status = 'active' AND rs.status IN ('scheduled', 'partial', 'overdue') AND rs.due_date <= DATE_ADD(DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 HOUR)), INTERVAL 30 DAY) ORDER BY rs.due_date LIMIT 200");
    $query->execute($parameters);
    foreach ($query->fetchAll() as $schedule) {
        $days = (int) $schedule['days_until_due'];
        $overdue = $days < 0;
        $title = $overdue ? 'ติดตามงวดชำระเกินกำหนด' : 'ติดตามงวดชำระใกล้ครบกำหนด';
        $contract = $schedule['contract_no'] ?: 'สัญญารอระบุเลขที่';
        $description = $schedule['name'] . ' · ' . $contract . ' · งวดที่ ' . $schedule['installment_no'];
        workflow_task_upsert($db, [
            'task_type' => 'repayment_followup', 'entity_type' => 'repayment_schedule', 'entity_id' => (int) $schedule['id'],
            'cooperative_id' => (int) $schedule['cooperative_id'], 'province_id' => (int) $schedule['province_id'], 'assigned_user_id' => (int) $user['id'],
            'title' => $title, 'description' => $description, 'priority' => $overdue ? 'urgent' : 'warning',
            'due_at' => $schedule['due_date'] . ' 16:59:59', 'action_url' => 'dashboard.php?page=debt&contract=' . (int) $schedule['contract_id'],
            'source_key' => 'repayment:' . (int) $schedule['id'] . ':user:' . (int) $user['id'],
        ]);
        if ($overdue) {
            $bucket = 'overdue-week-' . (int) floor((abs($days) - 1) / 7);
            $message = $description . ' เกินกำหนด ' . abs($days) . ' วัน';
        } elseif ($days <= 1) {
            $bucket = 'due-1';
            $message = $description . ($days === 0 ? ' ครบกำหนดวันนี้' : ' ครบกำหนดภายใน 1 วัน');
        } elseif ($days <= 7) {
            $bucket = 'due-7';
            $message = $description . ' ครบกำหนดภายใน 7 วัน';
        } elseif ($days <= 15) {
            $bucket = 'due-15';
            $message = $description . ' ครบกำหนดภายใน 15 วัน';
        } else {
            $bucket = 'due-30';
            $message = $description . ' ครบกำหนดภายใน 30 วัน';
        }
        notification_create($db, (int) $user['id'], 'repayment', $title, $message, $overdue ? 'urgent' : 'warning', 'dashboard.php?page=debt&contract=' . (int) $schedule['contract_id'], 'repayment:' . (int) $schedule['id'] . ':' . $bucket . ':user:' . (int) $user['id'], 'repayment_schedule', (int) $schedule['id']);
    }
}

function tracking_create_due_reminders(PDO $db, int $userId): void
{
    $db->prepare("UPDATE workflow_tasks SET priority = 'urgent' WHERE assigned_user_id = ? AND status IN ('open', 'in_progress') AND due_at < UTC_TIMESTAMP() AND priority <> 'urgent'")->execute([$userId]);
    $query = $db->prepare("SELECT id, entity_type, entity_id, title, description, priority, due_at, action_url, due_at < UTC_TIMESTAMP() AS is_overdue, TIMESTAMPDIFF(DAY, due_at, UTC_TIMESTAMP()) AS overdue_days FROM workflow_tasks WHERE assigned_user_id = ? AND status IN ('open', 'in_progress') AND due_at IS NOT NULL AND due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) ORDER BY due_at LIMIT 100");
    $query->execute([$userId]);
    foreach ($query->fetchAll() as $task) {
        $overdueDays = (int) $task['overdue_days'];
        if ((int) $task['is_overdue'] === 1) {
            $bucket = 'overdue-week-' . (int) floor($overdueDays / 7);
            $message = ($task['description'] ? $task['description'] . ' · ' : '') . 'งานเกินกำหนดแล้ว';
            $priority = 'urgent';
        } else {
            $bucket = 'due-soon';
            $message = ($task['description'] ? $task['description'] . ' · ' : '') . 'งานจะครบกำหนดภายใน 1 วัน';
            $priority = 'warning';
        }
        notification_create($db, $userId, 'task_due', $task['title'], $message, $priority, $task['action_url'], 'task:' . (int) $task['id'] . ':' . $bucket, $task['entity_type'], (int) $task['entity_id']);
    }
}

function tracking_refresh_user(PDO $db, array $user): void
{
    if (!notification_schema_ready($db)) {
        return;
    }
    tracking_sync_user_tasks($db, $user);
    tracking_sync_repayments($db, $user);
    tracking_create_due_reminders($db, (int) $user['id']);
    $db->exec("UPDATE workflow_tasks wt JOIN repayment_schedules rs ON wt.entity_type = 'repayment_schedule' AND wt.entity_id = rs.id SET wt.status = 'completed', wt.completed_at = COALESCE(wt.completed_at, UTC_TIMESTAMP()) WHERE wt.status IN ('open', 'in_progress') AND rs.status IN ('paid', 'waived')");
}

function notification_menu_data(PDO $db, int $userId): array
{
    if (!notification_schema_ready($db)) {
        return ['unread' => 0, 'items' => []];
    }
    $count = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $count->execute([$userId]);
    $query = $db->prepare('SELECT id, title, message, priority, created_at FROM notifications WHERE user_id = ? ORDER BY read_at IS NULL DESC, created_at DESC, id DESC LIMIT 5');
    $query->execute([$userId]);
    return ['unread' => (int) $count->fetchColumn(), 'items' => $query->fetchAll()];
}

function notification_open_tasks(PDO $db, int $userId, int $limit = 20): array
{
    if (!notification_schema_ready($db)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $query = $db->prepare("SELECT *, CASE WHEN due_at IS NOT NULL AND due_at < UTC_TIMESTAMP() THEN 'overdue' WHEN due_at IS NOT NULL AND due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) THEN 'due_soon' ELSE 'normal' END AS due_state FROM workflow_tasks WHERE assigned_user_id = ? AND status IN ('open', 'in_progress') ORDER BY CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at, FIELD(priority, 'urgent', 'warning', 'normal'), id LIMIT {$limit}");
    $query->execute([$userId]);
    return $query->fetchAll();
}

function notification_time_label(string $utcDate): string
{
    try {
        $date = new DateTime($utcDate, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $date->format('d/m/Y H:i') . ' น.';
    } catch (Throwable $exception) {
        return $utcDate;
    }
}

function notification_due_label(?string $utcDate): string
{
    return $utcDate ? notification_time_label($utcDate) : 'ไม่กำหนดวัน';
}

function tracking_refresh_all_users(PDO $db): int
{
    if (!notification_schema_ready($db)) {
        return 0;
    }
    $query = $db->query("SELECT u.id, u.province_id, u.cooperative_id, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND r.is_active = 1 AND r.code <> 'applicant'");
    $count = 0;
    foreach ($query->fetchAll() as $user) {
        tracking_refresh_user($db, $user);
        $count++;
    }
    return $count;
}
