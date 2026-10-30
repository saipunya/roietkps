<?php
$debtStatus = function (array $contract): array {
    if ((int) $contract['overdue_count'] > 0) {
        return ['overdue', 'ค้างชำระ', 'danger'];
    }
    if ($contract['next_due_date']) {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok'));
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $contract['next_due_date'], new DateTimeZone('Asia/Bangkok'));
        if ($due && (int) $today->diff($due)->format('%r%a') <= 30) {
            return ['due_soon', 'ใกล้ครบกำหนด', 'warning'];
        }
    }
    if ($contract['status'] === 'completed' || $contract['status'] === 'closed') {
        return ['completed', 'ชำระครบ', 'success'];
    }
    return ['normal', 'ปกติ', 'success'];
};
$trackingContracts = array_values(array_filter($contractRows, function ($contract) {
    return in_array($contract['status'], ['active', 'completed', 'closed'], true);
}));
?>
<?php if ($trackingNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($trackingNotice) ?></div><?php endif; ?>
<?php if ($trackingError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($trackingError) ?></div><?php endif; ?>
<?php if (!$trackingSchemaReady && account_can($currentUser, 'debt.record_follow_up')): ?><div class="alert alert-warning" role="alert">ยังไม่ได้ติดตั้งตารางบันทึกผลติดตาม กรุณานำเข้าไฟล์ <strong>database/009_post_approval_tracking.sql</strong></div><?php endif; ?>

<section class="tracking-summary-grid" aria-label="สรุปการติดตามหนี้">
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-green"><?= app_icon('check') ?></span><div><small>สัญญาที่ใช้งาน</small><strong><?= (int) $trackingSummary['active'] ?><span> สัญญา</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-red"><?= app_icon('alert') ?></span><div><small>งวดค้างชำระ</small><strong><?= (int) $trackingSummary['overdue'] ?><span> งวด</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-amber"><?= app_icon('calendar') ?></span><div><small>รายการติดตาม</small><strong><?= count($trackingContracts) ?><span> สัญญา</span></strong></div></article>
  <article class="tracking-summary-card"><span class="tracking-summary-icon tone-blue"><?= app_icon('wallet') ?></span><div><small>เงินต้นคงเหลือ</small><strong><?= e(number_format((float) $trackingSummary['outstanding'], 2)) ?><span> บาท</span></strong></div></article>
</section>

<?php if ($selectedContract && in_array($selectedContract['status'], ['active', 'completed', 'closed'], true)): ?>
<?php $selectedDebtState = $debtStatus($selectedContract); ?>
<section class="surface tracking-detail-panel mb-4" aria-labelledby="debtDetailTitle">
  <div class="surface-heading tracking-heading"><div><h2 id="debtDetailTitle"><?= e($selectedContract['contract_no'] ?: $selectedContract['application_no']) ?></h2><p><?= e($selectedContract['organization_name']) ?> · กำหนดชำระ <?= e(post_approval_display_date($selectedContract['next_due_date'])) ?></p></div><div class="tracking-heading-actions"><a class="btn btn-outline-primary" href="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedContract['id'] ?>">ดูสัญญา</a><a class="text-link" href="dashboard.php?page=debt">ปิด</a></div></div>
  <div class="tracking-detail-body">
    <div class="loan-status-line"><span class="status-badge status-<?= e($selectedDebtState[2]) ?>"><?= e($selectedDebtState[1]) ?></span><span class="status-badge status-neutral">เงินต้นคงเหลือ <?= e(number_format((float) $selectedContract['outstanding_principal'], 2)) ?> บาท</span></div>

    <div class="loan-subsection tracking-first-section">
      <div class="form-section-heading"><div><h3>ตารางชำระและสถานะรายงวด</h3><p>เปรียบเทียบยอดตามกำหนดกับยอดที่บันทึกรับชำระแล้ว</p></div><span class="loan-document-count"><?= count($repaymentSchedules) ?> งวด</span></div>
      <?php if (!$repaymentSchedules): ?><div class="loan-document-empty"><?= app_icon('calendar') ?><p>ยังไม่มีตารางชำระ กรุณาให้เจ้าหน้าที่จังหวัดเพิ่มตารางชำระในหน้าสัญญาเงินกู้</p></div><?php else: ?>
      <div class="tracking-table-wrap"><table class="table tracking-table mb-0"><thead><tr><th>งวด</th><th>ครบกำหนด</th><th class="amount-cell">ยอดตามกำหนด</th><th class="amount-cell">รับชำระแล้ว</th><th class="amount-cell">คงค้างงวดนี้</th><th>สถานะ</th></tr></thead><tbody><?php foreach ($repaymentSchedules as $schedule): ?><?php $dueTotal = (float) $schedule['principal_due'] + (float) $schedule['interest_due'] + (float) $schedule['penalty_due']; $paidTotal = (float) $schedule['principal_paid'] + (float) $schedule['interest_paid'] + (float) $schedule['penalty_paid']; ?><tr><td><?= (int) $schedule['installment_no'] ?></td><td><?= e(post_approval_display_date($schedule['due_date'])) ?></td><td class="amount-cell"><?= e(number_format($dueTotal, 2)) ?></td><td class="amount-cell"><?= e(number_format($paidTotal, 2)) ?></td><td class="amount-cell"><?= e(number_format(max(0, $dueTotal - $paidTotal), 2)) ?></td><td><span class="status-badge status-<?= $schedule['status'] === 'paid' ? 'success' : ($schedule['status'] === 'overdue' ? 'danger' : 'warning') ?>"><?= e($scheduleStatusLabels[$schedule['status']] ?? $schedule['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>

    <?php if (account_can($currentUser, 'repayments.record') && $selectedContract['status'] === 'active'): ?>
    <form class="review-decision-form" action="dashboard.php?page=debt&amp;contract=<?= (int) $selectedContract['id'] ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="record_repayment"><input type="hidden" name="contract_id" value="<?= (int) $selectedContract['id'] ?>">
      <div class="form-section-heading"><div><h3>บันทึกรับชำระ</h3><p>แยกเงินต้น ดอกเบี้ย และค่าปรับตามหลักฐานการรับเงิน</p></div></div>
      <div class="tracking-form-grid payment-grid">
        <div class="auth-field"><label class="form-label" for="paymentSchedule">งวดที่เกี่ยวข้อง</label><select class="form-select" id="paymentSchedule" name="schedule_id" data-payment-schedule><option value="">ไม่ระบุงวด</option><?php foreach ($repaymentSchedules as $schedule): ?><?php if (!in_array($schedule['status'], ['paid', 'waived'], true)): ?><option value="<?= (int) $schedule['id'] ?>" data-principal="<?= e(max(0, (float) $schedule['principal_due'] - (float) $schedule['principal_paid'])) ?>" data-interest="<?= e(max(0, (float) $schedule['interest_due'] - (float) $schedule['interest_paid'])) ?>" data-penalty="<?= e(max(0, (float) $schedule['penalty_due'] - (float) $schedule['penalty_paid'])) ?>">งวด <?= (int) $schedule['installment_no'] ?> · <?= e(post_approval_display_date($schedule['due_date'])) ?></option><?php endif; ?><?php endforeach; ?></select></div>
        <div class="auth-field"><label class="form-label" for="receiptNo">เลขที่ใบรับ</label><input class="form-control" id="receiptNo" name="receipt_no" maxlength="50" placeholder="เช่น RC-2569-001" required></div>
        <div class="auth-field"><label class="form-label" for="paidAt">วันและเวลารับชำระ</label><input class="form-control" type="datetime-local" id="paidAt" name="paid_at" value="<?= e($defaultDatetimeInput) ?>" required></div>
        <div class="auth-field"><label class="form-label" for="principalAmount">เงินต้น (บาท)</label><input class="form-control" type="number" id="principalAmount" name="principal_amount" min="0" max="<?= e($selectedContract['outstanding_principal']) ?>" step="0.01" value="0.00" data-payment-principal required></div>
        <div class="auth-field"><label class="form-label" for="interestAmount">ดอกเบี้ย (บาท)</label><input class="form-control" type="number" id="interestAmount" name="interest_amount" min="0" step="0.01" value="0.00" data-payment-interest required></div>
        <div class="auth-field"><label class="form-label" for="penaltyAmount">ค่าปรับ (บาท)</label><input class="form-control" type="number" id="penaltyAmount" name="penalty_amount" min="0" step="0.01" value="0.00" data-payment-penalty required></div>
        <div class="auth-field"><label class="form-label" for="paymentChannel">ช่องทางรับชำระ</label><select class="form-select" id="paymentChannel" name="payment_channel"><option value="โอนเงิน">โอนเงิน</option><option value="เช็ค">เช็ค</option><option value="เงินสด">เงินสด</option><option value="ช่องทางอื่น">ช่องทางอื่น</option></select></div>
        <div class="auth-field tracking-wide-field"><label class="form-label" for="paymentNote">หมายเหตุ</label><textarea class="form-control" id="paymentNote" name="payment_note" rows="2" maxlength="1000"></textarea></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('wallet') ?> บันทึกรับชำระ</button></div>
    </form>
    <?php endif; ?>

    <?php if (account_can($currentUser, 'debt.record_follow_up') && $trackingSchemaReady && in_array($selectedContract['status'], ['active', 'completed'], true)): ?>
    <form class="review-decision-form" action="dashboard.php?page=debt&amp;contract=<?= (int) $selectedContract['id'] ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="record_follow_up"><input type="hidden" name="contract_id" value="<?= (int) $selectedContract['id'] ?>">
      <div class="form-section-heading"><div><h3>บันทึกผลการติดตาม</h3><p>บันทึกทุกครั้งที่ติดต่อหรือเข้าพบหน่วยงาน พร้อมกำหนดวันติดตามครั้งถัดไป</p></div></div>
      <div class="tracking-form-grid follow-up-grid">
        <div class="auth-field"><label class="form-label" for="followUpSchedule">งวดที่เกี่ยวข้อง</label><select class="form-select" id="followUpSchedule" name="schedule_id"><option value="">ภาพรวมสัญญา</option><?php foreach ($repaymentSchedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>">งวด <?= (int) $schedule['installment_no'] ?> · <?= e($scheduleStatusLabels[$schedule['status']] ?? $schedule['status']) ?></option><?php endforeach; ?></select></div>
        <div class="auth-field"><label class="form-label" for="followedUpAt">วันและเวลาติดตาม</label><input class="form-control" type="datetime-local" id="followedUpAt" name="followed_up_at" value="<?= e($defaultDatetimeInput) ?>" required></div>
        <div class="auth-field"><label class="form-label" for="followUpChannel">ช่องทาง</label><select class="form-select" id="followUpChannel" name="follow_up_channel" required><?php foreach ($followUpChannelLabels as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="auth-field"><label class="form-label" for="followUpOutcome">ผลการติดตาม</label><select class="form-select" id="followUpOutcome" name="follow_up_outcome" required><?php foreach ($followUpOutcomeLabels as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="auth-field"><label class="form-label" for="nextFollowUpDate">ติดตามครั้งถัดไป</label><input class="form-control" type="date" id="nextFollowUpDate" name="next_follow_up_date"></div>
        <div class="auth-field tracking-wide-field"><label class="form-label" for="followUpNote">รายละเอียด</label><textarea class="form-control" id="followUpNote" name="follow_up_note" rows="3" maxlength="2000" required></textarea></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกผลติดตาม</button></div>
    </form>
    <?php endif; ?>

    <div class="tracking-history-grid">
      <section class="loan-subsection"><h3>ประวัติรับชำระ</h3><?php if (!$repaymentRows): ?><p class="text-secondary mt-2">ยังไม่มีรายการรับชำระ</p><?php else: ?><div class="tracking-history-list"><?php foreach ($repaymentRows as $payment): ?><article><span class="tracking-history-icon tone-green"><?= app_icon('wallet') ?></span><div><strong><?= e(number_format((float) $payment['principal_amount'] + (float) $payment['interest_amount'] + (float) $payment['penalty_amount'], 2)) ?> บาท</strong><small><?= e($payment['receipt_no']) ?> · <?= e(post_approval_display_datetime($payment['paid_at'])) ?><?= $payment['installment_no'] ? ' · งวด ' . (int) $payment['installment_no'] : '' ?></small><p>เงินต้น <?= e(number_format((float) $payment['principal_amount'], 2)) ?> · ดอกเบี้ย <?= e(number_format((float) $payment['interest_amount'], 2)) ?> · ค่าปรับ <?= e(number_format((float) $payment['penalty_amount'], 2)) ?></p><small>บันทึกโดย <?= e($payment['recorder_name']) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
      <section class="loan-subsection"><h3>ประวัติการติดตาม</h3><?php if (!$followUpRows): ?><p class="text-secondary mt-2">ยังไม่มีบันทึกผลติดตาม</p><?php else: ?><div class="tracking-history-list"><?php foreach ($followUpRows as $followUp): ?><article><span class="tracking-history-icon tone-amber"><?= app_icon('clock') ?></span><div><strong><?= e($followUpOutcomeLabels[$followUp['outcome']] ?? $followUp['outcome']) ?></strong><small><?= e($followUpChannelLabels[$followUp['channel']] ?? $followUp['channel']) ?> · <?= e(post_approval_display_datetime($followUp['followed_up_at'])) ?><?= $followUp['installment_no'] ? ' · งวด ' . (int) $followUp['installment_no'] : '' ?></small><p><?= nl2br(e($followUp['note'])) ?></p><?php if ($followUp['next_follow_up_date']): ?><small>ติดตามครั้งถัดไป <?= e(post_approval_display_date($followUp['next_follow_up_date'])) ?></small><?php endif; ?><small>บันทึกโดย <?= e($followUp['creator_name']) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="surface records-section" aria-label="รายการติดตามหนี้">
  <div class="records-toolbar"><div class="search-field"><label class="visually-hidden" for="recordSearch">ค้นหารายการติดตาม</label><?= app_icon('search') ?><input class="form-control" type="search" id="recordSearch" placeholder="ชื่อหน่วยงานหรือเลขที่สัญญา" autocomplete="off"></div><div class="status-filter"><label class="visually-hidden" for="statusFilter">กรองสถานะ</label><select class="form-select" id="statusFilter"><option value="">ทุกสถานะ</option><option value="overdue">ค้างชำระ</option><option value="due_soon">ใกล้ครบกำหนด</option><option value="normal">ปกติ</option><option value="completed">ชำระครบ</option></select></div></div>
  <div class="results-summary"><span id="resultCount" role="status" aria-live="polite">ทั้งหมด <?= count($trackingContracts) ?> รายการ</span><button class="btn reset-filter" type="button" id="resetFilters" hidden>ล้างตัวกรอง</button></div>
  <div class="records-wrap">
    <?php if (!$trackingContracts): ?><div class="empty-state"><?= app_icon('clock') ?><h2>ยังไม่มีสัญญาที่ต้องติดตาม</h2><p>เมื่อสัญญาได้รับการเบิกจ่าย รายการจะปรากฏที่นี่</p></div><?php else: ?>
    <table class="table records-table mb-0" id="recordsTable"><thead><tr><th>หน่วยงาน / เลขที่สัญญา</th><th class="amount-cell">เงินต้นคงเหลือ</th><th>กำหนดชำระถัดไป</th><th>ค้างชำระ</th><th>สถานะ</th><th data-no-export></th></tr></thead><tbody><?php foreach ($trackingContracts as $contract): ?><?php $state = $debtStatus($contract); ?><tr data-record data-status="<?= e($state[0]) ?>"><td class="record-primary"><strong><?= e($contract['organization_name']) ?></strong><small><?= e($contract['contract_no'] ?: $contract['application_no']) ?></small></td><td data-label="เงินต้นคงเหลือ" class="amount-cell"><?= e(number_format((float) $contract['outstanding_principal'], 2)) ?><span class="mobile-unit"> บาท</span></td><td data-label="กำหนดถัดไป"><?= e(post_approval_display_date($contract['next_due_date'])) ?></td><td data-label="ค้างชำระ"><?= (int) $contract['overdue_count'] ?> งวด</td><td data-label="สถานะ"><span class="status-badge status-<?= e($state[2]) ?>"><?= e($state[1]) ?></span></td><td class="record-action" data-no-export><a class="btn record-button" href="dashboard.php?page=debt&amp;contract=<?= (int) $contract['id'] ?>">ติดตาม <?= app_icon('chevron') ?></a></td></tr><?php endforeach; ?></tbody></table>
    <div class="empty-state" id="emptyState" hidden><?= app_icon('search') ?><h2>ไม่พบรายการที่ค้นหา</h2><p>กรุณาเปลี่ยนคำค้นหาหรือสถานะ</p><button class="btn btn-outline-primary" type="button" data-reset-filters>ล้างตัวกรอง</button></div>
    <?php endif; ?>
  </div>
</section>
<p class="data-footnote"><?= app_icon('info') ?> เงินต้นคงเหลือไม่รวมดอกเบี้ยและค่าปรับ ระบบคำนวณสถานะจากตารางชำระและรายการรับชำระที่บันทึกไว้</p>
