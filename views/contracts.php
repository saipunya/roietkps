<?php
$contractTone = function (string $status): string {
    if (in_array($status, ['active', 'completed', 'closed'], true)) {
        return 'success';
    }
    if ($status === 'cancelled') {
        return 'danger';
    }
    return 'warning';
};
?>
<?php if ($trackingNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($trackingNotice) ?></div><?php endif; ?>
<?php if ($trackingError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($trackingError) ?></div><?php endif; ?>

<section class="tracking-summary-grid" aria-label="สรุปข้อมูลสัญญา">
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-blue"><?= app_icon('document') ?></span><div><small>สัญญาทั้งหมด</small><strong><?= (int) $trackingSummary['total'] ?><span> สัญญา</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-amber"><?= app_icon('clock') ?></span><div><small>อยู่ระหว่างจัดทำ</small><strong><?= (int) $trackingSummary['preparation'] ?><span> สัญญา</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-green"><?= app_icon('check') ?></span><div><small>อยู่ระหว่างชำระ</small><strong><?= (int) $trackingSummary['active'] ?><span> สัญญา</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-red"><?= app_icon('wallet') ?></span><div><small>เงินต้นคงเหลือ</small><strong><?= e(number_format((float) $trackingSummary['outstanding'], 2)) ?><span> บาท</span></strong></div></article>
</section>

<?php if ($selectedContract): ?>
<section class="surface tracking-detail-panel mb-4" aria-labelledby="contractDetailTitle">
  <div class="surface-heading tracking-heading">
    <div><h2 id="contractDetailTitle"><?= e($selectedContract['contract_no'] ?: 'สัญญารอระบุเลขที่') ?></h2><p><?= e($selectedContract['organization_name']) ?> · <?= e($selectedContract['application_no']) ?></p></div>
    <div class="tracking-heading-actions"><a class="btn btn-outline-primary" href="dashboard.php?page=loans&amp;view=<?= (int) $selectedContract['loan_application_id'] ?>">ดูคำขอกู้</a><a class="btn btn-outline-primary" href="dashboard.php?page=debt&amp;contract=<?= (int) $selectedContract['id'] ?>">ติดตามการชำระ</a><a class="text-link" href="dashboard.php?page=contracts">ปิด</a></div>
  </div>
  <div class="tracking-detail-body">
    <div class="loan-status-line"><span class="status-badge status-<?= e($contractTone($selectedContract['status'])) ?>"><?= e($contractStatusLabels[$selectedContract['status']] ?? $selectedContract['status']) ?></span><?php if ((int) $selectedContract['overdue_count'] > 0): ?><span class="status-badge status-danger">ค้างชำระ <?= (int) $selectedContract['overdue_count'] ?> งวด</span><?php endif; ?></div>
    <dl class="loan-detail-grid tracking-contract-grid">
      <div><dt>เงินต้นตามสัญญา</dt><dd><?= e(number_format((float) $selectedContract['principal_amount'], 2)) ?> บาท</dd></div>
      <div><dt>อัตราดอกเบี้ย</dt><dd><?= e(number_format((float) $selectedContract['interest_rate'], 4)) ?>%</dd></div>
      <div><dt>ระยะเวลา</dt><dd><?= (int) $selectedContract['term_months'] ?> เดือน</dd></div>
      <div><dt>วันที่เริ่มสัญญา</dt><dd><?= e(post_approval_display_date($selectedContract['start_date'])) ?></dd></div>
      <div><dt>วันที่ครบกำหนด</dt><dd><?= e(post_approval_display_date($selectedContract['maturity_date'])) ?></dd></div>
      <div><dt>วันที่เบิกจ่าย</dt><dd><?= e(post_approval_display_datetime($selectedContract['disbursed_at'])) ?></dd></div>
      <div><dt>เงินต้นคงเหลือ</dt><dd><?= e(number_format((float) $selectedContract['outstanding_principal'], 2)) ?> บาท</dd></div>
      <div><dt>ตารางชำระ</dt><dd><?= (int) $selectedContract['schedule_count'] ?> งวด</dd></div>
      <div><dt>เจ้าหน้าที่ส่งเสริม</dt><dd><?= e($selectedContract['officer_name'] ?: 'รอมอบหมาย') ?></dd></div>
    </dl>

    <?php if (account_can($currentUser, 'contracts.manage') && !in_array($selectedContract['status'], ['active', 'closed', 'cancelled'], true)): ?>
    <form class="review-decision-form tracking-contract-form" action="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="save_contract"><input type="hidden" name="contract_id" value="<?= (int) $selectedContract['id'] ?>">
      <div class="form-section-heading"><div><h3>จัดทำสัญญา</h3><p>บันทึกเลขที่สัญญา วันเริ่ม วันครบกำหนด และเลื่อนสถานะตามลำดับงาน</p></div></div>
      <div class="tracking-form-grid">
        <div class="auth-field"><label class="form-label" for="contractNo">เลขที่สัญญา</label><input class="form-control" id="contractNo" name="contract_no" maxlength="50" placeholder="เช่น KPS-2569-001" value="<?= e($selectedContract['contract_no'] ?? '') ?>"></div>
        <div class="auth-field"><label class="form-label" for="contractStartDate">วันที่เริ่มสัญญา</label><input class="form-control" type="date" id="contractStartDate" name="start_date" value="<?= e($selectedContract['start_date'] ?? '') ?>"></div>
        <div class="auth-field"><label class="form-label" for="contractMaturityDate">วันที่ครบกำหนด</label><input class="form-control" type="date" id="contractMaturityDate" name="maturity_date" value="<?= e($selectedContract['maturity_date'] ?? '') ?>"></div>
        <div class="auth-field"><label class="form-label" for="contractStatus">สถานะถัดไป</label><select class="form-select" id="contractStatus" name="contract_status" required><?php foreach (post_approval_contract_transitions($selectedContract['status']) as $status): ?><option value="<?= e($status) ?>"<?= $status === $selectedContract['status'] ? ' selected' : '' ?>><?= e($contractStatusLabels[$status] ?? $status) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกข้อมูลสัญญา</button></div>
    </form>
    <?php endif; ?>

    <?php if (account_can($currentUser, 'contracts.manage') && in_array($selectedContract['status'], ['signed', 'pending_disbursement'], true)): ?>
    <form class="review-decision-form" action="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>" method="post" data-confirm="ยืนยันว่ามีการเบิกจ่ายแล้วและเปิดใช้งานสัญญานี้?">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="activate_contract"><input type="hidden" name="contract_id" value="<?= (int) $selectedContract['id'] ?>">
      <div class="form-section-heading"><div><h3>บันทึกการเบิกจ่าย</h3><p>เมื่อยืนยันแล้ว สัญญาจะเข้าสู่ขั้นติดตามการชำระ</p></div></div>
      <div class="tracking-form-grid compact"><div class="auth-field"><label class="form-label" for="disbursedAt">วันและเวลาเบิกจ่าย</label><input class="form-control" type="datetime-local" id="disbursedAt" name="disbursed_at" value="<?= e($defaultDatetimeInput) ?>" required></div></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('wallet') ?> ยืนยันการเบิกจ่ายและเปิดสัญญา</button></div>
    </form>
    <?php endif; ?>

    <?php if (account_can($currentUser, 'contracts.manage') && $selectedContract['status'] === 'active'): ?>
    <form class="review-decision-form" action="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="save_schedule"><input type="hidden" name="contract_id" value="<?= (int) $selectedContract['id'] ?>"><input type="hidden" name="schedule_id" value="<?= $scheduleEdit ? (int) $scheduleEdit['id'] : '' ?>">
      <div class="form-section-heading"><div><h3><?= $scheduleEdit ? 'แก้ไขงวดชำระ' : 'เพิ่มงวดชำระ' ?></h3><p>บันทึกยอดตามตารางแนบท้ายสัญญา ระบบจะไม่คำนวณรูปแบบดอกเบี้ยแทนเอกสารสัญญา</p></div><?php if ($scheduleEdit): ?><a class="text-link" href="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>">ยกเลิกแก้ไข</a><?php endif; ?></div>
      <div class="tracking-form-grid schedule-grid">
        <div class="auth-field"><label class="form-label" for="installmentNo">งวดที่</label><input class="form-control" type="number" id="installmentNo" name="installment_no" min="1" max="600" value="<?= e($scheduleEdit['installment_no'] ?? $nextInstallmentNo) ?>" required></div>
        <div class="auth-field"><label class="form-label" for="scheduleDueDate">วันครบกำหนด</label><input class="form-control" type="date" id="scheduleDueDate" name="due_date" value="<?= e($scheduleEdit['due_date'] ?? '') ?>" required></div>
        <div class="auth-field"><label class="form-label" for="principalDue">เงินต้น (บาท)</label><input class="form-control" type="number" id="principalDue" name="principal_due" min="0" step="0.01" value="<?= e($scheduleEdit['principal_due'] ?? '0.00') ?>" required></div>
        <div class="auth-field"><label class="form-label" for="interestDue">ดอกเบี้ย (บาท)</label><input class="form-control" type="number" id="interestDue" name="interest_due" min="0" step="0.01" value="<?= e($scheduleEdit['interest_due'] ?? '0.00') ?>" required></div>
        <div class="auth-field"><label class="form-label" for="penaltyDue">ค่าปรับ (บาท)</label><input class="form-control" type="number" id="penaltyDue" name="penalty_due" min="0" step="0.01" value="<?= e($scheduleEdit['penalty_due'] ?? '0.00') ?>" required></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('calendar') ?> <?= $scheduleEdit ? 'บันทึกการแก้ไข' : 'เพิ่มงวดชำระ' ?></button></div>
    </form>
    <?php endif; ?>

    <div class="loan-subsection">
      <div class="form-section-heading"><div><h3>ตารางชำระ</h3><p>ยอดและสถานะแยกตามงวด</p></div><span class="loan-document-count"><?= count($repaymentSchedules) ?> งวด</span></div>
      <?php if (!$repaymentSchedules): ?><div class="loan-document-empty"><?= app_icon('calendar') ?><p>ยังไม่มีตารางชำระ</p></div><?php else: ?>
      <div class="tracking-table-wrap"><table class="table tracking-table mb-0"><thead><tr><th>งวด</th><th>ครบกำหนด</th><th class="amount-cell">เงินต้น</th><th class="amount-cell">ดอกเบี้ย</th><th class="amount-cell">ค่าปรับ</th><th>สถานะ</th><?php if (account_can($currentUser, 'contracts.manage')): ?><th></th><?php endif; ?></tr></thead><tbody><?php foreach ($repaymentSchedules as $schedule): ?><tr><td><?= (int) $schedule['installment_no'] ?></td><td><?= e(post_approval_display_date($schedule['due_date'])) ?></td><td class="amount-cell"><?= e(number_format((float) $schedule['principal_due'], 2)) ?></td><td class="amount-cell"><?= e(number_format((float) $schedule['interest_due'], 2)) ?></td><td class="amount-cell"><?= e(number_format((float) $schedule['penalty_due'], 2)) ?></td><td><span class="status-badge status-<?= $schedule['status'] === 'paid' ? 'success' : ($schedule['status'] === 'overdue' ? 'danger' : 'warning') ?>"><?= e($scheduleStatusLabels[$schedule['status']] ?? $schedule['status']) ?></span></td><?php if (account_can($currentUser, 'contracts.manage')): ?><td><?php if ((float) $schedule['principal_paid'] + (float) $schedule['interest_paid'] + (float) $schedule['penalty_paid'] <= 0 && $selectedContract['status'] === 'active'): ?><a class="btn btn-sm btn-outline-primary" href="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>&amp;schedule=<?= (int) $schedule['id'] ?>">แก้ไข</a><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="surface records-section" aria-label="รายการสัญญาเงินกู้">
  <div class="records-toolbar">
    <div class="search-field"><label class="visually-hidden" for="recordSearch">ค้นหาสัญญา</label><?= app_icon('search') ?><input class="form-control" type="search" id="recordSearch" placeholder="ชื่อหน่วยงาน เลขที่สัญญา หรือเลขที่คำขอ" autocomplete="off"></div>
    <div class="status-filter"><label class="visually-hidden" for="statusFilter">กรองสถานะ</label><select class="form-select" id="statusFilter"><option value="">ทุกสถานะ</option><?php foreach ($contractStatusLabels as $status => $label): ?><option value="<?= e($status) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="results-summary"><span id="resultCount" role="status" aria-live="polite">ทั้งหมด <?= count($contractRows) ?> รายการ</span><button class="btn reset-filter" type="button" id="resetFilters" hidden>ล้างตัวกรอง</button></div>
  <div class="records-wrap">
    <?php if (!$contractRows): ?><div class="empty-state"><?= app_icon('document') ?><h2>ยังไม่มีสัญญาเงินกู้</h2><p>เมื่อคำขอกู้ได้รับอนุมัติ ระบบจะสร้างรายการรอจัดทำสัญญาที่นี่</p></div><?php else: ?>
    <table class="table records-table mb-0" id="recordsTable"><thead><tr><th>หน่วยงาน / เลขที่สัญญา</th><th>เลขที่คำขอ</th><th class="amount-cell">เงินต้นคงเหลือ</th><th>ครบกำหนดสัญญา</th><th>สถานะ</th><th data-no-export></th></tr></thead><tbody><?php foreach ($contractRows as $contract): ?><tr data-record data-status="<?= e($contract['status']) ?>"><td class="record-primary"><strong><?= e($contract['organization_name']) ?></strong><small><?= e($contract['contract_no'] ?: 'รอระบุเลขที่สัญญา') ?></small></td><td data-label="เลขที่คำขอ"><?= e($contract['application_no']) ?></td><td data-label="เงินต้นคงเหลือ" class="amount-cell"><?= e(number_format((float) $contract['outstanding_principal'], 2)) ?><span class="mobile-unit"> บาท</span></td><td data-label="ครบกำหนด"><?= e(post_approval_display_date($contract['maturity_date'])) ?></td><td data-label="สถานะ"><span class="status-badge status-<?= e($contractTone($contract['status'])) ?>"><?= e($contractStatusLabels[$contract['status']] ?? $contract['status']) ?></span></td><td class="record-action" data-no-export><a class="btn record-button" href="dashboard.php?page=contracts&amp;contract=<?= (int) $contract['id'] ?>">รายละเอียด <?= app_icon('chevron') ?></a></td></tr><?php endforeach; ?></tbody></table>
    <div class="empty-state" id="emptyState" hidden><?= app_icon('search') ?><h2>ไม่พบสัญญาที่ค้นหา</h2><p>กรุณาเปลี่ยนคำค้นหาหรือสถานะ</p><button class="btn btn-outline-primary" type="button" data-reset-filters>ล้างตัวกรอง</button></div>
    <?php endif; ?>
  </div>
</section>
