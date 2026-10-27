<div class="report-grid">
  <?php foreach ([['page' => 'registry', 'icon' => 'building', 'title' => 'ทะเบียนสหกรณ์', 'description' => 'รหัสสหกรณ์ พื้นที่ ประเภท และสถานะ', 'count' => count($cooperatives)], ['page' => 'loans', 'icon' => 'document', 'title' => 'ทะเบียนเงินกู้', 'description' => 'คำขอกู้ วงเงิน และผลการพิจารณา', 'count' => count($loans)], ['page' => 'debt', 'icon' => 'clock', 'title' => 'ติดตามหนี้', 'description' => 'กำหนดชำระ เงินต้นคงเหลือ และสถานะ', 'count' => count($debts)]] as $report): ?>
  <article class="surface report-card"><span class="report-icon tone-green"><?= app_icon($report['icon']) ?></span><h2><?= e($report['title']) ?></h2><p><?= e($report['description']) ?></p><small>ทั้งหมด <?= e($report['count']) ?> รายการ</small><a class="btn btn-outline-primary" href="dashboard.php?page=<?= e($report['page']) ?>">เลือกข้อมูลและส่งออก <?= app_icon('arrow') ?></a></article>
  <?php endforeach; ?>
</div>
<p class="data-footnote"><?= app_icon('info') ?> ส่งออก CSV ได้จากหน้ารายการ ระบบจะส่งออกเฉพาะข้อมูลที่ตรงกับตัวกรอง</p>
