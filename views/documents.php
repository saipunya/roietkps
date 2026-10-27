<?php $documentCategories = public_document_categories(); ?>

<?php if ($documentsNotice): ?><div class="alert alert-success" role="status"><?= e($documentsNotice) ?></div><?php endif; ?>
<?php if ($documentsError): ?><div class="alert alert-danger" role="alert"><?= e($documentsError) ?></div><?php endif; ?>

<?php if ($canPublishDocuments): ?>
<section class="surface document-upload-panel" aria-labelledby="documentUploadTitle">
  <div class="surface-heading">
    <div><h2 id="documentUploadTitle">เผยแพร่เอกสาร</h2><p>สำหรับ Admin และเจ้าหน้าที่จังหวัด</p></div>
    <span class="document-heading-icon tone-green"><?= app_icon('upload') ?></span>
  </div>
  <form class="document-upload-form" action="<?= e($workspaceUrl) ?>?page=documents" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="operation" value="upload_public_document">
    <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
    <div class="document-upload-grid">
      <div class="auth-field document-title-field">
        <label class="form-label" for="documentTitle">หัวข้อเอกสาร</label>
        <input class="form-control" type="text" id="documentTitle" name="title" minlength="3" maxlength="200" value="<?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('title') : '') ?>" required>
      </div>
      <div class="auth-field">
        <label class="form-label" for="documentCategory">ประเภทเอกสาร</label>
        <select class="form-select" id="documentCategory" name="category" required>
          <option value="">โปรดเลือก</option>
          <?php foreach ($documentCategories as $code => $label): ?>
          <option value="<?= e($code) ?>"<?= $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('category') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="auth-field document-description-field">
        <label class="form-label" for="documentDescription">รายละเอียด <span class="text-secondary">(ไม่บังคับ)</span></label>
        <textarea class="form-control" id="documentDescription" name="description" rows="3" maxlength="1000"><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('description') : '') ?></textarea>
      </div>
      <div class="auth-field document-file-field">
        <label class="form-label" for="documentFile">ไฟล์เอกสาร</label>
        <input class="form-control" type="file" id="documentFile" name="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.zip" required>
        <div class="form-text">รองรับ PDF, Word, Excel, PowerPoint, CSV, TXT และ ZIP ขนาดไม่เกิน 20 MB</div>
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('upload') ?> อัปโหลดและเผยแพร่</button></div>
  </form>
</section>
<?php endif; ?>

<section class="surface document-library" aria-labelledby="documentLibraryTitle">
  <div class="surface-heading">
    <div><h2 id="documentLibraryTitle">คลังเอกสารเผยแพร่</h2><p>ค้นหาและดาวน์โหลดคู่มือ แบบฟอร์ม และเอกสารที่เกี่ยวข้อง</p></div>
    <span class="document-heading-icon tone-blue"><?= app_icon('folder') ?></span>
  </div>
  <form class="document-toolbar" action="<?= e($workspaceUrl) ?>" method="get" role="search">
    <input type="hidden" name="page" value="documents">
    <div class="search-field"><label class="visually-hidden" for="documentSearch">ค้นหาเอกสาร</label><?= app_icon('search') ?><input class="form-control" type="search" id="documentSearch" name="q" value="<?= e($documentSearch) ?>" placeholder="ค้นหาหัวข้อหรือชื่อไฟล์"></div>
    <div class="document-category-filter"><label class="visually-hidden" for="documentCategoryFilter">ประเภทเอกสาร</label><select class="form-select" id="documentCategoryFilter" name="category"><option value="">ทุกประเภท</option><?php foreach ($documentCategories as $code => $label): ?><option value="<?= e($code) ?>"<?= $documentCategory === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-outline-primary" type="submit">ค้นหา</button>
  </form>
  <div class="results-summary">พบ <?= number_format($documentTotal) ?> รายการ</div>
  <?php if (!$publicDocuments): ?>
  <div class="empty-state"><?= app_icon('folder') ?><h2>ยังไม่มีเอกสารเผยแพร่</h2><p>เอกสารที่อัปโหลดจะแสดงในหน้านี้</p></div>
  <?php else: ?>
  <div class="document-list">
    <?php foreach ($publicDocuments as $document): ?>
    <?php $documentIsPinned = (int) $document['is_pinned'] === 1; $documentIsNew = public_document_is_new($document['published_at']); ?>
    <article class="document-row<?= $documentIsPinned ? ' is-pinned' : '' ?>">
      <span class="document-file-icon" aria-hidden="true"><?= app_icon('file') ?><small><?= e(strtoupper($document['file_extension'])) ?></small></span>
      <div class="document-row-copy">
        <div class="document-row-title"><h3><?= e($document['title']) ?></h3><?php if ($documentIsPinned): ?><span class="document-highlight-badge badge-pinned"><?= app_icon('pin') ?> ปักหมุด</span><?php endif; ?><?php if ($documentIsNew): ?><span class="document-highlight-badge badge-new"><?= app_icon('new') ?> ใหม่</span><?php endif; ?><span class="document-category-badge"><?= e($documentCategories[$document['category']] ?? 'เอกสาร') ?></span></div>
        <?php if (!empty($document['description'])): ?><p><?= e($document['description']) ?></p><?php endif; ?>
        <div class="document-meta"><span><?= app_icon('calendar') ?> <?= e(public_document_date($document['published_at'])) ?></span><span><?= e(public_document_format_size((int) $document['file_size'])) ?></span><span>ดาวน์โหลด <?= number_format((int) $document['download_count']) ?> ครั้ง</span><span>โดย <?= e($document['uploader_name']) ?></span></div>
      </div>
      <div class="document-actions">
        <?php if ($canPublishDocuments): ?><form action="<?= e($workspaceUrl) ?>?page=documents" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="toggle_public_document_pin"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><input type="hidden" name="pin_action" value="<?= $documentIsPinned ? 'unpin' : 'pin' ?>"><button class="btn document-pin-button<?= $documentIsPinned ? ' is-active' : '' ?>" type="submit"><?= app_icon($documentIsPinned ? 'unpin' : 'pin') ?> <?= $documentIsPinned ? 'ยกเลิกปักหมุด' : 'ปักหมุด' ?></button></form><?php endif; ?>
        <a class="btn btn-outline-primary document-download-button" href="download.php?id=<?= (int) $document['id'] ?>"><?= app_icon('download') ?> ดาวน์โหลด<span class="visually-hidden"> <?= e($document['title']) ?></span></a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($documentPageCount > 1): ?>
  <nav class="document-pagination" aria-label="หน้ารายการเอกสาร">
    <?php for ($pageNumber = 1; $pageNumber <= $documentPageCount; $pageNumber++): ?>
    <?php $pageQuery = http_build_query(['page' => 'documents', 'q' => $documentSearch, 'category' => $documentCategory, 'p' => $pageNumber]); ?>
    <a class="<?= $pageNumber === $documentPage ? 'active' : '' ?>" href="<?= e($workspaceUrl) ?>?<?= e($pageQuery) ?>"<?= $pageNumber === $documentPage ? ' aria-current="page"' : '' ?>><?= $pageNumber ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
</section>
