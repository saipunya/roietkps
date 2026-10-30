<?php

require_once __DIR__ . '/loan-documents.php';

$loanError = '';
$loanNotice = isset($_SESSION['loan_notice']) ? (string) $_SESSION['loan_notice'] : '';
unset($_SESSION['loan_notice']);
$db = app_db();
$isLoanDocumentPage = isset($page) && $page === 'loan-documents';

function loan_scope(array $user): array
{
    if ($user['role_code'] === 'cooperative_user') {
        return ['a.cooperative_id = ?', [(int) $user['cooperative_id']]];
    }
    if ($user['role_code'] === 'provincial_officer') {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $loanError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            if ($operation === 'upload_loan_document') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $replacementId = filter_var(post_text('replaces_document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                loan_document_store($db, $currentUser, $applicationId, [
                    'document_type' => post_text('document_type'),
                    'title' => post_text('document_title'),
                    'replaces_document_id' => $replacementId,
                ], isset($_FILES['loan_document_file']) && is_array($_FILES['loan_document_file']) ? $_FILES['loan_document_file'] : []);
                $_SESSION['loan_notice'] = $replacementId > 0
                    ? 'อัปโหลดไฟล์ฉบับแก้ไขแล้ว เมื่อแก้ไขเอกสารครบให้กดส่งให้เจ้าหน้าที่ตรวจสอบอีกครั้ง'
                    : 'อัปโหลดเอกสารเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=loan-documents&loan=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'review_loan_document') {
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $documentId = filter_var(post_text('document_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $decision = post_text('document_decision');
                loan_document_review($db, $currentUser, $applicationId, $documentId, $decision, post_text('document_review_note'));
                $_SESSION['loan_notice'] = $decision === 'verified' ? 'บันทึกว่าเอกสารผ่านการตรวจแล้ว' : 'ส่งเอกสารกลับให้หน่วยงานแก้ไขแล้ว';
                header('Location: dashboard.php?page=loan-documents&loan=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'save_application') {
                if ($currentUser['role_code'] !== 'cooperative_user' || !account_can($currentUser, 'loan_applications.create')) {
                    throw new DomainException('คุณไม่มีสิทธิ์สร้างคำขอกู้');
                }
                $cooperativeId = (int) $currentUser['cooperative_id'];
                $profile = $db->prepare("SELECT profile_status FROM cooperatives WHERE id = ? AND status = 'active'");
                $profile->execute([$cooperativeId]);
                if ($profile->fetchColumn() !== 'complete') {
                    throw new DomainException('กรุณากรอกข้อมูลหน่วยงานให้ครบก่อนสร้างคำขอกู้');
                }
                $applicationId = filter_var(post_text('application_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $purpose = post_text('purpose');
                $requestedAmount = loan_money(post_text('requested_amount'), 'วงเงินที่ขอ');
                $interestRate = loan_rate(post_text('proposed_interest_rate'), 'อัตราดอกเบี้ย');
                $termMonths = filter_var(post_text('term_months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);
                $collateral = post_text('collateral');
                if (mb_strlen($purpose, 'UTF-8') < 5 || mb_strlen($purpose, 'UTF-8') > 500 || !$termMonths || mb_strlen($collateral, 'UTF-8') > 5000) {
                    throw new DomainException('กรุณาตรวจสอบวัตถุประสงค์ อัตราดอกเบี้ย และระยะเวลาชำระ');
                }
                $db->beginTransaction();
                if ($applicationId) {
                    $query = $db->prepare("SELECT id FROM loan_applications WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
                    $query->execute([$applicationId, $cooperativeId]);
                    if (!$query->fetchColumn()) {
                        throw new DomainException('รายการนี้ไม่สามารถแก้ไขได้');
                    }
                    $query = $db->prepare('UPDATE loan_applications SET purpose = ?, requested_amount = ?, proposed_interest_rate = ?, term_months = ?, collateral = ?, row_version = row_version + 1 WHERE id = ?');
                    $query->execute([$purpose, $requestedAmount, $interestRate, $termMonths, $collateral ?: null, $applicationId]);
                    $db->prepare('DELETE FROM loan_guarantors WHERE loan_application_id = ?')->execute([$applicationId]);
                } else {
                    $applicationNo = 'KPS-' . gmdate('Ym') . '-' . strtoupper(bin2hex(random_bytes(4)));
                    $query = $db->prepare("INSERT INTO loan_applications (application_no, cooperative_id, created_by, purpose, requested_amount, proposed_interest_rate, term_months, collateral, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                    $query->execute([$applicationNo, $cooperativeId, $currentUser['id'], $purpose, $requestedAmount, $interestRate, $termMonths, $collateral ?: null]);
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
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'loan.save_draft', 'loan_application', ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $applicationId, json_encode(['requested_amount' => $requestedAmount, 'term_months' => (int) $termMonths], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                $_SESSION['loan_notice'] = 'บันทึกคำขอกู้ฉบับร่างแล้ว';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'submit_application') {
                if ($currentUser['role_code'] !== 'cooperative_user' || !account_can($currentUser, 'loan_applications.submit')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ส่งคำขอกู้');
                }
                $applicationId = (int) post_text('application_id');
                $db->beginTransaction();
                $query = $db->prepare("SELECT status FROM loan_applications WHERE id = ? AND cooperative_id = ? AND status IN ('draft', 'revision_requested') FOR UPDATE");
                $query->execute([$applicationId, $currentUser['cooperative_id']]);
                $oldStatus = $query->fetchColumn();
                if (!$oldStatus) {
                    throw new DomainException('รายการนี้ไม่สามารถส่งตรวจได้');
                }
                $documents = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(d.status = 'needs_revision'), 0) AS needs_revision FROM loan_documents d WHERE d.loan_application_id = ? AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
                $documents->execute([$applicationId]);
                $documentSummary = $documents->fetch();
                if ((int) $documentSummary['total'] < 1) {
                    throw new DomainException('กรุณาอัปโหลดเอกสารประกอบคำขอกู้อย่างน้อย 1 ฉบับก่อนส่งตรวจ');
                }
                if ((int) $documentSummary['needs_revision'] > 0) {
                    throw new DomainException('กรุณาอัปโหลดเอกสารฉบับแก้ไขให้ครบก่อนส่งตรวจอีกครั้ง');
                }
                $query = $db->prepare("SELECT officer_user_id FROM organization_officer_assignments WHERE cooperative_id = ? AND is_primary = 1 AND (ends_on IS NULL OR ends_on >= CURRENT_DATE()) ORDER BY starts_on DESC LIMIT 1");
                $query->execute([$currentUser['cooperative_id']]);
                $officer = $query->fetchColumn() ?: null;
                $query = $db->prepare("UPDATE loan_applications SET status = 'submitted', assigned_officer_id = ?, submitted_at = UTC_TIMESTAMP(), review_round = review_round + IF(? = 'revision_requested', 1, 0), row_version = row_version + 1 WHERE id = ?");
                $query->execute([$officer, $oldStatus, $applicationId]);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'loan.submit', 'loan_application', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $applicationId, json_encode(['status' => $oldStatus]), json_encode(['status' => 'submitted']), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_loan_submitted($db, $applicationId, (int) $currentUser['id']);
                $db->commit();
                $_SESSION['loan_notice'] = $officer ? 'ส่งคำขอให้เจ้าหน้าที่ส่งเสริมตรวจสอบแล้ว' : 'ส่งคำขอแล้ว ขณะนี้รอจังหวัดมอบหมายเจ้าหน้าที่ส่งเสริม';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'promotion_review') {
                if ($currentUser['role_code'] !== 'promotion_officer' || !account_can($currentUser, 'loan_applications.review_promotion')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ตรวจรายการนี้');
                }
                $applicationId = (int) post_text('application_id');
                $decision = post_text('decision');
                $comment = post_text('review_comment');
                if (!in_array($decision, ['request_revision', 'forward'], true) || $comment === '' || mb_strlen($comment, 'UTF-8') > 2000) {
                    throw new DomainException('กรุณาเลือกผลการตรวจและระบุความเห็น');
                }
                $toStatus = $decision === 'forward' ? 'province_review' : 'revision_requested';
                $db->beginTransaction();
                $query = $db->prepare("SELECT status, review_round FROM loan_applications WHERE id = ? AND assigned_officer_id = ? AND status IN ('submitted', 'promotion_review') FOR UPDATE");
                $query->execute([$applicationId, $currentUser['id']]);
                $application = $query->fetch();
                if (!$application) {
                    throw new DomainException('รายการนี้ไม่อยู่ในขั้นตอนที่คุณตรวจสอบได้');
                }
                if ($decision === 'forward') {
                    $documents = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(d.status = 'verified'), 0) AS verified FROM loan_documents d WHERE d.loan_application_id = ? AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)");
                    $documents->execute([$applicationId]);
                    $documentSummary = $documents->fetch();
                    if ((int) $documentSummary['total'] < 1 || (int) $documentSummary['verified'] !== (int) $documentSummary['total']) {
                        throw new DomainException('กรุณาตรวจเอกสารฉบับปัจจุบันทุกฉบับให้ผ่านก่อนส่งให้จังหวัด');
                    }
                }
                $db->prepare('UPDATE loan_applications SET status = ?, promotion_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')->execute([$toStatus, $applicationId]);
                $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'promotion', ?, ?, ?, ?, ?)")->execute([$applicationId, $application['review_round'], $decision, $application['status'], $toStatus, $comment, $currentUser['id']]);
                tracking_promotion_reviewed($db, $applicationId, (int) $currentUser['id'], $decision, $comment);
                $db->commit();
                $_SESSION['loan_notice'] = $decision === 'forward' ? 'ส่งคำขอให้เจ้าหน้าที่จังหวัดตรวจสอบแล้ว' : 'ส่งรายการกลับให้หน่วยงานแก้ไขแล้ว';
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }

            if ($operation === 'province_review') {
                if ($currentUser['role_code'] !== 'provincial_officer' || !account_can($currentUser, 'loan_applications.review_province')) {
                    throw new DomainException('คุณไม่มีสิทธิ์ตรวจรายการนี้');
                }
                $applicationId = (int) post_text('application_id');
                $decision = post_text('decision');
                $comment = post_text('review_comment');
                if (!in_array($decision, ['request_revision', 'approve', 'reject'], true) || $comment === '' || mb_strlen($comment, 'UTF-8') > 2000) {
                    throw new DomainException('กรุณาเลือกผลการตรวจและระบุความเห็น');
                }
                $db->beginTransaction();
                $query = $db->prepare("SELECT a.*, c.province_id FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ? AND c.province_id = ? AND a.status = 'province_review' FOR UPDATE");
                $query->execute([$applicationId, $currentUser['province_id']]);
                $application = $query->fetch();
                if (!$application) {
                    throw new DomainException('รายการนี้ไม่อยู่ในขั้นตอนอนุมัติของจังหวัด');
                }
                $toStatus = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'revision_requested');
                if ($decision === 'approve') {
                    $approvedAmount = loan_money(post_text('approved_amount'), 'วงเงินอนุมัติ');
                    $approvedRate = loan_rate(post_text('approved_interest_rate'), 'อัตราดอกเบี้ยอนุมัติ');
                    $query = $db->prepare("UPDATE loan_applications SET status = 'approved', approved_amount = ?, approved_interest_rate = ?, province_reviewed_at = UTC_TIMESTAMP(), approved_by = ?, approved_at = UTC_TIMESTAMP(), locked_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?");
                    $query->execute([$approvedAmount, $approvedRate, $currentUser['id'], $applicationId]);
                    $query = $db->prepare("INSERT INTO loan_contracts (loan_application_id, cooperative_id, principal_amount, interest_rate, term_months, outstanding_principal, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'awaiting_preparation', ?)");
                    $query->execute([$applicationId, $application['cooperative_id'], $approvedAmount, $approvedRate, $application['term_months'], $approvedAmount, $currentUser['id']]);
                } else {
                    $db->prepare('UPDATE loan_applications SET status = ?, province_reviewed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?')->execute([$toStatus, $applicationId]);
                }
                $db->prepare("INSERT INTO loan_reviews (loan_application_id, review_round, review_stage, decision, from_status, to_status, comment, reviewed_by) VALUES (?, ?, 'province', ?, 'province_review', ?, ?, ?)")->execute([$applicationId, $application['review_round'], $decision, $toStatus, $comment, $currentUser['id']]);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, ?, 'loan_application', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], 'loan.' . $decision, $applicationId, json_encode(['status' => 'province_review']), json_encode(['status' => $toStatus]), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_province_reviewed($db, $applicationId, (int) $currentUser['id'], $decision, $comment);
                $db->commit();
                $_SESSION['loan_notice'] = $decision === 'approve' ? 'อนุมัติคำขอแล้ว ระบบสร้างรายการรอจัดทำสัญญาเรียบร้อย' : ($decision === 'reject' ? 'บันทึกการไม่อนุมัติแล้ว' : 'ส่งรายการกลับให้แก้ไขแล้ว');
                header('Location: dashboard.php?page=loans&view=' . $applicationId, true, 303);
                exit;
            }
        } catch (DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            $loanError = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}

[$loanScopeSql, $loanScopeParameters] = loan_scope($currentUser);
$query = $db->prepare("SELECT a.*, c.name AS organization_name, c.organization_type, u.full_name AS officer_name, lc.id AS contract_id, lc.status AS contract_status,
    COALESCE(document_stats.current_document_count, 0) AS current_document_count,
    COALESCE(document_stats.uploaded_document_count, 0) AS uploaded_document_count,
    COALESCE(document_stats.revision_document_count, 0) AS revision_document_count,
    COALESCE(document_stats.verified_document_count, 0) AS verified_document_count
    FROM loan_applications a
    JOIN cooperatives c ON c.id = a.cooperative_id
    LEFT JOIN users u ON u.id = a.assigned_officer_id
    LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id
    LEFT JOIN (
        SELECT d.loan_application_id, COUNT(*) AS current_document_count,
            SUM(d.status = 'uploaded') AS uploaded_document_count,
            SUM(d.status = 'needs_revision') AS revision_document_count,
            SUM(d.status = 'verified') AS verified_document_count
        FROM loan_documents d
        LEFT JOIN loan_documents newer ON newer.replaces_document_id = d.id
        WHERE newer.id IS NULL
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
$loanGuarantors = [];
$loanReviews = [];
$loanDocuments = [];
if ($selectedLoan) {
    $query = $db->prepare('SELECT * FROM loan_guarantors WHERE loan_application_id = ? ORDER BY id');
    $query->execute([$selectedLoan['id']]);
    $loanGuarantors = $query->fetchAll();
    $query = $db->prepare('SELECT r.*, u.full_name AS reviewer_name FROM loan_reviews r JOIN users u ON u.id = r.reviewed_by WHERE r.loan_application_id = ? ORDER BY r.created_at, r.id');
    $query->execute([$selectedLoan['id']]);
    $loanReviews = $query->fetchAll();
    $query = $db->prepare('SELECT d.*, uploader.full_name AS uploader_name, reviewer.full_name AS reviewer_name, EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id) AS has_newer FROM loan_documents d JOIN users uploader ON uploader.id = d.uploaded_by LEFT JOIN users reviewer ON reviewer.id = d.reviewed_by WHERE d.loan_application_id = ? ORDER BY d.created_at DESC, d.id DESC');
    $query->execute([$selectedLoan['id']]);
    $loanDocuments = $query->fetchAll();
}

$cooperativeProfileComplete = false;
if ($currentUser['role_code'] === 'cooperative_user') {
    $query = $db->prepare('SELECT profile_status FROM cooperatives WHERE id = ?');
    $query->execute([$currentUser['cooperative_id']]);
    $cooperativeProfileComplete = $query->fetchColumn() === 'complete';
}
