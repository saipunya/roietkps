<?php

require_once __DIR__ . '/loan-documents.php';
require_once __DIR__ . '/loan-conversations.php';
require_once __DIR__ . '/loan-status.php';

$loanError = '';
$provinceReviewStale = false;
$loanNotice = isset($_SESSION['loan_notice']) ? (string) $_SESSION['loan_notice'] : '';
unset($_SESSION['loan_notice']);
$db = app_db();
$isLoanDocumentPage = isset($page) && $page === 'loan-documents';
$adminLoanEditor = account_is_admin($currentUser);

function loan_scope(array $user): array
{
    if ($user['role_code'] === 'cooperative_user') {
        return ['a.cooperative_id = ?', [(int) $user['cooperative_id']]];
    }
    if (role_is_provincial($user['role_code'])) {
        return ['c.province_id = ?', [(int) $user['province_id']]];
    }
    if ($user['role_code'] === 'promotion_officer') {
        return ["(a.assigned_officer_id = ? OR EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = a.cooperative_id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE())))", [(int) $user['id'], (int) $user['id']]];
    }
    return ['1 = 1', []];
}

function loan_money(string $value, string $label): float
{
    if (!preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $value) || (float) $value <= 0) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return (float) $value;
}

function loan_rate(string $value, string $label): float
{
    if (!preg_match('/\A\d{1,3}(?:\.\d{1,4})?\z/', $value) || (float) $value < 0 || (float) $value > 100) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return (float) $value;
}

function loan_date(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Bangkok'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new DomainException('กรุณากรอก' . $label . 'ให้ถูกต้อง');
    }
    return $value;
}

function loan_display_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('d/m/Y') : $value;
}

function loan_display_datetime(?string $utcValue): string
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

