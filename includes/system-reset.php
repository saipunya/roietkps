<?php

function system_reset_impact(PDO $db, int $adminId): array
{
    $query = $db->prepare(
        'SELECT '
        . '(SELECT COUNT(*) FROM users WHERE id <> ?) AS users, '
        . '(SELECT COUNT(*) FROM cooperatives) AS cooperatives, '
        . '(SELECT COUNT(*) FROM loan_applications) AS loan_applications, '
        . '(SELECT COUNT(*) FROM loan_contracts) AS loan_contracts, '
        . '(SELECT COUNT(*) FROM repayments) AS repayments, '
        . '(SELECT COUNT(*) FROM organization_documents) AS organization_documents, '
        . '(SELECT COUNT(*) FROM loan_documents) AS loan_documents, '
        . '(SELECT COUNT(*) FROM public_documents) AS public_documents, '
        . '(SELECT COUNT(*) FROM workflow_tasks) AS workflow_tasks, '
        . '(SELECT COUNT(*) FROM notifications) AS notifications, '
        . '(SELECT COUNT(*) FROM audit_logs) AS audit_logs'
    );
    $query->execute([$adminId]);
    $impact = $query->fetch();
    if (!$impact) {
        throw new RuntimeException('ไม่สามารถตรวจสอบข้อมูลที่จะล้างได้');
    }
    return array_map('intval', $impact);
}

function system_reset_storage_path(string $type): string
{
    $folders = [
        'public' => 'public-documents',
        'loan' => 'loan-documents',
        'organization' => 'organization-documents',
    ];
    if (!isset($folders[$type])) {
        throw new InvalidArgumentException('Unknown document storage type.');
    }
    $folder = $folders[$type];
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'roietkps-storage' . DIRECTORY_SEPARATOR . $folder;
}

function system_reset_remove_files(string $directory, array $storedNames): array
{
    $removed = 0;
    $failed = 0;
    foreach (array_unique($storedNames) as $storedName) {
        if (!is_string($storedName) || $storedName === '' || basename($storedName) !== $storedName) {
            $failed++;
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }
        if (@unlink($path)) {
            $removed++;
        } else {
            $failed++;
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}

function system_reset_execute(PDO $db, array $actor, string $currentPassword): array
{
    $adminId = (int) ($actor['id'] ?? 0);
    if ($adminId < 1 || ($actor['role_code'] ?? '') !== 'system_admin') {
        throw new DomainException('เฉพาะ Admin เท่านั้นที่ล้างข้อมูลระบบได้');
    }
    if ($currentPassword === '' || strlen($currentPassword) > 72 || strpos($currentPassword, "\0") !== false) {
        throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
    }

    $lockAcquired = (int) $db->query("SELECT GET_LOCK('roietkps_system_reset', 10)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('มีคำสั่งล้างข้อมูลกำลังทำงาน กรุณาลองใหม่ภายหลัง');
    }

    $publicFiles = [];
    $loanFiles = [];
    $organizationFiles = [];
    $impact = [];
    try {
        $db->beginTransaction();
        $adminQuery = $db->prepare("SELECT u.password_hash FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active' AND u.must_change_password = 0 AND r.code = 'system_admin' AND r.is_active = 1 FOR UPDATE");
        $adminQuery->execute([$adminId]);
        $passwordHash = $adminQuery->fetchColumn();
        if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) {
            throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
        }

        $impact = system_reset_impact($db, $adminId);
        $publicFiles = $db->query('SELECT stored_name FROM public_documents')->fetchAll(PDO::FETCH_COLUMN);
        $loanFiles = $db->query('SELECT stored_name FROM loan_documents')->fetchAll(PDO::FETCH_COLUMN);
        $organizationFiles = $db->query('SELECT stored_name FROM organization_documents')->fetchAll(PDO::FETCH_COLUMN);

        $deleteStatements = [
            'DELETE FROM notification_deliveries',
            'DELETE FROM notifications',
            'DELETE FROM workflow_tasks',
            'UPDATE contract_follow_ups SET replaces_follow_up_id = NULL WHERE replaces_follow_up_id IS NOT NULL',
            'DELETE FROM contract_follow_ups',
            'UPDATE repayments SET replaces_repayment_id = NULL WHERE replaces_repayment_id IS NOT NULL',
            'DELETE FROM repayments',
            'DELETE FROM repayment_schedules',
            'DELETE FROM loan_contracts',
            'DELETE FROM loan_document_reviews',
            'UPDATE loan_documents SET replaces_document_id = NULL WHERE replaces_document_id IS NOT NULL',
            'DELETE FROM loan_documents',
            'DELETE FROM loan_reviews',
            'DELETE FROM organization_documents',
            'DELETE FROM loan_guarantors',
            'DELETE FROM loan_applications',
            'DELETE FROM organization_officer_assignments',
            'DELETE FROM public_documents',
            'DELETE FROM registration_requests',
            'DELETE FROM audit_logs',
            'UPDATE users SET created_by = NULL WHERE created_by IS NOT NULL',
        ];
        foreach ($deleteStatements as $statement) {
            $db->exec($statement);
        }

        $deleteUsers = $db->prepare('DELETE FROM users WHERE id <> ?');
        $deleteUsers->execute([$adminId]);
        $db->exec('DELETE FROM cooperatives');
        $db->exec('DELETE FROM auth_rate_limits');

        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'system.reset', 'system', NULL, ?, ?)");
        $audit->execute([
            $adminId,
            json_encode(['cleared_counts' => $impact], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    } finally {
        $db->query("SELECT RELEASE_LOCK('roietkps_system_reset')");
    }

    $publicResult = system_reset_remove_files(system_reset_storage_path('public'), $publicFiles);
    $loanResult = system_reset_remove_files(system_reset_storage_path('loan'), $loanFiles);
    $organizationResult = system_reset_remove_files(system_reset_storage_path('organization'), $organizationFiles);
    return [
        'counts' => $impact,
        'files_removed' => $publicResult['removed'] + $loanResult['removed'] + $organizationResult['removed'],
        'files_failed' => $publicResult['failed'] + $loanResult['failed'] + $organizationResult['failed'],
    ];
}
