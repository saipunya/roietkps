<?php

$trackingError = '';
$trackingNotice = isset($_SESSION['tracking_notice']) ? (string) $_SESSION['tracking_notice'] : '';
unset($_SESSION['tracking_notice']);
$db = app_db();

$contractStatusLabels = [
    'awaiting_preparation' => 'รอจัดทำสัญญา',
    'draft' => 'ร่างสัญญา',
    'pending_review' => 'รอตรวจสัญญา',
    'awaiting_signature' => 'รอลงนาม',
    'signed' => 'ลงนามแล้ว',
    'pending_disbursement' => 'รอเบิกจ่าย',
    'active' => 'อยู่ระหว่างชำระ',
    'completed' => 'ชำระครบ',
    'closed' => 'ปิดสัญญา',
    'cancelled' => 'ยกเลิก',
];
$scheduleStatusLabels = [
    'scheduled' => 'รอชำระ',
    'partial' => 'ชำระบางส่วน',
    'paid' => 'ชำระแล้ว',
    'overdue' => 'ค้างชำระ',
    'waived' => 'ยกเว้น',
];
$followUpChannelLabels = [
    'phone' => 'โทรศัพท์',
    'line' => 'LINE',
    'email' => 'อีเมล',
    'letter' => 'หนังสือราชการ',
    'visit' => 'ลงพื้นที่',
    'other' => 'ช่องทางอื่น',
];
$followUpOutcomeLabels = [
    'contacted' => 'ติดต่อได้',
    'promised_payment' => 'รับปากจะชำระ',
    'no_response' => 'ยังติดต่อไม่ได้',
    'disputed' => 'ขอทบทวนยอด',
    'restructure_requested' => 'ขอปรับโครงสร้างหนี้',
    'other' => 'ผลอื่น',
];

function post_approval_scope(array $user): array
{
    if ($user['role_code'] === 'cooperative_user') {
        return ['lc.cooperative_id = ?', [(int) $user['cooperative_id']]];
    }
    if ($user['role_code'] === 'provincial_officer') {
        return ['c.province_id = ?', [(int) $user['province_id']]];
    }
    if ($user['role_code'] === 'promotion_officer') {
        return ["(a.assigned_officer_id = ? OR EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = lc.cooperative_id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE())))", [(int) $user['id'], (int) $user['id']]];
    }
    return ['1 = 1', []];
}

