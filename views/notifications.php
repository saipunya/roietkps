<?php
$notificationPriorityLabels = ['normal' => 'ข้อมูลทั่วไป', 'warning' => 'ต้องดำเนินการ', 'urgent' => 'เร่งด่วน'];
?>
<?php if ($notificationNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($notificationNotice) ?></div><?php endif; ?>
<?php if ($notificationError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($notificationError) ?></div><?php endif; ?>

<section class="notification-summary-grid" aria-label="สรุปงานและการแจ้งเตือน">
  <article class="notification-summary-card"><span class="notification-summary-icon tone-amber"><?= app_icon('briefcase') ?></span><div><strong><?= e($notificationOpenTaskCount) ?></strong><span>งานที่ต้องดำเนินการ</span></div></article>
  <article class="notification-summary-card"><span class="notification-summary-icon tone-red"><?= app_icon('bell') ?></span><div><strong><?= e($notificationUnreadCount) ?></strong><span>การแจ้งเตือนที่ยังไม่อ่าน</span></div></article>
</section>

<section class="surface notification-section" aria-labelledby="myTasksTitle">
  <div class="surface-heading"><div><h2 id="myTasksTitle">งานของฉัน</h2><p>เรียงตามงานที่ใกล้ครบกำหนดก่อน</p></div><span class="subtle-icon"><?= app_icon('clock') ?></span></div>
  <?php if (!$notificationTasks): ?>
    <div class="empty-state compact-empty-state"><?= app_icon('check') ?><h2>ไม่มีงานค้างดำเนินการ</h2><p>งานใหม่จะแสดงที่นี่เมื่อมีรายการส่งมาถึงคุณ</p></div>
  <?php else: ?>
    <div class="workflow-task-list">
      <?php foreach ($notificationTasks as $task): ?>
        <a class="workflow-task-item task-state-<?= e($task['due_state']) ?>" href="<?= e(notification_safe_action_url($task['action_url'])) ?>">
          <span class="workflow-task-marker tone-<?= $task['due_state'] === 'overdue' ? 'red' : ($task['due_state'] === 'due_soon' ? 'amber' : 'blue') ?>"><?= app_icon($task['due_state'] === 'overdue' ? 'alert' : 'clock') ?></span>
          <span class="workflow-task-copy"><strong><?= e($task['title']) ?></strong><small><?= e($task['description'] ?: 'เปิดรายการเพื่อดำเนินการ') ?></small></span>
          <span class="workflow-task-due"><small><?= $task['due_state'] === 'overdue' ? 'เกินกำหนด' : 'กำหนดเสร็จ' ?></small><strong><?= e(notification_due_label($task['due_at'])) ?></strong></span>
          <?= app_icon('chevron') ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="surface notification-section" aria-labelledby="notificationCenterTitle">
  <div class="notification-section-heading">
    <div><h2 id="notificationCenterTitle">การแจ้งเตือน</h2><p>ข่าวสารและเหตุการณ์ที่เกี่ยวข้องกับงานของคุณ</p></div>
    <?php if ($notificationUnreadCount > 0): ?><form action="dashboard.php?page=notifications" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="mark_all_notifications"><button class="btn btn-outline-primary btn-sm" type="submit"><?= app_icon('check') ?> อ่านทั้งหมด</button></form><?php endif; ?>
  </div>
  <nav class="notification-filter" aria-label="กรองการแจ้งเตือน">
    <a class="<?= $notificationFilter === 'all' ? 'active' : '' ?>" href="dashboard.php?page=notifications">ทั้งหมด</a>
    <a class="<?= $notificationFilter === 'unread' ? 'active' : '' ?>" href="dashboard.php?page=notifications&amp;status=unread">ยังไม่อ่าน <span><?= e($notificationUnreadCount) ?></span></a>
  </nav>
  <?php if (!$notificationRows): ?>
    <div class="empty-state compact-empty-state"><?= app_icon('bell') ?><h2>ไม่มีการแจ้งเตือน</h2><p>ระบบจะแจ้งให้ทราบเมื่อมีงานหรือสถานะเปลี่ยนแปลง</p></div>
  <?php else: ?>
    <div class="notification-list">
      <?php foreach ($notificationRows as $notification): ?>
        <article class="notification-row<?= $notification['read_at'] === null ? ' is-unread' : '' ?>" id="notification-<?= (int) $notification['id'] ?>">
          <span class="notification-row-icon tone-<?= $notification['priority'] === 'urgent' ? 'red' : ($notification['priority'] === 'warning' ? 'amber' : 'green') ?>"><?= app_icon($notification['priority'] === 'urgent' ? 'alert' : 'bell') ?></span>
          <div class="notification-row-copy"><div class="notification-row-title"><strong><?= e($notification['title']) ?></strong><?php if ($notification['read_at'] === null): ?><span class="unread-dot">ใหม่</span><?php endif; ?></div><p><?= e($notification['message']) ?></p><small><?= e($notificationPriorityLabels[$notification['priority']] ?? 'ข้อมูลทั่วไป') ?> · <?= e(notification_time_label($notification['created_at'])) ?></small></div>
          <form action="dashboard.php?page=notifications" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="open_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn notification-open-button" type="submit">เปิดรายการ <?= app_icon('chevron') ?></button></form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
