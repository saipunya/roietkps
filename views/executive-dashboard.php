<section class="executive-intro" aria-labelledby="executiveOverviewTitle">
  <div>
    <p class="eyebrow">สำหรับผู้บริหารสหกรณ์จังหวัด</p>
    <h2 id="executiveOverviewTitle">ภาพรวมการให้เงินกู้ทั้งจังหวัด</h2>
    <p>ติดตามผลอนุมัติ สถานะสัญญา และรายการที่ต้องผลักดันต่อได้จากหน้าเดียว</p>
  </div>
  <span class="executive-intro-mark" aria-hidden="true"><?= app_icon('chart') ?></span>
</section>

<section class="surface executive-follow-up-panel" aria-labelledby="executiveFollowUpTitle">
  <div class="surface-heading"><h2 id="executiveFollowUpTitle">รายการที่ควรติดตาม</h2></div>
  <div class="task-list executive-follow-up-list">
    <?php foreach ($executiveDashboard['follow_ups'] as $item): ?>
    <a class="task-item" href="<?= e($item['href']) ?>"><span class="task-icon tone-<?= e($item['tone']) ?>"><?= app_icon($item['icon']) ?></span><span class="task-copy"><strong><?= e($item['title']) ?></strong><small><?= e($item['description']) ?></small></span><span class="task-count"><?= e($item['count']) ?><small>รายการ</small></span><?= app_icon('chevron') ?></a>
    <?php endforeach; ?>
  </div>
</section>

<section class="stats-grid executive-stats" aria-label="สรุปภาพรวมเงินกู้">
  <?php foreach ($executiveDashboard['stats'] as $stat): ?>
  <article class="stat-card">
    <div class="stat-top"><h2><?= e($stat['label']) ?></h2><span class="stat-icon tone-<?= e($stat['tone']) ?>"><?= app_icon($stat['icon']) ?></span></div>
    <p class="stat-value"><?= e($stat['value']) ?><span><?= e($stat['unit']) ?></span></p>
    <p class="stat-note"><?= e($stat['note']) ?></p>
  </article>
  <?php endforeach; ?>
</section>

<section class="surface executive-workflow-panel" aria-labelledby="executiveWorkflowTitle">
  <div class="surface-heading"><div><h2 id="executiveWorkflowTitle">สถานะคำขอตามขั้นตอน</h2><p>จำนวนคำขอในแต่ละขั้นของการพิจารณา</p></div><a class="text-link" href="dashboard.php?page=loans">ดูทะเบียนเงินกู้ <?= app_icon('arrow') ?></a></div>
  <div class="executive-workflow">
    <?php foreach ($executiveDashboard['workflow'] as $index => $step): ?>
    <article class="executive-workflow-step tone-<?= e($step['tone']) ?>">
      <span class="executive-workflow-number"><?= (int) $index + 1 ?></span>
      <strong><?= e($step['label']) ?></strong>
      <b><?= e($step['count']) ?><small>คำขอ</small></b>
      <span><?= e($step['description']) ?></span>
    </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="surface executive-recent-section" aria-labelledby="executiveRecentTitle">
  <div class="surface-heading"><div><h2 id="executiveRecentTitle">คำขอและสัญญาที่ต้องติดตาม</h2><p>เรียงรายการรอพิจารณาและรายการที่ยังมีขั้นตอนค้างอยู่ก่อน</p></div><a class="text-link" href="dashboard.php?page=loans">ดูทั้งหมด <?= app_icon('arrow') ?></a></div>
  <?php if (!$executiveDashboard['recent']): ?>
  <div class="empty-state compact-empty-state"><?= app_icon('document') ?><h2>ยังไม่มีรายการเงินกู้</h2><p>เมื่อมีคำขอในจังหวัด รายการจะแสดงที่นี่</p></div>
  <?php else: ?>
  <table class="table records-table executive-records-table mb-0" aria-labelledby="executiveRecentTitle">
    <thead>
      <tr><th scope="col">หน่วยงาน / เลขที่คำขอ</th><th scope="col" class="amount-cell">วงเงิน</th><th scope="col">ขั้นตอนปัจจุบัน</th><th scope="col">ผู้รับผิดชอบ</th><th scope="col"><span class="visually-hidden">รายละเอียด</span></th></tr>
    </thead>
    <tbody>
      <?php foreach ($executiveDashboard['recent'] as $row): ?>
      <tr>
        <td class="record-primary"><strong><?= e($row['organization_name']) ?></strong><small><?= e($row['application_no']) ?><?= $row['contract_no'] ? ' · สัญญา ' . e($row['contract_no']) : '' ?></small></td>
        <td class="amount-cell" data-label="วงเงิน"><?= e(number_format((float) ($row['principal_amount'] ?? $row['approved_amount'] ?? $row['requested_amount']), 2)) ?> บาท</td>
        <td data-label="ขั้นตอนปัจจุบัน"><div class="lifecycle-status-cell"><span class="status-badge status-<?= e($row['stage']['tone']) ?>"><?= e($row['stage']['label']) ?></span><small><?= e($row['stage']['description']) ?></small></div></td>
        <td data-label="ผู้รับผิดชอบ"><?= e($row['officer_name'] ?: 'รอมอบหมาย') ?></td>
        <td class="record-action" data-no-export><a class="btn record-button" href="dashboard.php?page=loans&amp;view=<?= (int) $row['id'] ?>">รายละเอียด <?= app_icon('chevron') ?></a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
