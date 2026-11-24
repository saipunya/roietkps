<?php
$loanDocumentTypes = loan_document_types();
$loanDocumentStatuses = loan_document_statuses();
$loanDocumentUploadUrl = ($isLoanDocumentPage ? 'dashboard.php?page=loan-documents&loan=' : 'dashboard.php?page=loans&view=') . (int) $selectedLoan['id'];
$documentPost = $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'upload_loan_document'
    && (int) post_text('application_id') === (int) $selectedLoan['id'];
$postedReplacementId = $documentPost ? (int) post_text('replaces_document_id') : 0;
$documentUploadPost = $documentPost && $postedReplacementId === 0;
$draftDocumentMessage = (string) $selectedLoan['document_draft_message'];
$sendDocumentLabel = $selectedLoan['status'] === 'revision_requested' ? 'ส่งให้เจ้าหน้าที่ตรวจสอบอีกครั้ง' : 'ส่งให้เจ้าหน้าที่ตรวจสอบ';
$canReviewDocumentSet = (int) $selectedLoan['current_document_count'] > 0
    && loan_document_can_review_set($currentUser, $selectedLoan) && !$loanDocumentSetReview;
$isProvinceDocumentReview = $selectedLoan['status'] === 'province_review'
    && role_is_provincial($currentUser['role_code']) && account_can($currentUser, 'loan_applications.review_province');
