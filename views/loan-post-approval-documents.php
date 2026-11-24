<?php if ($canViewPostApprovalDocuments): ?>
<?php
$postApprovalKinds = loan_post_approval_document_kinds();
$postApprovalUrl = ($isLoanDocumentPage ? 'dashboard.php?page=loan-documents&loan=' : 'dashboard.php?page=loans&view=')
    . (int) $selectedLoan['id'];
$postApprovalKind = $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'upload_post_approval_documents'
    ? post_text('document_kind') : 'contract';
?>
<section id="loan-post-approval-documents" class="loan-subsection" aria-labelledby="postApprovalDocumentTitle">
  <h3 id="postApprovalDocumentTitle">สัญญาและเอกสารหลังอนุมัติ</h3>
  <p class="form-text mb-3">ไฟล์ที่แนบกับคำขอนี้จะเก็บไว้ให้เปิดดูย้อนหลังได้</p>
  <?php if ($canUploadPostApprovalDocuments): ?>
    <?php $postApprovalLimits = loan_document_batch_limits(); ?>
    <form class="loan-document-upload-form" action="<?= e($postApprovalUrl) ?>" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="operation" value="upload_post_approval_documents">
      <input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= $postApprovalLimits['file'] ?>">
      <div class="auth-field">
        <label class="form-label" for="postApprovalDocumentKind">ประเภทไฟล์ที่จะแนบ</label>
        <select class="form-select" id="postApprovalDocumentKind" name="document_kind" required>
          <?php foreach ($postApprovalKinds as $kind => $label): ?>
          <option value="<?= e($kind) ?>"<?= $postApprovalKind === $kind ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="auth-field mt-3">
        <label class="form-label" for="postApprovalFiles">เลือกไฟล์ของคำขอนี้</label>
        <input class="form-control" type="file" id="postApprovalFiles" name="post_approval_files[]"
          accept="<?= e('.' . implode(',.', array_keys(loan_document_allowed_files()))) ?>"
          aria-describedby="postApprovalFileHelp"<?= $postApprovalLimits['count'] > 1 ? ' multiple' : '' ?> required>
        <div class="form-text" id="postApprovalFileHelp">เลือกได้สูงสุด <?= $postApprovalLimits['count'] ?> ไฟล์ต่อครั้ง ไม่เกิน <?= e(loan_document_format_size($postApprovalLimits['file'])) ?> ต่อไฟล์ และ <?= e(loan_document_format_size($postApprovalLimits['total'])) ?> รวมทั้งชุด</div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('upload') ?> แนบเอกสารหลังอนุมัติ</button></div>
    </form>
  <?php endif; ?>

  <?php if ($postApprovalDocuments): ?>
  <div class="loan-document-list">
    <?php foreach ($postApprovalDocuments as $postApprovalDocument): ?>
    <article class="loan-document-row">
      <span class="loan-document-file-icon" aria-hidden="true"><?= app_icon('file') ?></span>
      <div class="loan-document-copy">
        <div class="loan-document-title"><h4><?= e($postApprovalDocument['original_name']) ?></h4></div>
        <p><?= e($postApprovalKinds[$postApprovalDocument['document_kind']]) ?> · <?= e(loan_document_format_size((int) $postApprovalDocument['file_size'])) ?></p>
        <small>แนบโดย <?= e($postApprovalDocument['uploader_name']) ?> · <?= e(loan_document_date($postApprovalDocument['created_at'])) ?></small>
      </div>
      <div class="loan-document-actions"><a class="btn btn-outline-primary" href="loan-document-download.php?post_approval=<?= (int) $postApprovalDocument['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด</a></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="loan-document-empty"><?= app_icon('file') ?><p>ยังไม่มีไฟล์สัญญาหรือเอกสารหลังอนุมัติ</p></div>
  <?php endif; ?>

  <?php if ($selectedLoan['contract_id']): ?>
  <div class="mt-3"><a class="text-link" href="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedLoan['contract_id'] ?>">ดูข้อมูลติดตามสัญญา <?= app_icon('arrow') ?></a></div>
  <?php endif; ?>
</section>
<?php endif; ?>
