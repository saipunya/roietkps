<?php $dashboardDocumentCategories = public_document_categories(); ?>
<section class="surface dashboard-documents" aria-labelledby="latestDocumentsTitle">
  <div class="surface-heading">
    <div><h2 id="latestDocumentsTitle">คู่มือและเอกสารเผยแพร่ล่าสุด</h2><p>10 หัวข้อล่าสุดสำหรับดาวน์โหลดและใช้งาน</p></div>
    <a class="text-link" href="<?= e($workspaceUrl) ?>?page=documents">ดูทั้งหมด <?= app_icon('arrow') ?></a>
  </div>
  <?php if (!$latestPublicDocuments): ?>
  <div class="empty-state compact-document-empty"><?= app_icon('folder') ?><h2>ยังไม่มีเอกสารเผยแพร่</h2><p>รายการเอกสารล่าสุดจะแสดงที่นี่</p></div>
  <?php else: ?>
  <div class="dashboard-document-list">
    <?php foreach ($latestPublicDocuments as $document): ?>
    <?php $dashboardDocumentIsPinned = (int) $document['is_pinned'] === 1; $dashboardDocumentIsNew = public_document_is_new($document['published_at']); ?>
    <article class="dashboard-document-row<?= $dashboardDocumentIsPinned ? ' is-pinned' : '' ?>">
      <span class="dashboard-document-icon"><?= app_icon('file') ?></span>
      <div><div class="dashboard-document-title"><h3><?= e($document['title']) ?></h3><?php if ($dashboardDocumentIsPinned): ?><span class="document-highlight-badge badge-pinned"><?= app_icon('pin') ?> ปักหมุด</span><?php endif; ?><?php if ($dashboardDocumentIsNew): ?><span class="document-highlight-badge badge-new"><?= app_icon('new') ?> ใหม่</span><?php endif; ?></div><p><span><?= e($dashboardDocumentCategories[$document['category']] ?? 'เอกสาร') ?></span> · <?= e(public_document_date($document['published_at'])) ?> · <?= e(public_document_format_size((int) $document['file_size'])) ?></p></div>
      <a class="btn document-compact-download" href="download.php?id=<?= (int) $document['id'] ?>" aria-label="ดาวน์โหลด <?= e($document['title']) ?>"><?= app_icon('download') ?><span>ดาวน์โหลด</span></a>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