$documentsForDisplay = $loanDocuments;
$previousProvinceDocuments = [];
if ($isProvinceDocumentReview) {
    $documentsForDisplay = array_filter($loanDocuments, static function (array $document): bool {
        return (int) $document['has_newer'] === 0;
    });
    $previousProvinceDocuments = array_diff_key($loanDocuments, $documentsForDisplay);
}
?>
<div id="loan-documents" class="loan-subsection loan-document-section">
  <?php if ($isProvinceDocumentReview): ?>
  <section class="border rounded-3 p-3 mb-3" aria-labelledby="provinceHandoffTitle">
    <h3 id="provinceHandoffTitle">ข้อมูลที่ส่งให้จังหวัดรอบล่าสุด</h3>
    <p class="form-text mt-1 mb-2">
      รอบที่ <?= (int) $selectedLoan['review_round'] ?>
      <?php if ($latestProvinceForward): ?>
        · ส่งโดย <?= e($latestProvinceForward['reviewer_name']) ?> · <?= e(loan_document_date($latestProvinceForward['created_at'])) ?>
      <?php endif; ?>
    </p>
    <?php if ($latestProvinceForward): ?>
      <?php $handoffNotes = $loanDocumentSetReview ? [$loanDocumentSetReview, $latestProvinceForward] : [$latestProvinceForward]; ?>
      <?php foreach ($handoffNotes as $handoffNote): ?>
        <?php
        $attachmentReviewId = (int) $handoffNote['id'];
        $handoffComment = (string) ($handoffNote['comment'] ?? '');
        ?>
        <?php if ($handoffComment !== '' || !empty($loanReviewAttachments[$attachmentReviewId])): ?>
          <div class="mt-2">
            <strong><?= $handoffNote['decision'] === 'forward' ? 'ความเห็นประกอบการส่งต่อ' : 'ความเห็นจากการตรวจชุดเอกสาร' ?></strong>
            <?php if ($handoffComment !== ''): ?><p class="mb-1 text-break"><?= nl2br(e($handoffComment)) ?></p><?php endif; ?>
            <?php require __DIR__ . '/loan-review-attachments.php'; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="form-text mb-0">ยังไม่พบรายละเอียดการส่งต่อของรอบนี้ สามารถดูรายการเอกสารปัจจุบันด้านล่าง</p>
    <?php endif; ?>
    <?php if ($loanReviews): ?>
      <a class="text-link small" href="<?= $isLoanDocumentPage ? 'dashboard.php?page=loans&amp;view=' . (int) $selectedLoan['id'] : '' ?>#loan-review-history">ดูประวัติการตรวจสอบทั้งหมด</a>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <div class="form-section-heading">
    <div>
      <h3><?= $isProvinceDocumentReview ? 'เอกสารฉบับปัจจุบันสำหรับรอบนี้' : 'เอกสารประกอบคำขอและข้อความแจ้งเจ้าหน้าที่' ?></h3>
      <p class="mb-0"><?= $isProvinceDocumentReview
          ? 'ดาวน์โหลดไฟล์เพื่อพิจารณาได้ทุกฉบับในรายการนี้ รวมถึงไฟล์จากรอบก่อนที่ยังใช้อยู่'
          : 'ทุกไฟล์ที่ส่งตรวจและข้อความประกอบจะถูกเก็บในประวัติคำขอนี้' ?></p>
    </div>
    <span class="loan-document-count"><?= (int) $selectedLoan['current_document_count'] ?> ฉบับปัจจุบัน</span>
  </div>

  <?php if ($canUploadLoanDocuments): ?>
  <p class="form-text mt-0">บันทึกร่างเพื่อกลับมาแก้ข้อความหรือลบไฟล์ได้ เจ้าหน้าที่จะได้รับเอกสารและข้อความเมื่อกดส่งตรวจ</p>
  <?php endif; ?>

  <?php if ($canUploadLoanDocuments): ?>
  <?php if ($selectedLoan['status'] === 'revision_requested'): ?>
  <?php if ((int) $selectedLoan['revision_document_count'] > 0): ?>
  <div class="alert alert-warning loan-document-revision-alert" role="status"><?= app_icon('alert') ?><div><strong>มีเอกสารที่ต้องแก้ไข</strong><p>กด “แก้ไขเอกสาร” ที่ไฟล์เดิมเพื่อแนบฉบับใหม่ ถ้าต้องแก้หลายรายการ ให้ทยอยบันทึกร่างแล้วกดส่งตรวจเมื่อแนบครบ</p></div></div>
  <?php elseif ((int) $selectedLoan['current_document_count'] > 0): ?>
  <p class="form-text">หากต้องแก้ไขไฟล์ตามความเห็นของเจ้าหน้าที่ ให้ดาวน์โหลดไฟล์เดิม แล้วกด “แก้ไขเอกสาร” เพื่ออัปโหลดฉบับใหม่</p>
  <?php endif; ?>
  <?php endif; ?>
  <?php $documentLimits = loan_document_batch_limits(); ?>
  <form class="loan-document-upload-form" action="<?= e($loanDocumentUploadUrl) ?>" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="operation" value="upload_loan_document">
    <input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>">
    <input type="hidden" name="row_version" value="<?= (int) $selectedLoan['row_version'] ?>">
    <input type="hidden" name="document_file_count" value="0">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $documentLimits['file'] ?>">
    <div class="loan-document-upload-grid">
      <div class="auth-field loan-document-file-field">
        <label class="form-label" for="loanDocumentFile">เลือกไฟล์เอกสารประกอบ</label>
        <input class="form-control" type="file" id="loanDocumentFile" name="loan_document_file[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
          data-max-files="<?= $documentLimits['count'] ?>" data-max-file-size="<?= $documentLimits['file'] ?>" data-max-total-size="<?= $documentLimits['total'] ?>"
          aria-describedby="loanDocumentFileHelp loanDocumentFileCount"<?= $documentLimits['count'] > 1 ? ' multiple' : '' ?>>
        <div class="form-text" id="loanDocumentFileHelp">PDF, Word, Excel, JPG, PNG หรือ ZIP เลือกได้สูงสุด <?= $documentLimits['count'] ?> ไฟล์ต่อครั้ง ขนาดไม่เกิน <?= e(loan_document_format_size($documentLimits['file'])) ?> ต่อไฟล์ และ <?= e(loan_document_format_size($documentLimits['total'])) ?> รวมทั้งชุด</div>
        <div class="form-text" id="loanDocumentFileCount" role="status"></div>
      </div>
      <div class="auth-field">
        <label class="form-label" for="loanDocumentType">ประเภทเอกสารของชุดนี้</label>
        <select class="form-select" id="loanDocumentType" name="document_type" required>
          <?php $selectedDocumentType = $documentUploadPost ? post_text('document_type') : 'other'; ?>
          <?php foreach ($loanDocumentTypes as $typeCode => $typeLabel): ?>
          <option value="<?= e($typeCode) ?>"<?= $selectedDocumentType === $typeCode ? ' selected' : '' ?>><?= e($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">หากเลือกหลายประเภทพร้อมกัน ให้ใช้ “เอกสารอื่น ๆ”</div>
      </div>
      <div class="auth-field" id="loanDocumentTitleField">
        <label class="form-label" for="loanDocumentTitle">ชื่อเอกสาร (ระบุได้เมื่อเลือกไฟล์เดียว)</label>
        <input class="form-control" type="text" id="loanDocumentTitle" name="document_title" minlength="3" maxlength="200" value="<?= e($documentUploadPost ? mb_substr(post_text('document_title'), 0, 200, 'UTF-8') : '') ?>">
        <div class="form-text">หากเว้นว่าง ระบบจะใช้ชื่อไฟล์</div>
      </div>
    </div>
    <div class="auth-field mt-3">
      <label class="form-label" for="loanDocumentMessage">ข้อความถึงเจ้าหน้าที่ส่งเสริมสำหรับไฟล์ชุดนี้</label>
      <textarea class="form-control" id="loanDocumentMessage" name="document_message" rows="3" maxlength="1700" aria-describedby="loanDocumentMessageHelp"><?= e($documentUploadPost ? mb_substr(post_text('document_message'), 0, 1700, 'UTF-8') : $draftDocumentMessage) ?></textarea>
      <div class="form-text" id="loanDocumentMessageHelp">ส่งตรวจได้เมื่อมีข้อความและเอกสารอย่างน้อย 1 ไฟล์ ใช้ไฟล์ที่บันทึกไว้แล้วได้</div>
    </div>
    <div class="form-actions loan-draft-actions">
      <button class="btn btn-outline-primary" type="submit" name="document_action" value="draft">บันทึกร่าง</button>
      <?php if ($canSubmitSelectedLoan): ?>
      <button class="btn btn-primary" type="submit" name="document_action" value="submit"><?= app_icon('arrow') ?> <?= e($sendDocumentLabel) ?></button>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>

  <?php if (!$documentsForDisplay): ?>
  <div class="loan-document-empty"><?= app_icon('file') ?><p>ยังไม่มีเอกสารสำหรับคำขอนี้</p></div>
  <?php else: ?>
  <div class="loan-document-list">
    <?php foreach ($documentsForDisplay as $document): ?>
    <?php
    $documentIsPrevious = (int) $document['has_newer'] === 1;
    $documentIsDraft = (bool) $document['is_draft'] || $selectedLoan['status'] === 'draft';
    $documentStatusTone = $documentIsPrevious || $documentIsDraft ? 'neutral' : ($document['status'] === 'verified' ? 'success' : ($document['status'] === 'needs_revision' ? 'danger' : 'warning'));
    $documentStatusLabel = $documentIsPrevious ? 'ฉบับก่อนหน้า' : ($documentIsDraft ? 'ฉบับร่าง' : ($loanDocumentStatuses[$document['status']] ?? $document['status']));
    $canReviseDocument = !$documentIsPrevious && !$documentIsDraft && $canUploadLoanDocuments && $selectedLoan['status'] === 'revision_requested';
    $canDeleteDraftDocument = !$documentIsPrevious && $canUploadLoanDocuments && $documentIsDraft && $document['status'] === 'uploaded';
    $revisionUploadPost = $documentPost && $postedReplacementId === (int) $document['id'];
    ?>
    <article class="loan-document-row<?= $documentIsPrevious ? ' is-previous' : '' ?>">
      <span class="loan-document-file-icon" aria-hidden="true"><?= app_icon('file') ?></span>
      <div class="loan-document-copy">
        <div class="loan-document-title"><h4><?= e($document['title']) ?></h4><span class="status-badge status-<?= e($documentStatusTone) ?>"><?= e($documentStatusLabel) ?></span></div>
        <p><?= e($loanDocumentTypes[$document['document_type']] ?? 'เอกสารอื่น ๆ') ?> · <?= e($document['original_name']) ?> · <?= e(loan_document_format_size((int) $document['file_size'])) ?></p>
        <small>อัปโหลดโดย <?= e($document['uploader_name']) ?> · <?= e(loan_document_date($document['created_at'])) ?></small>
        <?php if (!$documentIsPrevious && $document['review_note']): ?><div class="loan-document-review-note"><strong><?= $document['status'] === 'needs_revision' ? 'เหตุผลที่ส่งกลับ' : 'ความเห็นผู้ตรวจ' ?></strong><p><?= e($document['review_note']) ?></p><?php if ($document['reviewer_name']): ?><small><?= e($document['reviewer_name']) ?> · <?= e(loan_document_date($document['reviewed_at'])) ?></small><?php endif; ?></div><?php endif; ?>
      </div>
      <div class="loan-document-actions">
        <?php if ($canReviseDocument): ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#reviseLoanDocument<?= (int) $document['id'] ?>" aria-expanded="<?= $revisionUploadPost ? 'true' : 'false' ?>" aria-controls="reviseLoanDocument<?= (int) $document['id'] ?>"><?= app_icon('upload') ?> แก้ไขเอกสาร</button><?php endif; ?>
        <a class="btn btn-outline-primary" href="loan-document-download.php?id=<?= (int) $document['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด</a>
        <?php if ($canDeleteDraftDocument): ?><form action="<?= e($loanDocumentUploadUrl) ?>" method="post" class="d-inline" data-confirm="ยืนยันลบไฟล์ฉบับร่างนี้หรือไม่? หลังลบจะไม่สามารถกู้คืนได้"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="delete_draft_loan_document"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><button class="btn btn-outline-danger" type="submit"><?= app_icon('trash') ?> ลบไฟล์</button></form><?php endif; ?>
      </div>
      <?php if ($canReviseDocument): ?>
      <form class="collapse<?= $revisionUploadPost ? ' show' : '' ?> loan-document-revision-form" id="reviseLoanDocument<?= (int) $document['id'] ?>" action="<?= e($loanDocumentUploadUrl) ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="upload_loan_document"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><input type="hidden" name="replaces_document_id" value="<?= (int) $document['id'] ?>"><input type="hidden" name="row_version" value="<?= (int) $selectedLoan['row_version'] ?>"><input type="hidden" name="MAX_FILE_SIZE" value="<?= $documentLimits['file'] ?>">
        <div class="auth-field"><label class="form-label" for="revisedLoanDocumentFile<?= (int) $document['id'] ?>">เลือกไฟล์ฉบับแก้ไขของ “<?= e($document['title']) ?>”</label><input class="form-control" type="file" id="revisedLoanDocumentFile<?= (int) $document['id'] ?>" name="loan_document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" required><div class="form-text">ไฟล์เดิมจะถูกเก็บไว้เป็นประวัติ รองรับไฟล์ขนาดไม่เกิน 20 MB</div></div>
        <div class="auth-field"><label class="form-label" for="revisedLoanDocumentMessage<?= (int) $document['id'] ?>">ข้อความถึงเจ้าหน้าที่ส่งเสริม</label><textarea class="form-control" id="revisedLoanDocumentMessage<?= (int) $document['id'] ?>" name="document_message" rows="3" maxlength="1700"><?= e($revisionUploadPost ? mb_substr(post_text('document_message'), 0, 1700, 'UTF-8') : $draftDocumentMessage) ?></textarea></div>
        <div class="form-actions loan-draft-actions">
          <button class="btn btn-outline-primary" type="submit" name="document_action" value="draft">บันทึกร่าง</button>
          <?php if ($canSubmitSelectedLoan && (int) $selectedLoan['revision_document_count'] <= ($document['status'] === 'needs_revision' ? 1 : 0)): ?>
          <button class="btn btn-primary" type="submit" name="document_action" value="submit"><?= app_icon('arrow') ?> <?= e($sendDocumentLabel) ?></button>
          <?php endif; ?>
          <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#reviseLoanDocument<?= (int) $document['id'] ?>">ยกเลิก</button>
        </div>
      </form>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($previousProvinceDocuments): ?>
  <details class="mt-3">
    <summary class="text-link">ดูประวัติเอกสารที่ถูกแทนที่ (<?= count($previousProvinceDocuments) ?> ฉบับ)</summary>
    <ul class="list-unstyled mt-2 mb-0">
      <?php foreach ($previousProvinceDocuments as $previousDocument): ?>
      <li class="mb-2">
        <a class="text-link text-break" href="loan-document-download.php?id=<?= (int) $previousDocument['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด <?= e($previousDocument['original_name']) ?></a>
        <span class="form-text"><?= e(loan_document_date($previousDocument['created_at'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <?php if ($canReviewDocumentSet): ?>
  <?php $setReviewPost = $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'review_loan_document_set'; ?>
  <form class="review-decision-form loan-document-review-form" id="document-set-review" action="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>" method="post" enctype="multipart/form-data" aria-labelledby="documentSetReviewTitle">
    <h3 id="documentSetReviewTitle">สรุปผลตรวจชุดเอกสาร</h3>
    <p class="form-text mb-3">ดาวน์โหลดไฟล์ข้างต้นแล้วลงผลตรวจครั้งเดียวสำหรับเอกสารปัจจุบัน <?= (int) $selectedLoan['current_document_count'] ?> ฉบับ เขียนความเห็นหรือแนบไฟล์ประกอบอย่างน้อยหนึ่งอย่าง</p>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="operation" value="review_loan_document_set">
    <input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>">
    <input type="hidden" name="row_version" value="<?= (int) $selectedLoan['row_version'] ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
    <label class="form-label" for="documentSetReviewNote">ความเห็นต่อชุดเอกสาร</label>
    <textarea class="form-control" id="documentSetReviewNote" name="document_review_note" rows="4" maxlength="2000"><?= e($setReviewPost ? mb_substr(post_text('document_review_note'), 0, 2000, 'UTF-8') : '') ?></textarea>
    <?php $reviewAttachmentFieldId = 'documentSetReviewAttachments'; ?>
    <?php require __DIR__ . '/loan-review-upload.php'; ?>
    <div class="form-actions">
      <button class="btn btn-outline-danger" type="submit" name="document_decision" value="request_revision"><?= app_icon('alert') ?> ส่งกลับให้แก้ไข</button>
      <button class="btn btn-primary" type="submit" name="document_decision" value="verified"><?= app_icon('check') ?> ผ่านการตรวจทั้งชุด</button>
    </div>
  </form>
  <?php elseif ($isLoanDocumentPage && $loanDocumentSetReview && !$isProvinceDocumentReview): ?>
  <section class="loan-subsection" aria-labelledby="documentSetResultTitle">
    <h3 id="documentSetResultTitle">ผลตรวจชุดเอกสาร</h3>
    <span class="status-badge status-success mt-2">ผ่านการตรวจทั้งชุด</span>
    <p class="mb-1 mt-2"><?= nl2br(e($loanDocumentSetReview['comment'] ?? '')) ?></p>
    <p class="form-text"><?= e($loanDocumentSetReview['reviewer_name']) ?> · <?= e(loan_document_date($loanDocumentSetReview['created_at'])) ?></p>
    <?php $attachmentReviewId = (int) $loanDocumentSetReview['id']; ?>
    <?php require __DIR__ . '/loan-review-attachments.php'; ?>
  </section>
  <?php endif; ?>

  <?php if ($isLoanDocumentPage && account_is_admin($currentUser) && $loanDocuments): ?>
  <div class="loan-document-admin-clear<?= $loanDocumentClearState['allowed'] ? '' : ' is-locked' ?>">
    <div class="loan-document-admin-clear-heading">
      <span aria-hidden="true"><?= app_icon($loanDocumentClearState['allowed'] ? 'trash' : 'lock') ?></span>
      <div><strong>ล้างข้อมูลการส่งเอกสารของคำขอนี้</strong><p>ใช้เมื่อต้องการนำข้อมูลทดสอบออก โดยไม่ลบสหกรณ์ ผู้ใช้งาน รายละเอียดคำขอ หรือผู้ค้ำประกัน</p></div>
    </div>
    <?php if ($loanDocumentClearState['allowed']): ?>
    <details>
      <summary>เปิดแบบฟอร์มล้างข้อมูล <?= number_format((int) $loanDocumentClearState['document_count']) ?> ฉบับ</summary>
      <form action="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>" method="post" data-confirm="ยืนยันลบไฟล์และประวัติการส่งเอกสารของคำขอนี้อย่างถาวรหรือไม่?">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="clear_test_loan_documents"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>">
        <div class="loan-document-clear-impact" role="note"><strong>ข้อมูลที่จะถูกลบ</strong><span>เอกสารคำขอกู้ · ประวัติการตรวจเอกสาร · ความเห็นและไฟล์แนบของเจ้าหน้าที่ส่งเสริม · งานและการแจ้งเตือนของรอบนี้</span><strong>ข้อมูลที่จะเก็บไว้</strong><span>หน่วยงาน · ผู้ใช้งาน · รายละเอียดคำขอกู้ · ผู้ค้ำประกัน · ประวัติ Audit</span></div>
        <div class="auth-field"><label class="form-label" for="loanDocumentClearReason">เหตุผลการล้างข้อมูล</label><textarea class="form-control" id="loanDocumentClearReason" name="clear_reason" rows="2" minlength="5" maxlength="500" required></textarea></div>
        <div class="auth-field"><label class="form-label" for="loanDocumentClearConfirmation">พิมพ์เลขที่คำขอ <strong><?= e($selectedLoan['application_no']) ?></strong> เพื่อยืนยัน</label><input class="form-control" type="text" id="loanDocumentClearConfirmation" name="clear_confirmation" autocomplete="off" maxlength="50" required></div>
        <button class="btn btn-danger" type="submit"><?= app_icon('trash') ?> ล้างข้อมูลการส่งเอกสาร</button>
      </form>
    </details>
    <?php else: ?>
    <p class="loan-document-admin-clear-message"><?= e($loanDocumentClearState['message']) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