// The caller owns the transaction, including any newly uploaded files.
function loan_submit_for_review(PDO $db, array $actor, int $applicationId, ?string $message = null): bool
{
    if ($actor['role_code'] !== 'cooperative_user' || !account_can($actor, 'loan_applications.submit')) {
        throw new DomainException('คุณไม่มีสิทธิ์ส่งคำขอกู้');
    }
    $query = $db->prepare("SELECT status, review_round, document_draft_message FROM loan_applications
        WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
    $query->execute([$applicationId, $actor['cooperative_id']]);
    $loan = $query->fetch();
    if (!$loan) {
        throw new DomainException('รายการนี้ไม่อยู่ในขั้นตอนที่ส่งตรวจได้');
    }
    loan_require_cooperative_revision_turn($db, $applicationId, $loan);
    $documents = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(d.status = 'needs_revision'), 0) AS needs_revision
        FROM loan_documents d WHERE d.loan_application_id = ?
        AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
    $documents->execute([$applicationId]);
    $summary = $documents->fetch();
    if ((int) $summary['total'] < 1) {
        throw new DomainException('กรุณาแนบเอกสารประกอบอย่างน้อย 1 ไฟล์ก่อนส่งตรวจ');
    }
    if ((int) $summary['needs_revision'] > 0) {
        throw new DomainException('กรุณาแนบไฟล์ฉบับแก้ไขให้ครบก่อนส่งตรวจอีกครั้ง');
    }
    $query = $db->prepare("SELECT officer_user_id FROM organization_officer_assignments
        WHERE cooperative_id = ? AND is_primary = 1 AND (ends_on IS NULL OR ends_on >= CURRENT_DATE())
        ORDER BY starts_on DESC, id DESC LIMIT 1");
    $query->execute([$actor['cooperative_id']]);
    $officer = $query->fetchColumn() ?: null;
    $query = $db->prepare("UPDATE loan_applications SET status = 'submitted', assigned_officer_id = ?, submitted_at = UTC_TIMESTAMP(),
        document_draft_message = NULL, review_round = review_round + IF(? = 'revision_requested', 1, 0), row_version = row_version + 1 WHERE id = ?");
    $query->execute([$officer, $loan['status'], $applicationId]);
    $db->prepare('UPDATE loan_documents SET is_draft = 0 WHERE loan_application_id = ? AND is_draft = 1')->execute([$applicationId]);
    $message = $message ?? (string) $loan['document_draft_message'];
    if ($message !== '') {
        loan_conversation_post($db, $actor, $applicationId, 'cooperative', bin2hex(random_bytes(16)), $message, false);
    }
    $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash)
        VALUES (?, 'loan.submit', 'loan_application', ?, ?, ?, ?)");
    $audit->execute([(int) $actor['id'], $applicationId, json_encode(['status' => $loan['status']]), json_encode(['status' => 'submitted']), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
    tracking_loan_submitted($db, $applicationId, (int) $actor['id']);
    return $officer !== null;
}

function loan_submission_notice(bool $assigned): string
{
    return $assigned ? 'ส่งคำขอให้เจ้าหน้าที่ส่งเสริมตรวจสอบแล้ว' : 'ส่งคำขอแล้ว ขณะนี้รอจังหวัดมอบหมายเจ้าหน้าที่ส่งเสริม';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postLimit = loan_document_ini_bytes('post_max_size');
    if ($postLimit > 0 && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimit) {
        http_response_code(413);
        $loanError = 'ขนาดไฟล์รวมเกินที่เซิร์ฟเวอร์รับได้ กรุณาแบ่งอัปโหลดเป็นชุดเล็กลง';
    } elseif (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $loanError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $storedReviewAttachments = [];
        $storedDocumentFiles = [];
        $reviewAttachmentFiles = $_FILES['review_attachments'] ?? [];
        try {
            $operation = post_text('operation');
            if ($operation === 'post_loan_message') {
                throw new DomainException('กรุณาส่งข้อความพร้อมเอกสารจากหน้าจัดการเอกสารของคำขอนี้');
            }
            if ($operation === 'unlock_approved_application') {
                if (!account_is_admin($currentUser) || !account_can($currentUser, 'records.unlock')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ปลดล็อกคำขอที่อนุมัติแล้ว');
                }
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $reason = post_text('unlock_reason');
                if (!$applicationId || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 1000) {
                    throw new DomainException('กรุณาระบุเหตุผลการปลดล็อก 5–1,000 ตัวอักษร');
                }
                $db->beginTransaction();
                $query = $db->prepare("SELECT a.id, a.review_round, a.status, a.admin_unlocked_at, lc.id AS contract_id, lc.status AS contract_status, (SELECT COUNT(*) FROM repayment_schedules rs WHERE rs.contract_id = lc.id) AS schedule_count, (SELECT COUNT(*) FROM repayments rp WHERE rp.contract_id = lc.id AND rp.voided_at IS NULL) AS repayment_count FROM loan_applications a LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id WHERE a.id = ? FOR UPDATE");
                $query->execute([$applicationId]);
                $application = $query->fetch();
                if (!$application || $application['status'] !== 'approved' || $application['admin_unlocked_at'] !== null) {
                    throw new DomainException('คำขอนี้ไม่อยู่ในสถานะที่ปลดล็อกได้');
                }
                if ($application['contract_id'] && (!in_array($application['contract_status'], ['awaiting_preparation', 'draft'], true)
                    || (int) $application['schedule_count'] > 0 || (int) $application['repayment_count'] > 0)) {
                    throw new DomainException('ไม่สามารถปลดล็อกได้ เนื่องจากสัญญาเข้าสู่กระบวนการถัดไปแล้ว');
                }
                $db->prepare('UPDATE loan_applications SET admin_unlocked_at = UTC_TIMESTAMP(), admin_unlocked_by = ?, admin_unlock_reason = ?, row_version = row_version + 1 WHERE id = ?')->execute([(int) $currentUser['id'], $reason, $applicationId]);
                $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'admin', 'unlock', 'approved', 'approved', ?, ?)")->execute([$applicationId, $application['review_round'], $reason, (int) $currentUser['id']]);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'loan.admin_unlock', 'loan_application', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $applicationId, json_encode(['locked' => true]), json_encode(['locked' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                $_SESSION['loan_notice'] = 'ปลดล็อกคำขอแล้ว กรุณาแก้ไขและบันทึกเพื่อให้ระบบล็อกข้อมูลอีกครั้ง';
                header('Location: dashboard.php?page=loans&edit=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'relock_approved_application') {
                if (!account_is_admin($currentUser) || !account_can($currentUser, 'records.unlock')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ล็อกคำขอนี้');
                }
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $db->beginTransaction();
                $query = $db->prepare("SELECT id, admin_unlocked_by FROM loan_applications WHERE id = ? AND status = 'approved' AND admin_unlocked_at IS NOT NULL FOR UPDATE");
                $query->execute([$applicationId]);
                $application = $query->fetch();
                if (!$application || (int) $application['admin_unlocked_by'] !== (int) $currentUser['id']) {
                    throw new DomainException('ไม่พบคำขอที่คุณปลดล็อกไว้');
                }
                $db->prepare('UPDATE loan_applications SET admin_unlocked_at = NULL, admin_unlocked_by = NULL, admin_unlock_reason = NULL, locked_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')->execute([$applicationId]);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'loan.admin_relock', 'loan_application', ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $applicationId, json_encode(['locked' => true], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                $_SESSION['loan_notice'] = 'ล็อกคำขออีกครั้งแล้ว โดยไม่มีการเปลี่ยนข้อมูล';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'upload_post_approval_documents') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $db->beginTransaction();
                $uploadedCount = loan_post_approval_store($db, $currentUser, $applicationId, post_text('document_kind'),
                    $_FILES['post_approval_files'] ?? [], $storedDocumentFiles);
                $db->commit();
                $_SESSION['loan_notice'] = $uploadedCount > 0 ? 'บันทึกเอกสารหลังอนุมัติแล้ว ' . $uploadedCount . ' ไฟล์' : 'ไฟล์ที่เลือกมีอยู่ในรายการแล้ว';
                $returnUrl = $isLoanDocumentPage ? 'dashboard.php?page=loan-documents&loan=' : 'dashboard.php?page=loans&view=';
                header('Location: ' . $returnUrl . $applicationId . '#loan-post-approval-documents', true, 303);
                exit;
            }

            if ($operation === 'upload_loan_document') {
                if ($currentUser['role_code'] !== 'cooperative_user' || !account_can($currentUser, 'documents.upload')) {
                    throw new DomainException('คุณไม่มีสิทธิ์อัปโหลดเอกสารคำขอกู้');
                }
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $replacementId = filter_var(post_text('replaces_document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $sendForReview = post_text('document_action') === 'submit';
                $documentMessage = post_text('document_message');
                if ($sendForReview && $documentMessage === '') {
                    throw new DomainException('กรุณาเขียนข้อความถึงเจ้าหน้าที่ส่งเสริมก่อนส่งตรวจ');
                }
                if (!mb_check_encoding($documentMessage, 'UTF-8') || mb_strlen($documentMessage, 'UTF-8') > 1700) {
                    throw new DomainException('กรุณาตรวจสอบข้อความถึงเจ้าหน้าที่ส่งเสริม ความยาวสูงสุด 1,700 ตัวอักษร');
                }
                $files = $_FILES['loan_document_file'] ?? [];
                $uploads = loan_document_prepare_batch($files, (int) post_text('document_file_count'), $replacementId === 0);
                $db->beginTransaction();
                $query = $db->prepare("SELECT status, review_round, row_version FROM loan_applications
                    WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
                $query->execute([$applicationId, $currentUser['cooperative_id']]);
                $application = $query->fetch();
                if (!$application) {
                    throw new DomainException('คำขอนี้ไม่อยู่ในขั้นตอนที่บันทึกร่างหรือส่งตรวจได้');
                }
                loan_require_cooperative_revision_turn($db, $applicationId, $application);
                $expectedVersion = post_text('row_version');
                if ($expectedVersion !== '' && $expectedVersion !== (string) $application['row_version']) {
                    throw new DomainException('คำขอมีข้อมูลใหม่ กรุณาตรวจรายการและเลือกไฟล์อีกครั้งก่อนดำเนินการ');
                }
                if ($uploads) {
                    loan_documents_store($db, $currentUser, $applicationId, [
                        'document_type' => post_text('document_type'),
                        'title' => count($uploads) === 1 ? post_text('document_title') : '',
                        'replaces_document_id' => $replacementId,
                    ], $uploads, $storedDocumentFiles);
                }
                if ($sendForReview) {
                    $notice = loan_submission_notice(loan_submit_for_review($db, $currentUser, $applicationId, $documentMessage));
                } else {
                    $db->prepare('UPDATE loan_applications SET document_draft_message = ?, row_version = row_version + 1 WHERE id = ?')
                        ->execute([$documentMessage !== '' ? $documentMessage : null, $applicationId]);
                    $notice = 'บันทึกร่างแล้ว คุณกลับมาแก้ข้อความหรือจัดการไฟล์ก่อนส่งตรวจได้';
                }
                $db->commit();
                $_SESSION['loan_notice'] = $notice;
                $returnUrl = $isLoanDocumentPage ? 'dashboard.php?page=loan-documents&loan=' : 'dashboard.php?page=loans&view=';
                header('Location: ' . $returnUrl . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'delete_draft_loan_document') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                loan_document_delete_draft($db, $currentUser, $applicationId, $documentId);
                $_SESSION['loan_notice'] = 'ลบไฟล์ฉบับร่างแล้ว';
                $returnUrl = $isLoanDocumentPage ? 'dashboard.php?page=loan-documents&loan=' : 'dashboard.php?page=loans&view=';
                header('Location: ' . $returnUrl . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'review_loan_document') {
                throw new DomainException('ระบบตรวจเอกสารเป็นชุดแล้ว กรุณาเปิดหน้าเอกสารคำขอกู้ใหม่');
            }

            if ($operation === 'review_loan_document_set') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $decision = post_text('document_decision');
                $db->beginTransaction();
                loan_document_review_set($db, $currentUser, $applicationId, post_text('row_version'), $decision,
                    post_text('document_review_note'), $reviewAttachmentFiles, $storedReviewAttachments);
                $db->commit();
                $_SESSION['loan_notice'] = $decision === 'verified'
                    ? 'บันทึกผลว่าชุดเอกสารผ่านการตรวจแล้ว'
                    : 'ส่งคำขอกลับให้สหกรณ์แก้ไขพร้อมผลตรวจชุดเอกสารแล้ว';
                header('Location: dashboard.php?page=loan-documents&loan=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'clear_test_loan_documents') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $result = loan_document_admin_clear(
                    $db,
                    $currentUser,
                    $applicationId,
                    post_text('clear_confirmation'),
                    post_text('clear_reason')
                );
                $_SESSION['loan_notice'] = 'ล้างข้อมูลการส่งเอกสาร ' . number_format((int) $result['documents']) . ' ฉบับแล้ว และคืนสถานะคำขอเป็นฉบับร่าง';
                if ((int) $result['files_failed'] > 0) {
                    $_SESSION['loan_notice'] .= ' (มีไฟล์ตกค้าง ' . number_format((int) $result['files_failed']) . ' ไฟล์ กรุณาตรวจสอบสิทธิ์โฟลเดอร์จัดเก็บ)';
                }
                header('Location: dashboard.php?page=loan-documents&loan=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'cancel_application') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                loan_application_cancel(
                    $db,
                    $currentUser,
                    $applicationId,
                    post_text('cancellation_confirmation'),
                    post_text('cancellation_reason')
                );
                $_SESSION['loan_notice'] = 'ยกเลิกคำขอกู้แล้ว ระบบเก็บเหตุผลและประวัติการดำเนินการเรียบร้อย';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'save_application') {
                $cooperativeLoanEditor = $currentUser['role_code'] === 'cooperative_user' && account_can($currentUser, 'loan_applications.create');
                $promotionLoanEditor = loan_promotion_is_reviewer($currentUser);
                if (!$adminLoanEditor && !$cooperativeLoanEditor && !$promotionLoanEditor) {
                    throw new DomainException('คุณไม่มีสิทธิ์แก้ไขข้อมูลคำขอกู้');
                }
                $editingReturnedApplication = false;
                $editableApplication = null;
                $approvedAdminEdit = false;
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                if ($promotionLoanEditor) {
                    $db->beginTransaction();
                    $editableApplication = loan_promotion_application_for_update($db, $applicationId, (int) $currentUser['id']);
                    if (!$editableApplication || $editableApplication['status'] !== 'revision_requested' || !loan_promotion_can_handle_return(
                        $currentUser,
                        $editableApplication,
                        loan_latest_revision_stage($db, $applicationId, (int) $editableApplication['review_round'])
                    )) {
                        throw new DomainException('แก้ไขได้เฉพาะคำขอที่จังหวัดส่งกลับและคุณรับผิดชอบ');
                    }
                    $cooperativeId = (int) $editableApplication['cooperative_id'];
                } else {
                    $cooperativeId = $adminLoanEditor
                        ? (filter_var(post_text('cooperative_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
                        : (int) $currentUser['cooperative_id'];
                }
                $profile = $db->prepare("SELECT profile_status, province_id FROM cooperatives WHERE id = ? AND status = 'active'");
                $profile->execute([$cooperativeId]);
                $organization = $profile->fetch();
                if (!$organization) {
                    throw new DomainException('กรุณาเลือกสหกรณ์หรือกลุ่มเกษตรกร');
                }
                if ($organization['profile_status'] !== 'complete') {
                    throw new DomainException('กรุณากรอกข้อมูลหน่วยงานให้ครบก่อนสร้างคำขอกู้');
                }
                $purpose = post_text('purpose');
                $requestedAmount = loan_money(post_text('requested_amount'), 'วงเงินที่ขอ');
                $interestRate = loan_rate(post_text('proposed_interest_rate'), 'อัตราดอกเบี้ย');
                $termMonths = filter_var(post_text('term_months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);
                $repaymentDueDate = loan_date(post_text('repayment_due_date'), 'วันครบกำหนดชำระ');
                $collateral = post_text('collateral');
                if (mb_strlen($purpose, 'UTF-8') < 5 || mb_strlen($purpose, 'UTF-8') > 500 || !$termMonths || mb_strlen($collateral, 'UTF-8') > 5000) {
                    throw new DomainException('กรุณาตรวจสอบวัตถุประสงค์ อัตราดอกเบี้ย และระยะเวลาชำระ');
                }
                $assignedOfficerId = $adminLoanEditor
                    ? (filter_var(post_text('assigned_officer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null)
                    : null;
                if ($promotionLoanEditor) {
                    $assignedOfficerId = (int) $editableApplication['assigned_officer_id'];
                } elseif ($assignedOfficerId) {
                    $officer = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.province_id = ? AND u.status = 'active' AND r.code = 'promotion_officer'");
                    $officer->execute([$assignedOfficerId, $organization['province_id']]);
                    if (!$officer->fetchColumn()) {
                        throw new DomainException('เจ้าหน้าที่ส่งเสริมที่เลือกไม่พร้อมใช้งานหรืออยู่นอกพื้นที่');
                    }
                } else {
                    $officer = $db->prepare("SELECT officer_user_id FROM organization_officer_assignments WHERE cooperative_id = ? AND is_primary = 1 AND (ends_on IS NULL OR ends_on >= CURRENT_DATE()) ORDER BY starts_on DESC LIMIT 1");
                    $officer->execute([$cooperativeId]);
                    $assignedOfficerId = $officer->fetchColumn() ?: null;
                }
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                }
                if ($applicationId) {
                    if ($adminLoanEditor) {
                        $query = $db->prepare("SELECT status, cooperative_id, row_version FROM loan_applications WHERE id = ? AND (status IN ('draft', 'revision_requested') OR (status = 'approved' AND admin_unlocked_at IS NOT NULL AND admin_unlocked_by = ?)) FOR UPDATE");
                        $query->execute([$applicationId, $currentUser['id']]);
                        $editableApplication = $query->fetch();
                    } elseif ($cooperativeLoanEditor) {
                        $query = $db->prepare("SELECT status, review_round, cooperative_id, row_version FROM loan_applications WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
                        $query->execute([$applicationId, $cooperativeId]);
                        $editableApplication = $query->fetch();
                    }
                    if (!$editableApplication) {
                        throw new DomainException('รายการนี้ไม่สามารถแก้ไขได้');
                    }
                    if ($cooperativeLoanEditor) {
                        loan_require_cooperative_revision_turn($db, $applicationId, $editableApplication);
                    }
                    $approvedAdminEdit = $editableApplication['status'] === 'approved';
                    $editingReturnedApplication = $editableApplication['status'] === 'revision_requested';
                    $expectedVersion = post_text('row_version');
                    if (($promotionLoanEditor || $expectedVersion !== '') && $expectedVersion !== (string) $editableApplication['row_version']) {
                        throw new DomainException('คำขอมีข้อมูลใหม่ กรุณาเปิดคำขออีกครั้งก่อนบันทึก');
                    }
                    if ($approvedAdminEdit && (int) $editableApplication['cooperative_id'] !== $cooperativeId) {
                        throw new DomainException('คำขอที่อนุมัติแล้วไม่สามารถเปลี่ยนหน่วยงานได้');
                    }
                    if ($approvedAdminEdit) {
                        $approvedAmount = loan_money(post_text('approved_amount'), 'วงเงินอนุมัติ');
                        $approvedRate = loan_rate(post_text('approved_interest_rate'), 'อัตราดอกเบี้ยอนุมัติ');
                        $contractQuery = $db->prepare("SELECT id, status, start_date FROM loan_contracts WHERE loan_application_id = ? FOR UPDATE");
                        $contractQuery->execute([$applicationId]);
                        $contract = $contractQuery->fetch();
                        if ($contract && !in_array($contract['status'], ['awaiting_preparation', 'draft'], true)) {
                            throw new DomainException('สัญญาเข้าสู่กระบวนการถัดไปแล้ว ไม่สามารถแก้ไขคำขอได้');
                        }
                        if (!empty($contract['start_date']) && $repaymentDueDate <= $contract['start_date']) {
                            throw new DomainException('วันครบกำหนดชำระต้องอยู่หลังวันที่เริ่มสัญญา');
                        }
                        $query = $db->prepare('UPDATE loan_applications SET assigned_officer_id = ?, purpose = ?, requested_amount = ?, proposed_interest_rate = ?, approved_amount = ?, approved_interest_rate = ?, term_months = ?, repayment_due_date = ?, collateral = ?, admin_unlocked_at = NULL, admin_unlocked_by = NULL, admin_unlock_reason = NULL, locked_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?');
                        $query->execute([$assignedOfficerId, $purpose, $requestedAmount, $interestRate, $approvedAmount, $approvedRate, $termMonths, $repaymentDueDate, $collateral ?: null, $applicationId]);
                        if ($contract) {
                            $db->prepare('UPDATE loan_contracts SET principal_amount = ?, interest_rate = ?, term_months = ?, maturity_date = ?, outstanding_principal = ? WHERE id = ?')
                                ->execute([$approvedAmount, $approvedRate, $termMonths, $repaymentDueDate, $approvedAmount, $contract['id']]);
                        }
                    } else {
                        $query = $db->prepare('UPDATE loan_applications SET cooperative_id = ?, assigned_officer_id = ?, purpose = ?, requested_amount = ?, proposed_interest_rate = ?, term_months = ?, repayment_due_date = ?, collateral = ?, row_version = row_version + 1 WHERE id = ?');
                        $query->execute([$cooperativeId, $assignedOfficerId, $purpose, $requestedAmount, $interestRate, $termMonths, $repaymentDueDate, $collateral ?: null, $applicationId]);
                    }
                    $db->prepare('DELETE FROM loan_guarantors WHERE loan_application_id = ?')->execute([$applicationId]);
                } else {
                    $applicationNo = 'KPS-' . gmdate('Ym') . '-' . strtoupper(bin2hex(random_bytes(4)));
                    $query = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, assigned_officer_id, purpose, requested_amount, proposed_interest_rate, term_months, repayment_due_date, collateral, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                    $query->execute([$applicationNo, $cooperativeId, $currentUser['id'], $assignedOfficerId, $purpose, $requestedAmount, $interestRate, $termMonths, $repaymentDueDate, $collateral ?: null]);
                    $applicationId = (int) $db->lastInsertId();
                }
                $names = isset($_POST['guarantor_name']) && is_array($_POST['guarantor_name']) ? $_POST['guarantor_name'] : [];
                $relationships = isset($_POST['guarantor_relationship']) && is_array($_POST['guarantor_relationship']) ? $_POST['guarantor_relationship'] : [];
                $phones = isset($_POST['guarantor_phone']) && is_array($_POST['guarantor_phone']) ? $_POST['guarantor_phone'] : [];
                $amounts = isset($_POST['guarantor_amount']) && is_array($_POST['guarantor_amount']) ? $_POST['guarantor_amount'] : [];
                $insertGuarantor = $db->prepare('INSERT INTO loan_guarantors (loan_application_id, full_name, relationship, phone, guarantee_amount) VALUES (?, ?, ?, ?, ?)');
                foreach ($names as $index => $rawName) {
                    if (!is_string($rawName)) {
                        continue;
                    }
                    $name = trim($rawName);
                    if ($name === '') {
                        continue;
                    }
                    $relationship = isset($relationships[$index]) && is_string($relationships[$index]) ? trim($relationships[$index]) : '';
                    $phone = isset($phones[$index]) && is_string($phones[$index]) ? trim($phones[$index]) : '';
                    $amount = isset($amounts[$index]) && is_string($amounts[$index]) && $amounts[$index] !== '' ? loan_money(trim($amounts[$index]), 'วงเงินค้ำประกัน') : null;
                    if (mb_strlen($name, 'UTF-8') > 200 || mb_strlen($relationship, 'UTF-8') > 100 || strlen($phone) > 30) {
                        throw new DomainException('ข้อมูลผู้ค้ำประกันยาวเกินกำหนด');
                    }
                    $insertGuarantor->execute([$applicationId, $name, $relationship ?: null, $phone ?: null, $amount]);
                }
                $auditAction = $approvedAdminEdit ? 'loan.admin_update_approved' : ($adminLoanEditor ? 'loan.admin_save_draft' : 'loan.save_draft');
                $auditValues = ['cooperative_id' => $cooperativeId, 'assigned_officer_id' => $assignedOfficerId, 'requested_amount' => $requestedAmount,
                    'approved_amount' => $approvedAmount ?? null, 'term_months' => (int) $termMonths, 'repayment_due_date' => $repaymentDueDate];
                $auditOldValues = null;
                if ($promotionLoanEditor) {
                    $auditAction = 'loan.promotion_save_revision';
                    $auditValues += ['purpose' => $purpose, 'proposed_interest_rate' => $interestRate, 'collateral' => $collateral ?: null];
                    $auditOldValues = json_encode(array_intersect_key($editableApplication, $auditValues), JSON_UNESCAPED_UNICODE);
                }
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, 'loan_application', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $auditAction, $applicationId, $auditOldValues, json_encode($auditValues, JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                if (!empty($approvedAdminEdit)) {
                    $_SESSION['loan_notice'] = 'บันทึกการแก้ไขและล็อกคำขอที่อนุมัติแล้วอีกครั้ง';
                } elseif ($editingReturnedApplication) {
                    $_SESSION['loan_notice'] = 'บันทึกข้อมูลคำขอที่แก้ไขแล้ว';
                } else {
                    $_SESSION['loan_notice'] = $adminLoanEditor ? 'Admin บันทึกข้อมูลคำขอกู้ฉบับร่างแล้ว' : 'บันทึกคำขอกู้ฉบับร่างแล้ว';
                }
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'submit_application') {
                $applicationId = (int) post_text('application_id');
                $db->beginTransaction();
                $assigned = loan_submit_for_review($db, $currentUser, $applicationId);
                $db->commit();
                $_SESSION['loan_notice'] = loan_submission_notice($assigned);
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'promotion_review') {
                if (!loan_promotion_is_reviewer($currentUser)) {
                    throw new DomainException('คุณไม่มีสิทธิ์ตรวจรายการนี้');
                }
                $applicationId = (int) post_text('application_id');
                $decision = post_text('decision');
                $comment = post_text('review_comment');
                if (!in_array($decision, ['request_revision', 'forward'], true) || mb_strlen($comment, 'UTF-8') > 2000) {
                    throw new DomainException('กรุณาเลือกผลการตรวจและระบุความเห็น');
                }
                $toStatus = $decision === 'forward' ? 'province_review' : 'revision_requested';
                $db->beginTransaction();
                $application = loan_promotion_application_for_update($db, $applicationId, (int) $currentUser['id']);
                $handlingReturn = $application && $application['status'] === 'revision_requested' && loan_promotion_can_handle_return(
                    $currentUser,
                    $application,
                    loan_latest_revision_stage($db, $applicationId, (int) $application['review_round'])
                );
                if (!$application || (!$handlingReturn && !in_array($application['status'], ['submitted', 'promotion_review'], true))) {
                    throw new DomainException('รายการนี้ไม่อยู่ในขั้นตอนที่คุณตรวจสอบได้');
                }
                $expectedVersion = post_text('row_version');
                if (($handlingReturn || $expectedVersion !== '') && $expectedVersion !== (string) $application['row_version']) {
                    throw new DomainException('คำขอมีข้อมูลใหม่ กรุณาเปิดคำขออีกครั้งก่อนส่ง');
                }
                $reviewRound = (int) $application['review_round'];
                $documentSetReview = $decision === 'forward' && $comment === '' && !$handlingReturn
                    ? loan_document_set_review_for_round($db, $applicationId, $reviewRound)
                    : null;
                $attachments = loan_review_attachment_uploads($reviewAttachmentFiles);
                if ($comment === '') {
                    if ($attachments) {
                        $comment = 'รายละเอียดอยู่ในไฟล์แนบ';
                    } elseif (!$documentSetReview) {
                        throw new DomainException('กรุณาเขียนความเห็นหรือแนบไฟล์ประกอบ');
                    }
                }
                if ($decision === 'forward') {
                    $documents = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(d.status = 'verified'), 0) AS verified FROM loan_documents d WHERE d.loan_application_id = ? AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
                    $documents->execute([$applicationId]);
                    $documentSummary = $documents->fetch();
                    if ((int) $documentSummary['total'] < 1 || (int) $documentSummary['verified'] !== (int) $documentSummary['total']) {
                        throw new DomainException('กรุณาบันทึกผลตรวจชุดเอกสารให้ผ่านก่อนส่งให้จังหวัด');
                    }
                }
                if ($handlingReturn && $decision === 'forward') {
                    $reviewRound++;
                }
                $db->prepare('UPDATE loan_applications SET status = ?, review_round = ?, promotion_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')->execute([$toStatus, $reviewRound, $applicationId]);
                $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'promotion', ?, ?, ?, ?, ?)")->execute([$applicationId, $reviewRound, $decision, $application['status'], $toStatus, $comment !== '' ? $comment : null, $currentUser['id']]);
                loan_review_save_attachments($db, (int) $db->lastInsertId(), $attachments, $storedReviewAttachments);
                tracking_promotion_reviewed($db, $applicationId, (int) $currentUser['id'], $decision, $comment);
                $db->commit();
                $_SESSION['loan_notice'] = $decision === 'forward' ? 'ส่งคำขอให้เจ้าหน้าที่จังหวัดตรวจสอบแล้ว' : 'ส่งรายการกลับให้หน่วยงานแก้ไขแล้ว';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'province_review') {
                if (!role_is_provincial($currentUser['role_code']) || !account_can($currentUser, 'loan_applications.review_province')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ตรวจรายการนี้');
                }
                $applicationId = (int) post_text('application_id');
                $decision = post_text('decision');
                $comment = post_text('review_comment');
                if (!in_array($decision, ['request_revision', 'approve', 'reject'], true)
                    || !mb_check_encoding($comment, 'UTF-8') || mb_strlen($comment, 'UTF-8') > 2000) {
                    throw new DomainException('กรุณาเลือกผลการตรวจและระบุความเห็น');
                }
                $db->beginTransaction();
                $query = $db->prepare("SELECT a.*, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ? AND c.province_id = ? AND a.status = 'province_review' FOR UPDATE");
                $query->execute([$applicationId, $currentUser['province_id']]);
                $application = $query->fetch();
                if (!$application) {
                    throw new DomainException('รายการนี้ไม่อยู่ในขั้นตอนอนุมัติของจังหวัด');
                }
                if (post_text('row_version') !== (string) $application['row_version']) {
                    $provinceReviewStale = true;
                    throw new DomainException('คำขอมีข้อมูลใหม่ ระบบยังไม่ได้บันทึกผล กรุณาตรวจเอกสารฉบับปัจจุบันและลงผลรอบล่าสุดอีกครั้ง');
                }
                $attachments = loan_review_attachment_uploads($reviewAttachmentFiles);
                if ($comment === '') {
                    if ($decision !== 'request_revision' || !$attachments) {
                        throw new DomainException($decision === 'request_revision'
                            ? 'กรุณาเขียนคำแนะนำหรือแนบไฟล์สำหรับส่งกลับแก้ไข'
                            : 'กรุณาระบุความเห็นประกอบผลพิจารณาของจังหวัด');
                    }
                    $comment = 'รายละเอียดการแก้ไขอยู่ในไฟล์แนบ';
                }
                $toStatus = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'revision_requested');
                if ($decision === 'approve') {
                    $approvedAmount = loan_money(post_text('approved_amount'), 'วงเงินอนุมัติ');
                    $approvedRate = loan_rate(post_text('approved_interest_rate'), 'อัตราดอกเบี้ยอนุมัติ');
                    $query = $db->prepare("UPDATE loan_applications SET status = 'approved', approved_amount = ?, approved_interest_rate = ?, province_reviewed_at = UTC_TIMESTAMP(), approved_by = ?, approved_at = UTC_TIMESTAMP(), locked_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?");
                    $query->execute([$approvedAmount, $approvedRate, $currentUser['id'], $applicationId]);
                    $archivedDocumentCount = loan_document_archive_superseded_versions($db, $applicationId, (int) $currentUser['id']);
                } else {
                    $archivedDocumentCount = 0;
                    $db->prepare('UPDATE loan_applications SET status = ?, province_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')->execute([$toStatus, $applicationId]);
                }
                $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'province', ?, 'province_review', ?, ?, ?)")->execute([$applicationId, $application['review_round'], $decision, $toStatus, $comment, $currentUser['id']]);
                loan_review_save_attachments($db, (int) $db->lastInsertId(), $attachments, $storedReviewAttachments);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, 'loan_application', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], 'loan.' . $decision, $applicationId, json_encode(['status' => 'province_review']), json_encode(['status' => $toStatus, 'archived_document_count' => $archivedDocumentCount], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_province_reviewed($db, $applicationId, (int) $currentUser['id'], $decision, $comment);
                $db->commit();
                $_SESSION['loan_notice'] = $decision === 'approve' ? 'อนุมัติคำขอกู้แล้ว สามารถแนบสัญญาและเอกสารประกอบหลังอนุมัติได้' : ($decision === 'reject' ? 'บันทึกการไม่อนุมัติแล้ว' : 'ส่งคำขอกลับให้เจ้าหน้าที่ส่งเสริมแก้ไขแล้ว');
                header('Location: dashboard.php?page=loans&view=' . $applicationId . ($decision === 'approve' ? '#loan-post-approval-documents' : ''), true, 303);
                exit;
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($storedReviewAttachments || $storedDocumentFiles) {
                loan_document_remove_stored_files(array_merge($storedReviewAttachments, $storedDocumentFiles));
            }
            if (!$exception instanceof DomainException) {
                throw $exception;
            }
            http_response_code(422);
            $loanError = $exception->getMessage();
        }
    }
}

[$loanScopeSql, $loanScopeParameters] = loan_scope($currentUser);
$showDraftDocuments = loan_document_can_view_drafts($currentUser);
$visibleDocumentScope = $showDraftDocuments ? '' : ' AND d.is_draft = 0';
$visibleSuccessorScope = $showDraftDocuments ? '' : ' AND newer.is_draft = 0';
$query = $db->prepare("SELECT a.*, c.name AS organization_name, c.organization_type, c.province_id AS organization_province_id,
    p.name AS organization_province_name,
    COALESCE(u.full_name, primary_officer.full_name) AS officer_name,
    CASE
        WHEN a.assigned_officer_id IS NOT NULL THEN 'loan'
        WHEN primary_officer.id IS NOT NULL THEN 'organization_default'
        ELSE 'unassigned'
    END AS officer_assignment_source,
    canceller.full_name AS cancelled_by_name,
    lc.id AS contract_id, lc.status AS contract_status,
    COALESCE(document_stats.current_document_count, 0) AS current_document_count,
    COALESCE(document_stats.draft_document_count, 0) AS draft_document_count,
    COALESCE(document_stats.uploaded_document_count, 0) AS uploaded_document_count,
    COALESCE(document_stats.revision_document_count, 0) AS revision_document_count,
    COALESCE(document_stats.verified_document_count, 0) AS verified_document_count
    FROM loan_applications a
    JOIN cooperatives c ON c.id = a.cooperative_id
    JOIN provinces p ON p.id = c.province_id
    LEFT JOIN users u ON u.id = a.assigned_officer_id
    LEFT JOIN users primary_officer ON primary_officer.id = (
        SELECT oa.officer_user_id
        FROM organization_officer_assignments oa
        WHERE oa.cooperative_id = a.cooperative_id
          AND oa.is_primary = 1
          AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE())
        ORDER BY oa.starts_on DESC, oa.id DESC
        LIMIT 1
    )
    LEFT JOIN users canceller ON canceller.id = a.cancelled_by
    LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id
    LEFT JOIN (
        SELECT d.loan_application_id, COUNT(*) AS current_document_count,
            SUM(d.is_draft = 1) AS draft_document_count,
            SUM(d.status = 'uploaded' AND d.is_draft = 0) AS uploaded_document_count,
            SUM(d.status = 'needs_revision') AS revision_document_count,
            SUM(d.status = 'verified') AS verified_document_count
        FROM loan_documents d
        LEFT JOIN loan_documents newer ON newer.replaces_document_id = d.id{$visibleSuccessorScope}
        WHERE newer.id IS NULL{$visibleDocumentScope}
        GROUP BY d.loan_application_id
    ) document_stats ON document_stats.loan_application_id = a.id
    WHERE {$loanScopeSql} ORDER BY a.updated_at DESC, a.id DESC");
$query->execute($loanScopeParameters);
$loanRows = $query->fetchAll();

$loanViewId = $isLoanDocumentPage
    ? (isset($_GET['loan']) && is_scalar($_GET['loan']) ? (int) $_GET['loan'] : 0)
    : (isset($_GET['view']) && is_scalar($_GET['view']) ? (int) $_GET['view'] : 0);
$loanEditId = !$isLoanDocumentPage && isset($_GET['edit']) && is_scalar($_GET['edit']) ? (int) $_GET['edit'] : 0;
$newLoan = !$isLoanDocumentPage && isset($_GET['action']) && $_GET['action'] === 'new';
$selectedLoan = null;
foreach ($loanRows as $row) {
    if ((int) $row['id'] === $loanViewId || (int) $row['id'] === $loanEditId) {
        $selectedLoan = $row;
        break;
    }
}
$canCancelSelectedLoan = $selectedLoan ? loan_application_can_cancel($currentUser, $selectedLoan) : false;
$loanIsEditable = $selectedLoan && in_array($selectedLoan['status'], ['draft', 'revision_requested'], true);
$isApplicantOrganization = $selectedLoan && $currentUser['role_code'] === 'cooperative_user'
    && (int) $currentUser['cooperative_id'] === (int) $selectedLoan['cooperative_id'];
$canEditSelectedLoan = $loanIsEditable && ($adminLoanEditor || ($isApplicantOrganization && account_can($currentUser, 'loan_applications.create')));
$canUploadLoanDocuments = $loanIsEditable && $isApplicantOrganization && account_can($currentUser, 'documents.upload');
$canSubmitSelectedLoan = $loanIsEditable && $isApplicantOrganization && account_can($currentUser, 'loan_applications.submit');
$loanGuarantors = [];
$loanReviews = [];
$loanReviewAttachments = [];
$loanDocumentSetReview = null;
$latestProvinceForward = null;
$loanConversationChannels = [];
$loanConversationMessages = [];
$latestRevision = null;
$provinceRevisionForRound = null;
$waitingForPromotionRevision = false;
$canPromotionHandleReturnedLoan = false;
$loanDocuments = [];
$postApprovalDocuments = [];
$canViewPostApprovalDocuments = false;
$canUploadPostApprovalDocuments = false;
$loanDocumentClearState = ['allowed' => false, 'document_count' => 0, 'message' => ''];
if ($selectedLoan) {
    $postApprovalContext = $selectedLoan + ['province_id' => $selectedLoan['organization_province_id']];
    $canViewPostApprovalDocuments = $selectedLoan['status'] === 'approved' && account_can($currentUser, 'contracts.view')
        && loan_document_user_can_access($db, $currentUser, $postApprovalContext);
    $canUploadPostApprovalDocuments = $canViewPostApprovalDocuments && loan_post_approval_can_upload($currentUser, $postApprovalContext)
        && $selectedLoan['admin_unlocked_at'] === null;
    if ($canViewPostApprovalDocuments) {
        $query = $db->prepare('SELECT d.id, d.document_kind, d.original_name, d.file_size, d.created_at, u.full_name AS uploader_name
            FROM loan_post_approval_documents d JOIN users u ON u.id = d.uploaded_by
            WHERE d.loan_application_id = ? ORDER BY d.created_at DESC, d.id DESC');
        $query->execute([$selectedLoan['id']]);
        $postApprovalDocuments = $query->fetchAll();
    }
    if (!$isLoanDocumentPage && $loanViewId) {
        $loanConversationChannels = loan_conversation_channels($currentUser, $selectedLoan);
        $loanConversationMessages = loan_conversation_messages($db, (int) $selectedLoan['id'], $loanConversationChannels);
    }
    $query = $db->prepare('SELECT * FROM loan_guarantors WHERE loan_application_id = ? ORDER BY id');
    $query->execute([$selectedLoan['id']]);
    $loanGuarantors = $query->fetchAll();
    $query = $db->prepare('SELECT r.*, u.full_name AS reviewer_name FROM loan_reviews r JOIN users u ON u.id = r.reviewed_by WHERE r.loan_application_id = ? ORDER BY r.created_at, r.id');
    $query->execute([$selectedLoan['id']]);
    $loanReviews = $query->fetchAll();
    if ($loanReviews) {
        $loanReviewAttachments = loan_review_attachments_by_application($db, (int) $selectedLoan['id']);
        $loanDocumentSetReview = loan_document_set_review_for_round($db, (int) $selectedLoan['id'], (int) $selectedLoan['review_round']);
        if ($selectedLoan['status'] === 'province_review') {
            foreach (array_reverse($loanReviews) as $review) {
                if ((int) $review['review_round'] === (int) $selectedLoan['review_round']
                    && $review['review_stage'] === 'promotion' && $review['decision'] === 'forward'
                    && $review['to_status'] === 'province_review') {
                    $latestProvinceForward = $review;
                    break;
                }
            }
        }
    }
    if ($selectedLoan['status'] === 'revision_requested') {
        foreach (array_reverse($loanReviews) as $review) {
            if ($review['decision'] === 'request_revision' && (int) $review['review_round'] === (int) $selectedLoan['review_round']) {
                $latestRevision ??= $review;
                if ($review['review_stage'] === 'province') {
                    $provinceRevisionForRound = $review;
                    break;
                }
            }
        }
        $canPromotionHandleReturnedLoan = loan_promotion_can_handle_return($currentUser, $selectedLoan, $latestRevision['review_stage'] ?? null);
        $canEditSelectedLoan = $canEditSelectedLoan || $canPromotionHandleReturnedLoan;
        $waitingForPromotionRevision = ($latestRevision['review_stage'] ?? null) === 'province';
        if ($waitingForPromotionRevision && $isApplicantOrganization) {
            $canEditSelectedLoan = false;
            $canUploadLoanDocuments = false;
            $canSubmitSelectedLoan = false;
        }
    }
    $archiveScope = loan_document_archive_schema_ready($db) ? ' AND d.archived_at IS NULL' : '';
    $query = $db->prepare("SELECT d.*, uploader.full_name AS uploader_name, reviewer.full_name AS reviewer_name,
        EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id{$visibleSuccessorScope}) AS has_newer
        FROM loan_documents d JOIN users uploader ON uploader.id = d.uploaded_by LEFT JOIN users reviewer ON reviewer.id = d.reviewed_by
        WHERE d.loan_application_id = ?{$archiveScope}{$visibleDocumentScope} ORDER BY d.created_at DESC, d.id DESC");
    $query->execute([$selectedLoan['id']]);
    $loanDocuments = $query->fetchAll();
    if (account_is_admin($currentUser) && $loanDocuments) {
        $loanDocumentClearState = loan_document_admin_clear_state($db, (int) $selectedLoan['id']);
    }
}

$cooperativeProfileComplete = false;
if ($currentUser['role_code'] === 'cooperative_user') {
    $query = $db->prepare('SELECT profile_status FROM cooperatives WHERE id = ?');
    $query->execute([$currentUser['cooperative_id']]);
    $cooperativeProfileComplete = $query->fetchColumn() === 'complete';
}

$loanOrganizations = [];
$loanPromotionOfficers = [];
if ($adminLoanEditor && !$isLoanDocumentPage) {
    $loanOrganizations = $db->query("SELECT id, name, province_id FROM cooperatives WHERE status = 'active' AND profile_status = 'complete' ORDER BY name")->fetchAll();
    $loanPromotionOfficers = $db->query("SELECT u.id, u.full_name, u.province_id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND r.code = 'promotion_officer' ORDER BY u.full_name")->fetchAll();
}