function post_approval_schema_ready(PDO $db): bool
{
    try {
        return (bool) $db->query("SHOW TABLES LIKE 'contract_follow_ups'")->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function post_approval_money(string $value, string $label, bool $allowZero = true): float
{
    if (!preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $value)) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    $amount = (float) $value;
    if ((!$allowZero && $amount <= 0) || ($allowZero && $amount < 0)) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return $amount;
}

function post_approval_date(string $value, string $label, bool $required = true): ?string
{
    if ($value === '' && !$required) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Bangkok'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return $value;
}

function post_approval_datetime(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Bangkok'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function post_approval_datetime_input(?string $utcValue): string
{
    if (!$utcValue) {
        return '';
    }
    try {
        $date = new DateTimeImmutable($utcValue, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('Y-m-d\TH:i');
    } catch (Throwable $exception) {
        return '';
    }
}

function post_approval_display_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('d/m/Y') : $value;
}

function post_approval_display_datetime(?string $utcValue): string
{
    if (!$utcValue) {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($utcValue, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('d/m/Y H:i') . ' น.';
    } catch (Throwable $exception) {
        return $utcValue;
    }
}

function post_approval_today(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
}

function post_approval_audit(PDO $db, int $actorId, string $action, string $entityType, int $entityId, ?array $oldValues, ?array $newValues): void
{
    $query = $db->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([
        $actorId,
        $action,
        $entityType,
        $entityId,
        $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
        $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
        hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
}

function post_approval_contract_for_update(PDO $db, array $user, int $contractId): array
{
    [$scopeSql, $scopeParameters] = post_approval_scope($user);
    $query = $db->prepare("SELECT lc.*, a.application_no, a.assigned_officer_id, c.name AS organization_name, c.province_id FROM loan_contracts lc JOIN loan_applications a ON a.id = lc.loan_application_id JOIN cooperatives c ON c.id = lc.cooperative_id WHERE lc.id = ? AND {$scopeSql} FOR UPDATE");
    $query->execute(array_merge([$contractId], $scopeParameters));
    $contract = $query->fetch();
    if (!$contract) {
        throw new DomainException('ไม่พบสัญญาหรือคุณไม่มีสิทธิ์เข้าถึงรายการนี้');
    }
    return $contract;
}

function post_approval_contract_transitions(string $status): array
{
    $transitions = [
        'awaiting_preparation' => ['awaiting_preparation', 'draft', 'cancelled'],
        'draft' => ['draft', 'pending_review', 'cancelled'],
        'pending_review' => ['pending_review', 'draft', 'awaiting_signature', 'cancelled'],
        'awaiting_signature' => ['awaiting_signature', 'signed', 'cancelled'],
        'signed' => ['signed', 'pending_disbursement', 'cancelled'],
        'pending_disbursement' => ['pending_disbursement'],
        'active' => ['active'],
        'completed' => ['completed', 'closed'],
        'closed' => ['closed'],
        'cancelled' => ['cancelled'],
    ];
    return $transitions[$status] ?? [$status];
}

function post_approval_sync_schedule_status(PDO $db, int $scheduleId): ?string
{
    $query = $db->prepare('SELECT rs.due_date, rs.principal_due, rs.interest_due, rs.penalty_due, COALESCE(SUM(r.principal_amount), 0) AS principal_paid, COALESCE(SUM(r.interest_amount), 0) AS interest_paid, COALESCE(SUM(r.penalty_amount), 0) AS penalty_paid FROM repayment_schedules rs LEFT JOIN repayments r ON r.schedule_id = rs.id WHERE rs.id = ? GROUP BY rs.id, rs.due_date, rs.principal_due, rs.interest_due, rs.penalty_due');
    $query->execute([$scheduleId]);
    $row = $query->fetch();
    if (!$row) {
        return null;
    }
    $paid = (float) $row['principal_paid'] + (float) $row['interest_paid'] + (float) $row['penalty_paid'];
    $complete = (float) $row['principal_paid'] + 0.009 >= (float) $row['principal_due']
        && (float) $row['interest_paid'] + 0.009 >= (float) $row['interest_due']
        && (float) $row['penalty_paid'] + 0.009 >= (float) $row['penalty_due'];
    $status = $complete ? 'paid' : ($paid > 0 ? 'partial' : ($row['due_date'] < post_approval_today() ? 'overdue' : 'scheduled'));
    $db->prepare('UPDATE repayment_schedules SET status = ?, paid_at = IF(? = \'paid\', COALESCE(paid_at, UTC_TIMESTAMP()), NULL) WHERE id = ?')->execute([$status, $status, $scheduleId]);
    return $status;
}

$trackingSchemaReady = post_approval_schema_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $trackingError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            $contractId = filter_var(post_text('contract_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

            if ($operation === 'save_contract') {
                if (!account_can($currentUser, 'contracts.manage')) {
                    throw new DomainException('คุณไม่มีสิทธิ์จัดการสัญญา');
                }
                $contractNo = post_text('contract_no');
                $startDate = post_approval_date(post_text('start_date'), 'วันที่เริ่มสัญญา', false);
                $maturityDate = post_approval_date(post_text('maturity_date'), 'วันที่ครบกำหนดสัญญา', false);
                $targetStatus = post_text('contract_status');
                if ($contractNo !== '' && (!preg_match('/\A[A-Za-z0-9._\/-]{1,50}\z/', $contractNo))) {
                    throw new DomainException('เลขที่สัญญาใช้ได้เฉพาะภาษาอังกฤษ ตัวเลข จุด ขีดกลาง ขีดล่าง และเครื่องหมายทับ');
                }
                if ($startDate && $maturityDate && $maturityDate <= $startDate) {
                    throw new DomainException('วันที่ครบกำหนดต้องอยู่หลังวันที่เริ่มสัญญา');
                }
                $db->beginTransaction();
                $contract = post_approval_contract_for_update($db, $currentUser, $contractId);
                if (!in_array($targetStatus, post_approval_contract_transitions($contract['status']), true)) {
                    throw new DomainException('ไม่สามารถเปลี่ยนสัญญาไปยังสถานะที่เลือกได้');
                }
                if (!in_array($targetStatus, ['awaiting_preparation', 'draft', 'cancelled'], true) && $contractNo === '') {
                    throw new DomainException('กรุณาระบุเลขที่สัญญาก่อนส่งตรวจสัญญา');
                }
                if (in_array($targetStatus, ['awaiting_signature', 'signed', 'pending_disbursement'], true) && (!$startDate || !$maturityDate)) {
                    throw new DomainException('กรุณาระบุวันที่เริ่มและวันที่ครบกำหนดสัญญา');
                }
                $signedAt = $targetStatus === 'signed' && !$contract['signed_at'] ? gmdate('Y-m-d H:i:s') : $contract['signed_at'];
                $query = $db->prepare('UPDATE loan_contracts SET contract_no = ?, start_date = ?, maturity_date = ?, status = ?, signed_at = ? WHERE id = ?');
                $query->execute([$contractNo ?: null, $startDate, $maturityDate, $targetStatus, $signedAt, $contractId]);
                post_approval_audit($db, (int) $currentUser['id'], 'contract.update', 'loan_contract', $contractId, ['contract_no' => $contract['contract_no'], 'status' => $contract['status']], ['contract_no' => $contractNo ?: null, 'status' => $targetStatus]);
                if ($targetStatus !== 'awaiting_preparation') {
                    workflow_complete_tasks($db, 'contract_preparation', 'loan_application', (int) $contract['loan_application_id'], (int) $currentUser['id']);
                }
                $db->commit();
                $_SESSION['tracking_notice'] = 'บันทึกข้อมูลสัญญาแล้ว';
                header('Location: dashboard.php?page=contracts&contract=' . $contractId, true, 303);
                exit;
            }

            if ($operation === 'activate_contract') {
                if (!account_can($currentUser, 'contracts.manage')) {
                    throw new DomainException('คุณไม่มีสิทธิ์บันทึกการเบิกจ่าย');
                }
                $disbursedAt = post_approval_datetime(post_text('disbursed_at'), 'วันและเวลาเบิกจ่าย');
                $db->beginTransaction();
                $contract = post_approval_contract_for_update($db, $currentUser, $contractId);
                if (!in_array($contract['status'], ['signed', 'pending_disbursement'], true) || !$contract['contract_no'] || !$contract['start_date'] || !$contract['maturity_date']) {
                    throw new DomainException('สัญญาต้องลงนามและมีข้อมูลครบก่อนบันทึกการเบิกจ่าย');
                }
                $db->prepare("UPDATE loan_contracts SET status = 'active', disbursed_at = ?, locked_at = COALESCE(locked_at, UTC_TIMESTAMP()) WHERE id = ?")->execute([$disbursedAt, $contractId]);
                post_approval_audit($db, (int) $currentUser['id'], 'contract.disburse', 'loan_contract', $contractId, ['status' => $contract['status']], ['status' => 'active', 'disbursed_at' => $disbursedAt]);
                $cooperativeUsers = notification_active_user_ids($db, 'cooperative_user', null, (int) $contract['cooperative_id']);
                notification_create_for_users($db, $cooperativeUsers, 'contract', 'สัญญาเริ่มใช้งานแล้ว', 'สัญญา ' . $contract['contract_no'] . ' บันทึกการเบิกจ่ายแล้ว กรุณาติดตามกำหนดชำระในระบบ', 'normal', 'dashboard.php?page=debt&contract=' . $contractId, 'contract:' . $contractId . ':active', 'loan_contract', $contractId);
                $db->commit();
                $_SESSION['tracking_notice'] = 'บันทึกการเบิกจ่ายและเปิดใช้งานสัญญาแล้ว กรุณาเพิ่มตารางชำระ';
                header('Location: dashboard.php?page=contracts&contract=' . $contractId, true, 303);
                exit;
            }

            if ($operation === 'save_schedule') {
                if (!account_can($currentUser, 'contracts.manage')) {
                    throw new DomainException('คุณไม่มีสิทธิ์จัดการตารางชำระ');
                }
                $scheduleId = filter_var(post_text('schedule_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $installmentNo = filter_var(post_text('installment_no'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]) ?: 0;
                $dueDate = post_approval_date(post_text('due_date'), 'วันครบกำหนด');
                $principalDue = post_approval_money(post_text('principal_due'), 'เงินต้น');
                $interestDue = post_approval_money(post_text('interest_due'), 'ดอกเบี้ย');
                $penaltyDue = post_approval_money(post_text('penalty_due'), 'ค่าปรับ');
                if (!$installmentNo || $principalDue + $interestDue + $penaltyDue <= 0) {
                    throw new DomainException('กรุณาระบุงวดและยอดที่ต้องชำระ');
                }
                $db->beginTransaction();
                $contract = post_approval_contract_for_update($db, $currentUser, $contractId);
                if ($contract['status'] !== 'active') {
                    throw new DomainException('เพิ่มตารางชำระได้เมื่อสัญญาอยู่ระหว่างชำระ');
                }
                if ($contract['start_date'] && $dueDate < $contract['start_date']) {
                    throw new DomainException('วันครบกำหนดงวดต้องไม่อยู่ก่อนวันเริ่มสัญญา');
                }
                $oldSchedule = null;
                if ($scheduleId) {
                    $query = $db->prepare('SELECT rs.* FROM repayment_schedules rs WHERE rs.id = ? AND rs.contract_id = ? FOR UPDATE');
                    $query->execute([$scheduleId, $contractId]);
                    $oldSchedule = $query->fetch();
                    if (!$oldSchedule) {
                        throw new DomainException('ไม่พบงวดชำระที่ต้องการแก้ไข');
                    }
                    $query = $db->prepare('SELECT COUNT(*) FROM repayments WHERE schedule_id = ?');
                    $query->execute([$scheduleId]);
                    if ((int) $query->fetchColumn() > 0) {
                        throw new DomainException('งวดที่มีรายการรับชำระแล้วไม่สามารถแก้ไขได้');
                    }
                }
                $query = $db->prepare('SELECT COALESCE(SUM(principal_due), 0) FROM repayment_schedules WHERE contract_id = ? AND id <> ?');
                $query->execute([$contractId, $scheduleId]);
                if ((float) $query->fetchColumn() + $principalDue > (float) $contract['principal_amount'] + 0.009) {
                    throw new DomainException('ยอดเงินต้นรวมในตารางชำระเกินเงินต้นตามสัญญา');
                }
                $status = $dueDate < post_approval_today() ? 'overdue' : 'scheduled';
                if ($scheduleId) {
                    $query = $db->prepare('UPDATE repayment_schedules SET installment_no = ?, due_date = ?, principal_due = ?, interest_due = ?, penalty_due = ?, status = ?, paid_at = NULL WHERE id = ?');
                    $query->execute([$installmentNo, $dueDate, $principalDue, $interestDue, $penaltyDue, $status, $scheduleId]);
                } else {
                    $query = $db->prepare('INSERT INTO repayment_schedules (contract_id, installment_no, due_date, principal_due, interest_due, penalty_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $query->execute([$contractId, $installmentNo, $dueDate, $principalDue, $interestDue, $penaltyDue, $status]);
                    $scheduleId = (int) $db->lastInsertId();
                }
                post_approval_audit($db, (int) $currentUser['id'], $oldSchedule ? 'repayment_schedule.update' : 'repayment_schedule.create', 'repayment_schedule', $scheduleId, $oldSchedule ?: null, ['contract_id' => $contractId, 'installment_no' => $installmentNo, 'due_date' => $dueDate, 'principal_due' => $principalDue, 'interest_due' => $interestDue, 'penalty_due' => $penaltyDue]);
                $db->commit();
                $_SESSION['tracking_notice'] = $oldSchedule ? 'แก้ไขงวดชำระแล้ว' : 'เพิ่มงวดชำระแล้ว';
                header('Location: dashboard.php?page=contracts&contract=' . $contractId, true, 303);
                exit;
            }

            if ($operation === 'record_repayment') {
                if (!account_can($currentUser, 'repayments.record')) {
                    throw new DomainException('คุณไม่มีสิทธิ์บันทึกรับชำระ');
                }
                $scheduleId = filter_var(post_text('schedule_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $receiptNo = post_text('receipt_no');
                $paidAt = post_approval_datetime(post_text('paid_at'), 'วันและเวลารับชำระ');
                $principalAmount = post_approval_money(post_text('principal_amount'), 'เงินต้นรับชำระ');
                $interestAmount = post_approval_money(post_text('interest_amount'), 'ดอกเบี้ยรับชำระ');
                $penaltyAmount = post_approval_money(post_text('penalty_amount'), 'ค่าปรับรับชำระ');
                $paymentChannel = post_text('payment_channel');
                $note = post_text('payment_note');
                if (!preg_match('/\A[A-Za-z0-9._\/-]{1,50}\z/', $receiptNo)) {
                    throw new DomainException('เลขที่ใบรับใช้ได้เฉพาะภาษาอังกฤษ ตัวเลข จุด ขีดกลาง ขีดล่าง และเครื่องหมายทับ');
                }
                if ($principalAmount + $interestAmount + $penaltyAmount <= 0 || mb_strlen($paymentChannel, 'UTF-8') > 100 || mb_strlen($note, 'UTF-8') > 1000) {
                    throw new DomainException('กรุณาตรวจสอบยอดรับชำระและรายละเอียด');
                }
                $db->beginTransaction();
                $contract = post_approval_contract_for_update($db, $currentUser, $contractId);
                if ($contract['status'] !== 'active' || $principalAmount > (float) $contract['outstanding_principal'] + 0.009) {
                    throw new DomainException('สัญญาไม่พร้อมรับชำระหรือยอดเงินต้นเกินยอดคงเหลือ');
                }
                if ($scheduleId) {
                    $query = $db->prepare('SELECT id FROM repayment_schedules WHERE id = ? AND contract_id = ? FOR UPDATE');
                    $query->execute([$scheduleId, $contractId]);
                    if (!$query->fetchColumn()) {
                        throw new DomainException('งวดชำระที่เลือกไม่อยู่ในสัญญานี้');
                    }
                }
                $query = $db->prepare('INSERT INTO repayments (contract_id, schedule_id, receipt_no, paid_at, principal_amount, interest_amount, penalty_amount, payment_channel, note, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $query->execute([$contractId, $scheduleId ?: null, $receiptNo, $paidAt, $principalAmount, $interestAmount, $penaltyAmount, $paymentChannel ?: null, $note ?: null, $currentUser['id']]);
                $repaymentId = (int) $db->lastInsertId();
                $remaining = max(0, (float) $contract['outstanding_principal'] - $principalAmount);
                if ($scheduleId) {
                    $scheduleStatus = post_approval_sync_schedule_status($db, $scheduleId);
                    if ($scheduleStatus === 'paid') {
                        workflow_complete_tasks($db, 'repayment_followup', 'repayment_schedule', $scheduleId, (int) $currentUser['id']);
                    }
                }
                $query = $db->prepare("SELECT COUNT(*) AS schedule_count, COALESCE(SUM(status NOT IN ('paid', 'waived')), 0) AS pending_count FROM repayment_schedules WHERE contract_id = ?");
                $query->execute([$contractId]);
                $scheduleProgress = $query->fetch();
                $allSchedulesSettled = (int) $scheduleProgress['schedule_count'] === 0 || (int) $scheduleProgress['pending_count'] === 0;
                $newStatus = $remaining <= 0.009 && $allSchedulesSettled ? 'completed' : 'active';
                $db->prepare('UPDATE loan_contracts SET outstanding_principal = ?, status = ? WHERE id = ?')->execute([$remaining, $newStatus, $contractId]);
                post_approval_audit($db, (int) $currentUser['id'], 'repayment.record', 'repayment', $repaymentId, null, ['contract_id' => $contractId, 'schedule_id' => $scheduleId ?: null, 'receipt_no' => $receiptNo, 'principal_amount' => $principalAmount, 'interest_amount' => $interestAmount, 'penalty_amount' => $penaltyAmount]);
                $cooperativeUsers = notification_active_user_ids($db, 'cooperative_user', null, (int) $contract['cooperative_id']);
                notification_create_for_users($db, $cooperativeUsers, 'repayment', 'บันทึกรับชำระแล้ว', 'สัญญา ' . ($contract['contract_no'] ?: $contract['application_no']) . ' รับชำระรวม ' . number_format($principalAmount + $interestAmount + $penaltyAmount, 2) . ' บาท', 'normal', 'dashboard.php?page=debt&contract=' . $contractId, 'repayment:' . $repaymentId . ':recorded', 'repayment', $repaymentId);
                $db->commit();
                $_SESSION['tracking_notice'] = $newStatus === 'completed' ? 'บันทึกรับชำระแล้วและเงินต้นครบตามสัญญา' : 'บันทึกรับชำระแล้ว';
                header('Location: dashboard.php?page=debt&contract=' . $contractId, true, 303);
                exit;
            }

            if ($operation === 'record_follow_up') {
                if (!account_can($currentUser, 'debt.record_follow_up') || !$trackingSchemaReady) {
                    throw new DomainException('คุณไม่มีสิทธิ์บันทึกผลติดตามหรือยังไม่ติดตั้งฐานข้อมูลส่วนติดตาม');
                }
                $scheduleId = filter_var(post_text('schedule_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $followedUpAt = post_approval_datetime(post_text('followed_up_at'), 'วันและเวลาติดตาม');
                $channel = post_text('follow_up_channel');
                $outcome = post_text('follow_up_outcome');
                $note = post_text('follow_up_note');
                $nextDate = post_approval_date(post_text('next_follow_up_date'), 'วันที่ติดตามครั้งถัดไป', false);
                $channels = ['phone', 'line', 'email', 'letter', 'visit', 'other'];
                $outcomes = ['contacted', 'promised_payment', 'no_response', 'disputed', 'restructure_requested', 'other'];
                if (!in_array($channel, $channels, true) || !in_array($outcome, $outcomes, true) || $note === '' || mb_strlen($note, 'UTF-8') > 2000) {
                    throw new DomainException('กรุณาระบุผลการติดตามให้ครบถ้วน');
                }
                $db->beginTransaction();
                $contract = post_approval_contract_for_update($db, $currentUser, $contractId);
                if (!in_array($contract['status'], ['active', 'completed'], true)) {
                    throw new DomainException('สัญญานี้ยังไม่อยู่ในขั้นตอนติดตามการชำระ');
                }
                if ($scheduleId) {
                    $query = $db->prepare('SELECT id FROM repayment_schedules WHERE id = ? AND contract_id = ?');
                    $query->execute([$scheduleId, $contractId]);
                    if (!$query->fetchColumn()) {
                        throw new DomainException('งวดชำระที่เลือกไม่อยู่ในสัญญานี้');
                    }
                }
                $query = $db->prepare('INSERT INTO contract_follow_ups (contract_id, schedule_id, followed_up_at, channel, outcome, note, next_follow_up_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $query->execute([$contractId, $scheduleId ?: null, $followedUpAt, $channel, $outcome, $note, $nextDate, $currentUser['id']]);
                $followUpId = (int) $db->lastInsertId();
                post_approval_audit($db, (int) $currentUser['id'], 'debt.follow_up', 'contract_follow_up', $followUpId, null, ['contract_id' => $contractId, 'schedule_id' => $scheduleId ?: null, 'outcome' => $outcome, 'next_follow_up_date' => $nextDate]);
                if (notification_schema_ready($db)) {
                    $query = $db->prepare("UPDATE workflow_tasks SET status = 'completed', completed_at = UTC_TIMESTAMP(), completed_by = ? WHERE task_type = 'debt_follow_up' AND entity_type = 'loan_contract' AND entity_id = ? AND assigned_user_id = ? AND status IN ('open', 'in_progress')");
                    $query->execute([$currentUser['id'], $contractId, $currentUser['id']]);
                }
                if ($nextDate) {
                    $nextDueAt = (new DateTimeImmutable($nextDate . ' 09:00:00', new DateTimeZone('Asia/Bangkok')))
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                    workflow_task_upsert($db, [
                        'task_type' => 'debt_follow_up',
                        'entity_type' => 'loan_contract',
                        'entity_id' => $contractId,
                        'cooperative_id' => (int) $contract['cooperative_id'],
                        'province_id' => (int) $contract['province_id'],
                        'assigned_user_id' => (int) $currentUser['id'],
                        'title' => 'ติดตามสัญญา ' . ($contract['contract_no'] ?: $contract['application_no']),
                        'description' => $contract['organization_name'],
                        'priority' => 'warning',
                        'due_at' => $nextDueAt,
                        'action_url' => 'dashboard.php?page=debt&contract=' . $contractId,
                        'source_key' => 'follow-up:' . $followUpId . ':user:' . (int) $currentUser['id'],
                    ]);
                }
                $db->commit();
                $_SESSION['tracking_notice'] = 'บันทึกผลการติดตามแล้ว';
                header('Location: dashboard.php?page=debt&contract=' . $contractId, true, 303);
                exit;
            }
        } catch (DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            $trackingError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
                http_response_code(422);
                $trackingError = 'เลขที่สัญญา เลขที่ใบรับ หรืองวดชำระซ้ำกับข้อมูลเดิม';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}

[$trackingScopeSql, $trackingScopeParameters] = post_approval_scope($currentUser);
$overdueUpdate = $db->prepare("UPDATE repayment_schedules rs JOIN loan_contracts lc ON lc.id = rs.contract_id JOIN loan_applications a ON a.id = lc.loan_application_id JOIN cooperatives c ON c.id = lc.cooperative_id SET rs.status = 'overdue' WHERE rs.due_date < CURRENT_DATE() AND rs.status = 'scheduled' AND {$trackingScopeSql}");
$overdueUpdate->execute($trackingScopeParameters);

$query = $db->prepare("SELECT
    lc.*, a.application_no, a.purpose, a.assigned_officer_id,
    c.name AS organization_name, c.province_id, u.full_name AS officer_name,
    (SELECT COUNT(*) FROM repayment_schedules rs WHERE rs.contract_id = lc.id) AS schedule_count,
    (SELECT COUNT(*) FROM repayment_schedules rs WHERE rs.contract_id = lc.id AND (rs.status = 'overdue' OR (rs.due_date < CURRENT_DATE() AND rs.status IN ('scheduled', 'partial')))) AS overdue_count,
    (SELECT MIN(rs.due_date) FROM repayment_schedules rs WHERE rs.contract_id = lc.id AND rs.status IN ('scheduled', 'partial', 'overdue')) AS next_due_date,
    (SELECT COALESCE(SUM(r.principal_amount), 0) FROM repayments r WHERE r.contract_id = lc.id) AS principal_paid
    FROM loan_contracts lc
    JOIN loan_applications a ON a.id = lc.loan_application_id
    JOIN cooperatives c ON c.id = lc.cooperative_id
    LEFT JOIN users u ON u.id = a.assigned_officer_id
    WHERE {$trackingScopeSql}
    ORDER BY FIELD(lc.status, 'active', 'pending_disbursement', 'signed', 'awaiting_signature', 'pending_review', 'draft', 'awaiting_preparation', 'completed', 'closed', 'cancelled'), COALESCE(lc.maturity_date, '9999-12-31'), lc.id DESC");
$query->execute($trackingScopeParameters);
$contractRows = $query->fetchAll();

$trackingSummary = ['total' => count($contractRows), 'preparation' => 0, 'active' => 0, 'overdue' => 0, 'outstanding' => 0.0];
foreach ($contractRows as $contractRow) {
    if (in_array($contractRow['status'], ['awaiting_preparation', 'draft', 'pending_review', 'awaiting_signature', 'signed', 'pending_disbursement'], true)) {
        $trackingSummary['preparation']++;
    }
    if ($contractRow['status'] === 'active') {
        $trackingSummary['active']++;
        $trackingSummary['outstanding'] += (float) $contractRow['outstanding_principal'];
    }
    $trackingSummary['overdue'] += (int) $contractRow['overdue_count'];
}

$selectedContractId = isset($_GET['contract']) && is_scalar($_GET['contract']) ? (int) $_GET['contract'] : 0;
$selectedContract = null;
foreach ($contractRows as $contractRow) {
    if ((int) $contractRow['id'] === $selectedContractId) {
        $selectedContract = $contractRow;
        break;
    }
}

$repaymentSchedules = [];
$repaymentRows = [];
$followUpRows = [];
$scheduleEdit = null;
$nextInstallmentNo = 1;
if ($selectedContract) {
    $query = $db->prepare('SELECT rs.*, (SELECT COALESCE(SUM(r.principal_amount), 0) FROM repayments r WHERE r.schedule_id = rs.id) AS principal_paid, (SELECT COALESCE(SUM(r.interest_amount), 0) FROM repayments r WHERE r.schedule_id = rs.id) AS interest_paid, (SELECT COALESCE(SUM(r.penalty_amount), 0) FROM repayments r WHERE r.schedule_id = rs.id) AS penalty_paid FROM repayment_schedules rs WHERE rs.contract_id = ? ORDER BY rs.installment_no, rs.due_date, rs.id');
    $query->execute([$selectedContract['id']]);
    $repaymentSchedules = $query->fetchAll();
    if ($repaymentSchedules) {
        $nextInstallmentNo = max(array_map(function ($row) { return (int) $row['installment_no']; }, $repaymentSchedules)) + 1;
    }
    $scheduleEditId = isset($_GET['schedule']) && is_scalar($_GET['schedule']) ? (int) $_GET['schedule'] : 0;
    foreach ($repaymentSchedules as $scheduleRow) {
        if ((int) $scheduleRow['id'] === $scheduleEditId && (float) $scheduleRow['principal_paid'] + (float) $scheduleRow['interest_paid'] + (float) $scheduleRow['penalty_paid'] <= 0) {
            $scheduleEdit = $scheduleRow;
            break;
        }
    }
    $query = $db->prepare('SELECT r.*, rs.installment_no, u.full_name AS recorder_name FROM repayments r LEFT JOIN repayment_schedules rs ON rs.id = r.schedule_id JOIN users u ON u.id = r.recorded_by WHERE r.contract_id = ? ORDER BY r.paid_at DESC, r.id DESC');
    $query->execute([$selectedContract['id']]);
    $repaymentRows = $query->fetchAll();
    if ($trackingSchemaReady) {
        $query = $db->prepare('SELECT f.*, rs.installment_no, u.full_name AS creator_name FROM contract_follow_ups f LEFT JOIN repayment_schedules rs ON rs.id = f.schedule_id JOIN users u ON u.id = f.created_by WHERE f.contract_id = ? ORDER BY f.followed_up_at DESC, f.id DESC');
        $query->execute([$selectedContract['id']]);
        $followUpRows = $query->fetchAll();
    }
}

$nowBangkok = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
$defaultDatetimeInput = $nowBangkok->format('Y-m-d\TH:i');
