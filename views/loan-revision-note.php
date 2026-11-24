<?php if ($selectedLoan['status'] === 'revision_requested'): ?>
  <div class="alert alert-warning">
    <?php if ($latestRevision): ?>
      <strong><?= $latestRevision['review_stage'] === 'province' ? 'ความเห็นระดับจังหวัด' : 'ความเห็นของเจ้าหน้าที่ส่งเสริม' ?></strong>
      <p class="mb-2"><?= nl2br(e($latestRevision['comment'] ?? '')) ?></p>
      <?php $attachmentReviewId = (int) $latestRevision['id']; ?>
      <?php require __DIR__ . '/loan-review-attachments.php'; ?>
    <?php else: ?>
      <strong>คำขอถูกส่งกลับมาให้แก้ไข</strong>
    <?php endif; ?>
    <?php if ($waitingForPromotionRevision): ?>
      <p class="mb-2"><strong>อยู่ระหว่างเจ้าหน้าที่ส่งเสริมตรวจแก้</strong></p>
      <?php if ($isApplicantOrganization): ?>
        <p class="mb-2">สหกรณ์อ่านความเห็นและดาวน์โหลดไฟล์แนบได้ เมื่อเจ้าหน้าที่ส่งเสริมส่งกลับมา จึงจะแก้ไขและส่งให้เจ้าหน้าที่ส่งเสริมตรวจอีกครั้งได้</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-2"><strong>รอสหกรณ์แก้ไขและส่งให้เจ้าหน้าที่ส่งเสริมตรวจอีกครั้ง</strong></p>
      <?php if ($provinceRevisionForRound): ?>
        <details class="mb-2">
          <summary class="text-link">ดูความเห็นและไฟล์แนบจากจังหวัดของรอบนี้</summary>
          <p class="mt-2 mb-2"><?= nl2br(e($provinceRevisionForRound['comment'] ?? '')) ?></p>
          <?php $attachmentReviewId = (int) $provinceRevisionForRound['id']; ?>
          <?php require __DIR__ . '/loan-review-attachments.php'; ?>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-2">
      <?php if ($canEditSelectedLoan): ?>
        <a class="btn btn-primary" href="dashboard.php?page=loans&amp;edit=<?= (int) $selectedLoan['id'] ?>">แก้ไขข้อมูลคำขอ</a>
      <?php endif; ?>
      <a class="btn btn-outline-primary" href="#loan-documents"><?= $canUploadLoanDocuments ? 'แก้ไขเอกสารคำขอกู้' : 'ดูเอกสารคำขอกู้' ?></a>
      <?php if ($canPromotionHandleReturnedLoan): ?>
        <a class="btn btn-outline-primary" href="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>#promotion-review">ลงความเห็นและแนบไฟล์</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
