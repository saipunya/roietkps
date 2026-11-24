<div class="auth-field mt-3">
  <label class="form-label" for="<?= e($reviewAttachmentFieldId) ?>">ไฟล์แนบประกอบความเห็น (ไม่บังคับ)</label>
  <input class="form-control" type="file" id="<?= e($reviewAttachmentFieldId) ?>" name="review_attachments[]" accept="<?= e('.' . implode(',.', array_keys(loan_document_allowed_files()))) ?>" multiple>
  <div class="form-text">เลือกได้หลายไฟล์ ไฟล์ละไม่เกิน 20 MB</div>
</div>
