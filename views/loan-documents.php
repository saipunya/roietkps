<?php
$loanStatuses = [
    'draft' => 'ฉบับร่าง',
    'submitted' => 'ส่งตรวจแล้ว',
    'promotion_review' => 'เจ้าหน้าที่ส่งเสริมกำลังตรวจ',
    'revision_requested' => 'ส่งกลับแก้ไข',
    'province_review' => 'รอจังหวัดตรวจสอบ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ไม่อนุมัติ',
    'cancelled' => 'ยกเลิก',
];
?>
<?php if ($loanNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($loanNotice) ?></div><?php endif; ?>
<?php if ($loanError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($loanError) ?></div><?php endif; ?>

<?php if ($selectedLoan): ?>
<?php
$isProvinceReview = $selectedLoan['status'] === 'province_review'
    && role_is_provincial($currentUser['role_code'])
    && account_can($currentUser, 'loan_applications.review_province');
$isPromotionReviewer = $currentUser['role_code'] === 'promotion_officer'
    && (int) $selectedLoan['assigned_officer_id'] === (int) $currentUser['id']
    && in_array($selectedLoan['status'], ['submitted', 'promotion_review'], true);
$documentsVerified = (int) $selectedLoan['current_document_count'] > 0
    && (int) $selectedLoan['current_document_count'] === (int) $selectedLoan['verified_document_count'];
?>
<section class="surface loan-detail-panel loan-document-workspace mb-4" aria-labelledby="loanDocumentWorkspaceTitle">
  <div class="surface-heading">
    <div><h2 id="loanDocumentWorkspaceTitle"><?= e($selectedLoan['application_no']) ?></h2><p><?= e($selectedLoan['organization_name']) ?> · จัดการเอกสารแยกจากข้อมูลคำขอกู้</p></div>
    <a class="text-link" href="dashboard.php?page=loan-documents">ปิด</a>
  </div>
  <div class="loan-detail-body">
    <?php require __DIR__ . '/loan-revision-note.php'; ?>
    <div class="loan-document-context">
      <div><span class="loan-document-context-label">สถานะคำขอกู้</span><strong><?= e($loanStatuses[$selectedLoan['status']] ?? $selectedLoan['status']) ?></strong></div>
      <div><span class="loan-document-context-label">วัตถุประสงค์</span><strong><?= e($selectedLoan['purpose']) ?></strong></div>
      <a class="btn <?= $isProvinceReview ? 'btn-primary' : 'btn-outline-primary' ?>" href="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>"><?= app_icon('document') ?> <?= $isProvinceReview ? 'ไปพิจารณาคำขอกู้' : 'ดูข้อมูลคำขอกู้' ?></a>
    </div>
    <?php require __DIR__ . '/loan-document-panel.php'; ?>
    <?php require __DIR__ . '/loan-post-approval-documents.php'; ?>
    <div class="form-actions loan-document-workspace-actions">
      <a class="btn btn-outline-primary" href="dashboard.php?page=loan-documents"><?= app_icon('folder') ?> กลับรายการเอกสาร</a>
      <?php if ($canSubmitSelectedLoan && !$canUploadLoanDocuments): ?><a class="btn btn-primary" href="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>"><?= app_icon('arrow') ?> ไปหน้าคำขอกู้เพื่อส่งตรวจ</a><?php endif; ?>
      <?php if ($isPromotionReviewer): ?><a class="btn btn-primary" href="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>"><?= app_icon('arrow') ?> <?= $documentsVerified ? 'ไปหน้าคำขอกู้เพื่อส่งให้จังหวัด' : 'ไปตรวจข้อมูลคำขอกู้' ?></a><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="surface records-section" aria-label="รายการเอกสารคำขอกู้">
  <div class="loan-list-heading"><div><strong>รายการเอกสารแยกตามคำขอกู้</strong><span><?= count($loanRows) ?> คำขอ</span></div></div>
  <?php if (!$loanRows): ?>
  <div class="empty-state"><?= app_icon('folder') ?><h2>ยังไม่มีคำขอกู้</h2><p>เมื่อมีคำขอกู้ รายการสำหรับจัดการเอกสารจะแสดงที่นี่</p></div>
  <?php else: ?>
  <div class="records-wrap"><table class="table records-table mb-0" id="recordsTable">
    <thead><tr><th>หน่วยงาน / เลขที่คำขอ</th><th>เอกสารปัจจุบัน</th><th>รอตรวจ</th><th>ต้องแก้ไข</th><th>ผ่านแล้ว</th><th data-no-export></th></tr></thead>
    <tbody>
      <?php foreach ($loanRows as $loan): ?>
      <?php
      $currentCount = (int) $loan['current_document_count'];
      $uploadedCount = (int) $loan['uploaded_document_count'];
      $revisionCount = (int) $loan['revision_document_count'];
      $verifiedCount = (int) $loan['verified_document_count'];
      $documentUrl = 'dashboard.php?page=loan-documents&loan=' . (int) $loan['id'];
      ?>
      <tr>
        <td class="record-primary">
          <a class="record-name-link" href="<?= e($documentUrl) ?>#loan-documents">
            <strong><?= e($loan['organization_name']) ?></strong>
            <small><?= e($loan['application_no']) ?> · <?= e($loanStatuses[$loan['status']] ?? $loan['status']) ?></small>
          </a>
        </td>
        <td data-label="เอกสารปัจจุบัน"><?= $currentCount ?> ฉบับ</td>
        <td data-label="รอตรวจ"><span class="status-badge <?= $uploadedCount > 0 ? 'status-warning' : 'status-neutral' ?>"><?= $uploadedCount ?> ฉบับ</span></td>
        <td data-label="ต้องแก้ไข"><span class="status-badge <?= $revisionCount > 0 ? 'status-danger' : 'status-neutral' ?>"><?= $revisionCount ?> ฉบับ</span></td>
        <td data-label="ผ่านแล้ว"><span class="status-badge <?= $verifiedCount > 0 ? 'status-success' : 'status-neutral' ?>"><?= $verifiedCount ?> ฉบับ</span></td>
        <td class="record-action" data-no-export><a class="btn record-button" href="<?= e($documentUrl) ?>">จัดการเอกสาร <?= app_icon('chevron') ?></a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
