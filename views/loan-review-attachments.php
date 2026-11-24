<?php $attachmentsForReview = $loanReviewAttachments[$attachmentReviewId] ?? []; ?>
<?php if ($attachmentsForReview): ?>
  <ul class="list-unstyled mb-2">
    <?php foreach ($attachmentsForReview as $attachment): ?>
      <li class="mb-1">
        <a class="text-link" href="loan-document-download.php?attachment=<?= (int) $attachment['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด <?= e($attachment['original_name']) ?></a>
        <span class="text-body-secondary small"><?= e(loan_document_format_size((int) $attachment['file_size'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
