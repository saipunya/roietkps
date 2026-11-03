<?php

function loan_lifecycle_status(string $applicationStatus, ?string $contractStatus = null): array
{
    if ($applicationStatus === 'cancelled' || $contractStatus === 'cancelled') {
        return ['code' => 'cancelled', 'label' => 'ยกเลิก', 'tone' => 'danger'];
    }
    if (in_array($contractStatus, ['completed', 'closed'], true)) {
        return ['code' => 'completed', 'label' => 'เสร็จสิ้น', 'tone' => 'success'];
    }
    if ($applicationStatus === 'rejected') {
        return ['code' => 'rejected', 'label' => 'ไม่อนุมัติ', 'tone' => 'danger'];
    }
    if ($applicationStatus === 'approved') {
        return ['code' => 'approved', 'label' => 'อนุมัติ', 'tone' => 'success'];
    }
    if ($applicationStatus === 'revision_requested') {
        return ['code' => 'revision', 'label' => 'ส่งกลับแก้ไข', 'tone' => 'warning'];
    }
    if (in_array($applicationStatus, ['submitted', 'promotion_review', 'province_review'], true)) {
        return ['code' => 'pending_approval', 'label' => 'เสนอขออนุมัติ', 'tone' => 'warning'];
    }
    return ['code' => 'draft', 'label' => 'ฉบับร่าง', 'tone' => 'neutral'];
}

function loan_application_can_cancel(array $actor, array $application): bool
{
    if (!account_can($actor, 'loan_applications.cancel') || !empty($application['contract_id'])) {
        return false;
    }
    $status = (string) ($application['status'] ?? '');
    if (account_is_admin($actor)) {
        return in_array($status, ['draft', 'submitted', 'promotion_review', 'revision_requested', 'province_review'], true);
    }
    if (($actor['role_code'] ?? '') === 'cooperative_user') {
        return (int) ($actor['cooperative_id'] ?? 0) === (int) ($application['cooperative_id'] ?? 0)
            && in_array($status, ['draft', 'submitted', 'promotion_review', 'revision_requested'], true);
    }
    if (role_is_provincial((string) ($actor['role_code'] ?? ''))) {
        return (int) ($actor['province_id'] ?? 0) === (int) ($application['organization_province_id'] ?? 0)
            && $status === 'province_review';
    }
    return false;
}

function loan_application_cancel(PDO $db, array $actor, int $applicationId, string $confirmation, string $reason): void
{
    if ($applicationId < 1 || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 1000) {
        throw new DomainException('กรุณาระบุเหตุผลการยกเลิก 5–1,000 ตัวอักษร');
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT a.id, a.application_no, a.cooperative_id, a.assigned_officer_id, a.status,
            c.province_id AS organization_province_id, lc.id AS contract_id
            FROM loan_applications a
            JOIN cooperatives c ON c.id = a.cooperative_id
            LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id
            WHERE a.id = ? FOR UPDATE");
        $query->execute([$applicationId]);
        $application = $query->fetch();
        if (!$application || !loan_application_can_cancel($actor, $application)) {
            throw new DomainException('คำขอนี้ไม่อยู่ในสถานะที่คุณสามารถยกเลิกได้');
        }
        if (!hash_equals((string) $application['application_no'], trim($confirmation))) {
            throw new DomainException('กรุณาพิมพ์เลขที่คำขอให้ตรงกับรายการเพื่อยืนยันการยกเลิก');
        }

        $db->prepare("UPDATE loan_applications SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancellation_reason = ?, row_version = row_version + 1 WHERE id = ?")
            ->execute([(int) $actor['id'], $reason, $applicationId]);
        if (notification_schema_ready($db)) {
            $db->prepare("UPDATE workflow_tasks SET status = 'cancelled', completed_at = COALESCE(completed_at, UTC_TIMESTAMP()), completed_by = ? WHERE entity_type = 'loan_application' AND entity_id = ? AND status IN ('open', 'in_progress')")
                ->execute([(int) $actor['id'], $applicationId]);
        }

        $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'loan.cancel', 'loan_application', ?, ?, ?, ?)");
        $audit->execute([
            (int) $actor['id'],
            $applicationId,
            json_encode(['status' => $application['status']], JSON_UNESCAPED_UNICODE),
            json_encode(['status' => 'cancelled', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        $recipients = notification_active_user_ids($db, 'cooperative_user', null, (int) $application['cooperative_id']);
        if (!empty($application['assigned_officer_id'])) {
            $recipients[] = (int) $application['assigned_officer_id'];
        }
        if ($application['status'] === 'province_review') {
            $recipients = array_merge($recipients, notification_provincial_user_ids($db, (int) $application['organization_province_id']));
        }
        $recipients = array_values(array_filter(array_unique(array_map('intval', $recipients)), static function ($userId) use ($actor) {
            return $userId > 0 && $userId !== (int) $actor['id'];
        }));
        notification_create_for_users(
            $db,
            $recipients,
            'loan_cancelled',
            'คำขอกู้ถูกยกเลิก',
            $application['application_no'] . ' ถูกยกเลิก: ' . mb_substr($reason, 0, 180, 'UTF-8'),
            'urgent',
            'dashboard.php?page=loans&view=' . $applicationId,
            'loan:' . $applicationId . ':cancelled',
            'loan_application',
            $applicationId
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}
