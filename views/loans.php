<?php
$loanStatuses = [
    'draft' => 'ฉบับร่าง',
    'submitted' => 'ส่งตรวจแล้ว',
    'promotion_review' => 'เจ้าหน้าที่ส่งเสริมกำลังตรวจ',
    'revision_requested' => 'ส่งกลับแก้ไข',
    'province_review' => 'รอจังหวัดตรวจสอบ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ไม่อนุมัติ',
    'cancelled' => 'ยกเลิก',
];
$contractStatuses = [
    'awaiting_preparation' => 'รอจัดทำสัญญา', 'draft' => 'ร่างสัญญา', 'pending_review' => 'รอตรวจสัญญา',
    'awaiting_signature' => 'รอลงนาม', 'signed' => 'ลงนามแล้ว', 'pending_disbursement' => 'รอเบิกจ่าย',
    'active' => 'อยู่ระหว่างสัญญา', 'completed' => 'ชำระครบ', 'closed' => 'ปิดสัญญา', 'cancelled' => 'ยกเลิก',
];
$showLoanForm = $currentUser['role_code'] === 'cooperative_user' && ($newLoan || ($loanEditId && $selectedLoan && in_array($selectedLoan['status'], ['draft', 'revision_requested'], true)));
$formLoan = $selectedLoan ?: [];
$formGuarantors = $loanGuarantors ?: [['full_name' => '', 'relationship' => '', 'phone' => '', 'guarantee_amount' => '']];
?>
<?php if ($loanNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($loanNotice) ?></div><?php endif; ?>
<?php if ($loanError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($loanError) ?></div><?php endif; ?>

<?php if ($showLoanForm): ?>
<section class="surface loan-form-panel" aria-labelledby="loanFormTitle">
  <div class="surface-heading"><div><h2 id="loanFormTitle"><?= $loanEditId ? 'แก้ไขคำขอกู้' : 'สร้างคำขอกู้ใหม่' ?></h2><p>บันทึกข้อมูลเป็นฉบับร่างก่อนส่งให้เจ้าหน้าที่ตรวจสอบ</p></div><a class="text-link" href="dashboard.php?page=loans">ปิด</a></div>
  <form class="loan-form" action="dashboard.php?page=loans<?= $loanEditId ? '&amp;edit=' . (int) $loanEditId : '&amp;action=new' ?>" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="save_application"><input type="hidden" name="application_id" value="<?= $loanEditId ? (int) $loanEditId : '' ?>">
    <div class="auth-field"><label class="form-label" for="loanPurpose">วัตถุประสงค์การกู้</label><textarea class="form-control" id="loanPurpose" name="purpose" rows="3" maxlength="500" required><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('purpose') : ($formLoan['purpose'] ?? '')) ?></textarea></div>
    <div class="loan-field-grid">
      <div class="auth-field"><label class="form-label" for="requestedAmount">วงเงินที่ขอ (บาท)</label><input class="form-control" type="number" id="requestedAmount" name="requested_amount" min="0.01" step="0.01" value="<?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('requested_amount') : ($formLoan['requested_amount'] ?? '')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="proposedRate">อัตราดอกเบี้ยต่อปี (%)</label><input class="form-control" type="number" id="proposedRate" name="proposed_interest_rate" min="0" max="100" step="0.0001" value="<?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('proposed_interest_rate') : ($formLoan['proposed_interest_rate'] ?? '')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="termMonths">ระยะเวลาชำระ (เดือน)</label><input class="form-control" type="number" id="termMonths" name="term_months" min="1" max="600" value="<?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('term_months') : ($formLoan['term_months'] ?? '')) ?>" required></div>
    </div>
    <div class="auth-field"><label class="form-label" for="collateral">หลักประกัน / รายละเอียดเพิ่มเติม</label><textarea class="form-control" id="collateral" name="collateral" rows="3" maxlength="5000"><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('collateral') : ($formLoan['collateral'] ?? '')) ?></textarea></div>

    <div class="form-section-heading"><div><h3>ผู้ค้ำประกัน</h3><p>เพิ่มได้มากกว่าหนึ่งคน</p></div><button class="btn btn-outline-primary" type="button" data-add-guarantor><?= app_icon('users') ?> เพิ่มผู้ค้ำ</button></div>
    <div class="guarantor-list" id="guarantorList">
      <?php foreach ($formGuarantors as $guarantor): ?>
      <div class="guarantor-row" data-guarantor-row>
        <div><label class="form-label">ชื่อ–นามสกุล</label><input class="form-control" name="guarantor_name[]" maxlength="200" value="<?= e($guarantor['full_name'] ?? '') ?>"></div>
        <div><label class="form-label">ความสัมพันธ์</label><input class="form-control" name="guarantor_relationship[]" maxlength="100" value="<?= e($guarantor['relationship'] ?? '') ?>"></div>
        <div><label class="form-label">โทรศัพท์</label><input class="form-control" name="guarantor_phone[]" maxlength="30" value="<?= e($guarantor['phone'] ?? '') ?>"></div>
        <div><label class="form-label">วงเงินค้ำประกัน</label><input class="form-control" type="number" min="0" step="0.01" name="guarantor_amount[]" value="<?= e($guarantor['guarantee_amount'] ?? '') ?>"></div>
        <button class="btn guarantor-remove" type="button" data-remove-guarantor>ลบ</button>
      </div>
      <?php endforeach; ?>
    </div>
    <template id="guarantorTemplate"><div class="guarantor-row" data-guarantor-row><div><label class="form-label">ชื่อ–นามสกุล</label><input class="form-control" name="guarantor_name[]" maxlength="200"></div><div><label class="form-label">ความสัมพันธ์</label><input class="form-control" name="guarantor_relationship[]" maxlength="100"></div><div><label class="form-label">โทรศัพท์</label><input class="form-control" name="guarantor_phone[]" maxlength="30"></div><div><label class="form-label">วงเงินค้ำประกัน</label><input class="form-control" type="number" min="0" step="0.01" name="guarantor_amount[]"></div><button class="btn guarantor-remove" type="button" data-remove-guarantor>ลบ</button></div></template>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกฉบับร่าง</button><a class="btn btn-outline-primary" href="dashboard.php?page=loans">ยกเลิก</a></div>
  </form>
