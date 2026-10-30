<?php
$loanDocumentTypes = loan_document_types();
$loanDocumentStatuses = loan_document_statuses();
$currentLoanDocuments = array_values(array_filter($loanDocuments, static function ($document) {
    return (int) $document['has_newer'] === 0;
}));
$hasPendingDocumentRevision = false;
foreach ($currentLoanDocuments as $loanDocument) {
    if ($loanDocument['status'] === 'needs_revision') {
        $hasPendingDocumentRevision = true;
        break;
    }
}
?>
<div class="loan-subsection loan-document-section">
  <div class="form-section-heading">
    <div><h3>เอกสารที่ส่งให้เจ้าหน้าที่ตรวจสอบ</h3><p>จัดการเฉพาะไฟล์เอกสารของคำขอนี้ โดยไม่แก้ไขข้อมูลคำขอกู้</p></div>
    <span class="loan-document-count"><?= count($currentLoanDocuments) ?> ฉบับปัจจุบัน</span>
  </div>

  <?php if ($currentUser['role_code'] === 'cooperative_user' && in_array($selectedLoan['status'], ['draft', 'revision_requested'], true)): ?>
  <?php if ($selectedLoan['status'] === 'revision_requested'): ?>
  <div class="alert <?= $hasPendingDocumentRevision ? 'alert-warning' : 'alert-success' ?> loan-document-revision-alert" role="status"><?= app_icon($hasPendingDocumentRevision ? 'alert' : 'check') ?><div><?php if ($hasPendingDocumentRevision): ?><strong>มีเอกสารที่ต้องแก้ไข</strong><p>กดปุ่ม “แก้ไขเอกสาร” ในรายการที่ถูกส่งกลับ แล้วอัปโหลดไฟล์ฉบับใหม่ให้ครบ</p><?php else: ?><strong>เอกสารฉบับแก้ไขพร้อมส่งตรวจ</strong><p>ตรวจสอบไฟล์ให้เรียบร้อย แล้วกลับไปหน้าคำขอกู้เพื่อส่งให้เจ้าหน้าที่ตรวจสอบอีกครั้ง</p><?php endif; ?></div></div>
  <?php endif; ?>
  <form class="loan-document-upload-form" action="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="upload_loan_document"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><input type="hidden" name="MAX_FILE_SIZE" value="20971520">
    <div class="loan-document-upload-grid">
      <div class="auth-field"><label class="form-label" for="loanDocumentType">ประเภทเอกสาร</label><select class="form-select" id="loanDocumentType" name="document_type" required><option value="">โปรดเลือก</option><?php foreach ($loanDocumentTypes as $typeCode => $typeLabel): ?><option value="<?= e($typeCode) ?>"><?= e($typeLabel) ?></option><?php endforeach; ?></select></div>
      <div class="auth-field"><label class="form-label" for="loanDocumentTitle">ชื่อเอกสาร</label><input class="form-control" type="text" id="loanDocumentTitle" name="document_title" minlength="3" maxlength="200" required></div>
      <div class="auth-field loan-document-file-field"><label class="form-label" for="loanDocumentFile">เลือกไฟล์</label><input class="form-control" type="file" id="loanDocumentFile" name="loan_document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" required><div class="form-text">PDF, Word, Excel, JPG, PNG หรือ ZIP ขนาดไม่เกิน 20 MB</div></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('upload') ?> อัปโหลดเอกสาร</button></div>
  </form>
  <?php endif; ?>

  <?php if (!$loanDocuments): ?>
  <div class="loan-document-empty"><?= app_icon('file') ?><p>ยังไม่มีเอกสารสำหรับคำขอนี้</p></div>
  <?php else: ?>
  <div class="loan-document-list">
    <?php foreach ($loanDocuments as $document): ?>
    <?php
    $documentIsPrevious = (int) $document['has_newer'] === 1;
    $documentStatusTone = $documentIsPrevious ? 'neutral' : ($document['status'] === 'verified' ? 'success' : ($document['status'] === 'needs_revision' ? 'danger' : 'warning'));
    $canReviseDocument = !$documentIsPrevious && $document['status'] === 'needs_revision' && $currentUser['role_code'] === 'cooperative_user' && $selectedLoan['status'] === 'revision_requested';
    ?>
    <article class="loan-document-row<?= $documentIsPrevious ? ' is-previous' : '' ?>">
      <span class="loan-document-file-icon" aria-hidden="true"><?= app_icon('file') ?></span>
      <div class="loan-document-copy">
        <div class="loan-document-title"><h4><?= e($document['title']) ?></h4><span class="status-badge status-<?= e($documentStatusTone) ?>"><?= $documentIsPrevious ? 'ฉบับก่อนหน้า' : e($loanDocumentStatuses[$document['status']] ?? $document['status']) ?></span></div>
        <p><?= e($loanDocumentTypes[$document['document_type']] ?? 'เอกสารอื่น ๆ') ?> · <?= e($document['original_name']) ?> · <?= e(loan_document_format_size((int) $document['file_size'])) ?></p>
        <small>อัปโหลดโดย <?= e($document['uploader_name']) ?> · <?= e(loan_document_date($document['created_at'])) ?></small>
        <?php if (!$documentIsPrevious && $document['review_note']): ?><div class="loan-document-review-note"><strong><?= $document['status'] === 'needs_revision' ? 'เหตุผลที่ส่งกลับ' : 'ความเห็นผู้ตรวจ' ?></strong><p><?= e($document['review_note']) ?></p><?php if ($document['reviewer_name']): ?><small><?= e($document['reviewer_name']) ?> · <?= e(loan_document_date($document['reviewed_at'])) ?></small><?php endif; ?></div><?php endif; ?>
      </div>
      <div class="loan-document-actions">
        <?php if ($canReviseDocument): ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#reviseLoanDocument<?= (int) $document['id'] ?>" aria-expanded="false" aria-controls="reviseLoanDocument<?= (int) $document['id'] ?>"><?= app_icon('upload') ?> แก้ไขเอกสาร</button><?php endif; ?>
        <a class="btn btn-outline-primary" href="loan-document-download.php?id=<?= (int) $document['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด</a>
      </div>
      <?php if ($canReviseDocument): ?>
      <form class="collapse loan-document-revision-form" id="reviseLoanDocument<?= (int) $document['id'] ?>" action="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="upload_loan_document"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><input type="hidden" name="replaces_document_id" value="<?= (int) $document['id'] ?>"><input type="hidden" name="MAX_FILE_SIZE" value="20971520">
        <div class="auth-field"><label class="form-label" for="revisedLoanDocumentFile<?= (int) $document['id'] ?>">เลือกไฟล์ฉบับแก้ไขของ “<?= e($document['title']) ?>”</label><input class="form-control" type="file" id="revisedLoanDocumentFile<?= (int) $document['id'] ?>" name="loan_document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" required><div class="form-text">ไฟล์เดิมจะถูกเก็บไว้เป็นประวัติ รองรับไฟล์ขนาดไม่เกิน 20 MB</div></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('upload') ?> อัปโหลดไฟล์ฉบับแก้ไข</button><button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#reviseLoanDocument<?= (int) $document['id'] ?>">ยกเลิก</button></div>
      </form>
      <?php endif; ?>
      <?php if (!$documentIsPrevious && $document['status'] === 'uploaded' && $currentUser['role_code'] === 'promotion_officer' && in_array($selectedLoan['status'], ['submitted', 'promotion_review'], true) && (int) $selectedLoan['assigned_officer_id'] === (int) $currentUser['id']): ?>
      <form class="loan-document-review-form" action="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="review_loan_document"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
        <label class="form-label" for="documentReviewNote<?= (int) $document['id'] ?>">ความเห็น/เหตุผลที่ต้องแก้ไข</label><textarea class="form-control" id="documentReviewNote<?= (int) $document['id'] ?>" name="document_review_note" rows="2" maxlength="1000"></textarea>
        <div class="form-actions"><button class="btn btn-outline-danger" type="submit" name="document_decision" value="needs_revision"><?= app_icon('alert') ?> ส่งกลับแก้ไข</button><button class="btn btn-primary" type="submit" name="document_decision" value="verified"><?= app_icon('check') ?> ผ่านการตรวจ</button></div>
      </form>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
