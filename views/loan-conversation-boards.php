<?php
$hasConversationHistory = false;
foreach ($loanConversationMessages as $conversationMessages) {
  if ($conversationMessages) {
    $hasConversationHistory = true;
    break;
  }
}
?>
<?php if ($hasConversationHistory): ?>
<section class="loan-subsection" aria-labelledby="loanContactHistoryTitle">
  <h3 id="loanContactHistoryTitle">ประวัติข้อความและไฟล์ประกอบ</h3>
  <p class="form-text">แสดงประวัติเดิมและข้อความที่ระบบบันทึกเมื่อส่งเอกสาร ไม่มีช่องส่งข้อความหรือแนบไฟล์แยกต่างหาก</p>
  <?php foreach ($loanConversationChannels as $conversationChannel => $conversationTitle): ?>
    <?php $messages = $loanConversationMessages[$conversationChannel] ?? []; ?>
    <?php if ($messages): ?>
    <section class="mt-3" aria-labelledby="loanContactHistory<?= e($conversationChannel) ?>Title">
      <h4 id="loanContactHistory<?= e($conversationChannel) ?>Title" class="h6 mb-2"><?= e($conversationTitle) ?></h4>
      <ul class="list-unstyled d-grid gap-2 mb-0" aria-label="ประวัติข้อความ <?= e($conversationTitle) ?>">
        <?php foreach ($messages as $message): ?>
        <li class="border rounded-3 p-3">
          <div class="d-flex flex-wrap align-items-baseline gap-2">
            <strong><?= e($message['full_name']) ?></strong>
            <span class="text-body-secondary small"><?= e(loan_display_datetime($message['created_at'])) ?></span>
          </div>
          <?php if ($message['body'] !== ''): ?>
            <p class="text-break mt-2 mb-0"><?= nl2br(e($message['body'])) ?></p>
          <?php endif; ?>
          <?php if ($message['attachments']): ?>
            <ul class="list-unstyled mt-2 mb-0">
              <?php foreach ($message['attachments'] as $file): ?>
              <li class="text-break"><a class="text-link" href="loan-document-download.php?message_attachment=<?= (int) $file['attachment_id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด <?= e($file['original_name']) ?></a> <span class="text-body-secondary small"><?= e(loan_document_format_size((int) $file['file_size'])) ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  <?php endforeach; ?>
</section>
<?php endif; ?>