</section>
<?php endif; ?>

<?php if ($selectedLoan && !$showLoanForm): ?>
<section class="surface loan-detail-panel mb-4">
  <div class="surface-heading"><div><h2><?= e($selectedLoan['application_no']) ?></h2><p><?= e($selectedLoan['organization_name']) ?></p></div><a class="text-link" href="dashboard.php?page=loans">ปิด</a></div>
  <div class="loan-detail-body">
    <div class="loan-status-line"><span class="status-badge status-<?= $selectedLoan['status'] === 'approved' ? 'success' : ($selectedLoan['status'] === 'revision_requested' || $selectedLoan['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e($loanStatuses[$selectedLoan['status']] ?? $selectedLoan['status']) ?></span><?php if ($selectedLoan['contract_status']): ?><span class="status-badge status-neutral">สัญญา: <?= e($contractStatuses[$selectedLoan['contract_status']] ?? $selectedLoan['contract_status']) ?></span><?php endif; ?><?php if ($selectedLoan['contract_id']): ?><a class="btn btn-sm btn-outline-primary" href="dashboard.php?page=contracts&amp;contract=<?= (int) $selectedLoan['contract_id'] ?>"><?= app_icon('file-check') ?> ดูสัญญา</a><?php endif; ?></div>
    <dl class="loan-detail-grid"><div><dt>วัตถุประสงค์</dt><dd><?= e($selectedLoan['purpose']) ?></dd></div><div><dt>วงเงินที่ขอ</dt><dd><?= e(number_format((float) $selectedLoan['requested_amount'], 2)) ?> บาท</dd></div><div><dt>อัตราดอกเบี้ยที่เสนอ</dt><dd><?= e(number_format((float) $selectedLoan['proposed_interest_rate'], 4)) ?>%</dd></div><div><dt>ระยะเวลา</dt><dd><?= (int) $selectedLoan['term_months'] ?> เดือน</dd></div><div><dt>เจ้าหน้าที่ส่งเสริม</dt><dd><?= e($selectedLoan['officer_name'] ?? 'รอมอบหมาย') ?></dd></div><div><dt>หลักประกัน</dt><dd><?= e($selectedLoan['collateral'] ?? '—') ?></dd></div><?php if ($selectedLoan['approved_amount'] !== null): ?><div><dt>วงเงินอนุมัติ</dt><dd><?= e(number_format((float) $selectedLoan['approved_amount'], 2)) ?> บาท</dd></div><div><dt>อัตราดอกเบี้ยอนุมัติ</dt><dd><?= e(number_format((float) $selectedLoan['approved_interest_rate'], 4)) ?>%</dd></div><?php endif; ?></dl>

    <div class="loan-subsection"><h3>ผู้ค้ำประกัน</h3><?php if (!$loanGuarantors): ?><p class="text-secondary">ยังไม่ได้ระบุผู้ค้ำประกัน</p><?php else: ?><div class="guarantor-summary"><?php foreach ($loanGuarantors as $guarantor): ?><div><strong><?= e($guarantor['full_name']) ?></strong><span><?= e($guarantor['relationship'] ?? 'ไม่ระบุความสัมพันธ์') ?><?= $guarantor['guarantee_amount'] !== null ? ' · ' . number_format((float) $guarantor['guarantee_amount'], 2) . ' บาท' : '' ?></span></div><?php endforeach; ?></div><?php endif; ?></div>

    <div class="loan-subsection loan-document-summary">
      <div class="loan-document-summary-copy">
        <span class="loan-document-summary-icon" aria-hidden="true"><?= app_icon('folder') ?></span>
        <div><h3>เอกสารคำขอกู้</h3><p>เอกสารถูกแยกไปจัดการในรายการเฉพาะ เพื่อไม่ให้ปะปนกับการแก้ไขข้อมูลคำขอ</p><div class="loan-document-summary-counts"><span>ปัจจุบัน <strong><?= (int) $selectedLoan['current_document_count'] ?></strong></span><span>รอตรวจ <strong><?= (int) $selectedLoan['uploaded_document_count'] ?></strong></span><span>ต้องแก้ไข <strong><?= (int) $selectedLoan['revision_document_count'] ?></strong></span><span>ผ่านแล้ว <strong><?= (int) $selectedLoan['verified_document_count'] ?></strong></span></div></div>
      </div>
      <a class="btn btn-outline-primary" href="dashboard.php?page=loan-documents&amp;loan=<?= (int) $selectedLoan['id'] ?>"><?= app_icon('folder') ?> จัดการเอกสาร</a>
    </div>

    <?php if ($currentUser['role_code'] === 'cooperative_user' && in_array($selectedLoan['status'], ['draft', 'revision_requested'], true)): ?><div class="form-actions"><a class="btn btn-outline-primary" href="dashboard.php?page=loans&amp;edit=<?= (int) $selectedLoan['id'] ?>">แก้ไขข้อมูลคำขอ</a><form action="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="submit_application"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><button class="btn btn-primary" type="submit"><?= app_icon('arrow') ?> <?= $selectedLoan['status'] === 'revision_requested' ? 'ส่งให้เจ้าหน้าที่ตรวจสอบอีกครั้ง' : 'ส่งให้เจ้าหน้าที่ตรวจสอบ' ?></button></form></div><?php endif; ?>

    <?php if ($currentUser['role_code'] === 'promotion_officer' && in_array($selectedLoan['status'], ['submitted', 'promotion_review'], true) && (int) $selectedLoan['assigned_officer_id'] === (int) $currentUser['id']): ?><form class="review-decision-form" action="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="promotion_review"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><label class="form-label" for="promotionComment">ความเห็นของเจ้าหน้าที่ส่งเสริม</label><textarea class="form-control" id="promotionComment" name="review_comment" rows="4" maxlength="2000" required></textarea><div class="form-actions"><button class="btn btn-outline-danger" name="decision" value="request_revision" type="submit">ส่งกลับแก้ไข</button><button class="btn btn-primary" name="decision" value="forward" type="submit"><?= app_icon('arrow') ?> ส่งให้จังหวัดตรวจสอบ</button></div></form><?php endif; ?>

    <?php if ($currentUser['role_code'] === 'provincial_officer' && $selectedLoan['status'] === 'province_review'): ?><form class="review-decision-form" action="dashboard.php?page=loans&amp;view=<?= (int) $selectedLoan['id'] ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="province_review"><input type="hidden" name="application_id" value="<?= (int) $selectedLoan['id'] ?>"><div class="loan-field-grid"><div class="auth-field"><label class="form-label" for="approvedAmount">วงเงินอนุมัติ (บาท)</label><input class="form-control" type="number" id="approvedAmount" name="approved_amount" min="0.01" step="0.01" value="<?= e($selectedLoan['requested_amount']) ?>"></div><div class="auth-field"><label class="form-label" for="approvedRate">อัตราดอกเบี้ยอนุมัติ (%)</label><input class="form-control" type="number" id="approvedRate" name="approved_interest_rate" min="0" max="100" step="0.0001" value="<?= e($selectedLoan['proposed_interest_rate']) ?>"></div></div><label class="form-label" for="provinceComment">ความเห็นของเจ้าหน้าที่จังหวัด</label><textarea class="form-control" id="provinceComment" name="review_comment" rows="4" maxlength="2000" required></textarea><div class="form-actions"><button class="btn btn-outline-danger" name="decision" value="reject" type="submit">ไม่อนุมัติ</button><button class="btn btn-outline-primary" name="decision" value="request_revision" type="submit">ส่งกลับแก้ไข</button><button class="btn btn-primary" name="decision" value="approve" type="submit"><?= app_icon('check') ?> อนุมัติและสร้างร่างสัญญา</button></div></form><?php endif; ?>

    <?php if ($loanReviews): ?><div class="loan-subsection"><h3>ประวัติการตรวจสอบ</h3><div class="review-timeline"><?php foreach ($loanReviews as $review): ?><article><span class="timeline-dot"></span><div><strong><?= e($review['reviewer_name']) ?></strong><small><?= e($review['review_stage'] === 'promotion' ? 'เจ้าหน้าที่ส่งเสริม' : 'เจ้าหน้าที่จังหวัด') ?> · <?= e($review['created_at']) ?> UTC</small><p><?= e($review['comment'] ?? '') ?></p></div></article><?php endforeach; ?></div></div><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="surface records-section" aria-label="รายการคำขอกู้">
  <div class="loan-list-heading"><div><strong>คำขอกู้ทั้งหมด</strong><span><?= count($loanRows) ?> รายการ</span></div><?php if ($currentUser['role_code'] === 'cooperative_user'): ?><?php if ($cooperativeProfileComplete): ?><a class="btn btn-primary" href="dashboard.php?page=loans&amp;action=new"><?= app_icon('document') ?> สร้างคำขอกู้</a><?php else: ?><a class="btn btn-outline-primary" href="dashboard.php?page=registry">กรอกข้อมูลหน่วยงานก่อน</a><?php endif; ?><?php endif; ?></div>
  <?php if (!$loanRows): ?><div class="empty-state"><?= app_icon('document') ?><h2>ยังไม่มีคำขอกู้</h2><p>เมื่อสร้างหรือได้รับมอบหมายคำขอ รายการจะแสดงที่นี่</p></div><?php else: ?><div class="records-wrap"><table class="table records-table mb-0" id="recordsTable"><thead><tr><th>หน่วยงาน / เลขที่คำขอ</th><th>วัตถุประสงค์</th><th class="amount-cell">วงเงินที่ขอ</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th><th data-no-export></th></tr></thead><tbody><?php foreach ($loanRows as $loan): ?><tr><td class="record-primary"><strong><?= e($loan['organization_name']) ?></strong><small><?= e($loan['application_no']) ?></small></td><td data-label="วัตถุประสงค์"><?= e($loan['purpose']) ?></td><td data-label="วงเงิน" class="amount-cell"><?= e(number_format((float) $loan['requested_amount'], 2)) ?> บาท</td><td data-label="ผู้รับผิดชอบ"><?= e($loan['officer_name'] ?? 'รอมอบหมาย') ?></td><td data-label="สถานะ"><span class="status-badge status-<?= $loan['status'] === 'approved' ? 'success' : ($loan['status'] === 'revision_requested' || $loan['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e($loanStatuses[$loan['status']] ?? $loan['status']) ?></span></td><td class="record-action" data-no-export><a class="btn record-button" href="dashboard.php?page=loans&amp;view=<?= (int) $loan['id'] ?>">รายละเอียด <?= app_icon('chevron') ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
