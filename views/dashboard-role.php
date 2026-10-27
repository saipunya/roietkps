<?php
$applicationStatuses = [
    'draft' => 'ฉบับร่าง',
    'submitted' => 'ส่งตรวจแล้ว',
    'promotion_review' => 'เจ้าหน้าที่ส่งเสริมกำลังตรวจ',
    'revision_requested' => 'ส่งกลับแก้ไข',
    'province_review' => 'รอจังหวัดตรวจสอบ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ไม่อนุมัติ',
    'cancelled' => 'ยกเลิก',
];
?>
<section class="role-dashboard-heading">
  <div><p class="eyebrow"><?= e($currentUser['role_name']) ?></p><h2><?= e($roleDashboard['heading']) ?></h2><p><?= e($roleDashboard['description']) ?></p></div>
  <span class="role-dashboard-mark"><?= app_icon($currentUser['role_code'] === 'cooperative_user' ? 'building' : 'dashboard') ?></span>
</section>

<section class="stats-grid" aria-label="สรุปงานตามบทบาท">
  <?php foreach ($roleDashboard['stats'] as $stat): ?>
    <article class="stat-card">
      <div class="stat-top"><h2><?= e($stat['label']) ?></h2><span class="stat-icon tone-<?= e($stat['tone']) ?>"><?= app_icon($stat['icon']) ?></span></div>
      <p class="stat-value"><?= e($stat['value']) ?><span><?= e($stat['unit']) ?></span></p>
      <p class="stat-note"><?= e($stat['note']) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<?php if ($roleDashboard['tasks']): ?>
<section class="surface role-task-panel">
  <div class="surface-heading"><div><h2>งานที่ต้องดำเนินการ</h2><p>เรียงตามขั้นตอนที่เกี่ยวข้องกับบทบาทของคุณ</p></div><?= app_icon('clock') ?></div>
  <div class="task-list">
    <?php foreach ($roleDashboard['tasks'] as $task): ?>
      <a class="task-item" href="<?= e($task['href']) ?>"><span class="task-icon tone-<?= e($task['tone']) ?>"><?= app_icon($task['icon']) ?></span><span class="task-copy"><strong><?= e($task['title']) ?></strong><small><?= e($task['description']) ?></small></span><span class="task-count"><?= e($task['count']) ?><small><?= e($task['unit']) ?></small></span><?= app_icon('chevron') ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="surface recent-section role-recent-section">
  <div class="surface-heading"><div><h2>คำขอกู้ที่อัปเดตล่าสุด</h2><p>รายการภายในขอบเขตที่คุณรับผิดชอบ</p></div><?php if (account_can($currentUser, 'loans.view')): ?><a class="text-link" href="index.php?page=loans">ดูทั้งหมด <?= app_icon('arrow') ?></a><?php endif; ?></div>
  <?php if (!$roleDashboard['recent']): ?>
    <div class="empty-state"><?= app_icon('document') ?><h2>ยังไม่มีคำขอกู้</h2><p>รายการใหม่จะแสดงที่นี่เมื่อเริ่มบันทึกข้อมูล</p></div>
  <?php else: ?>
    <div class="records-wrap"><table class="table records-table mb-0"><thead><tr><th>หน่วยงาน / เลขที่คำขอ</th><th>วัตถุประสงค์</th><th class="amount-cell">วงเงินที่ขอ</th><th>สถานะ</th></tr></thead><tbody>
      <?php foreach ($roleDashboard['recent'] as $application): ?>
        <tr><td class="record-primary"><strong><?= e($application['organization_name']) ?></strong><small><?= e($application['application_no']) ?></small></td><td data-label="วัตถุประสงค์"><?= e($application['purpose']) ?></td><td data-label="วงเงิน" class="amount-cell"><?= e(number_format((float) $application['requested_amount'], 2)) ?> บาท</td><td data-label="สถานะ"><span class="status-badge status-<?= in_array($application['status'], ['approved'], true) ? 'success' : ($application['status'] === 'revision_requested' ? 'danger' : 'warning') ?>"><?= e($applicationStatuses[$application['status']] ?? $application['status']) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
