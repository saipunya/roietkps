<?php if (!isset($currentUser) || !account_is_admin($currentUser)) { http_response_code(403); exit; } ?>
<?php if ($approvalNotice): ?><div class="alert alert-success" role="status"><?= e($approvalNotice) ?></div><?php endif; ?>
<?php if ($approvalError): ?><div class="alert alert-danger" role="alert"><?= e($approvalError) ?></div><?php endif; ?>

<?php if ($reviewRequest):
    $selectedRole = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('role_code') : $reviewRequest['requested_role'];
    $selectedProvince = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('province_id') : '';
    $selectedCooperative = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('cooperative_id') : '';
?>
<section class="surface review-panel mb-4" aria-labelledby="reviewTitle">
  <div class="surface-heading"><div><h2 id="reviewTitle">พิจารณาคำขอสมัครสมาชิก</h2><p><?= e($reviewRequest['full_name']) ?> · <?= e($reviewRequest['username']) ?></p></div><a class="text-link" href="index.php?page=users">ปิด</a></div>
  <div class="review-body">
    <p><strong>หน่วยงาน:</strong> <?= e($reviewRequest['organization']) ?><br><strong>ประเภทหน่วยงาน:</strong> <?= e(organization_type_labels()[$reviewRequest['organization_type']] ?? $reviewRequest['organization_type']) ?><br><strong>อีเมล:</strong> <?= e($reviewRequest['email']) ?><br><strong>ประเภทบัญชี:</strong> <?= e(request_roles()[$reviewRequest['requested_role']]) ?></p>
    <form action="index.php?page=users" method="post" id="approvalForm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="user_id" value="<?= (int) $reviewRequest['id'] ?>">
      <div class="row g-3">
        <div class="col-12"><label class="form-label" for="approvalRole">บทบาทที่อนุมัติ</label><select class="form-select" name="role_code" id="approvalRole" required><?php foreach (assignable_roles() as $code => $name): ?><option value="<?= e($code) ?>"<?= $selectedRole === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="approvalProvinceField"><label class="form-label" for="approvalProvince">จังหวัดที่รับผิดชอบ</label><select class="form-select" name="province_id" id="approvalProvince"><option value="">เลือกจังหวัด</option><?php foreach ($approvalProvinces as $province): ?><option value="<?= (int) $province['id'] ?>"<?= $selectedProvince === (string) $province['id'] ? ' selected' : '' ?>><?= e($province['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="approvalCooperativeField"><label class="form-label" for="approvalCooperative">สหกรณ์ / กลุ่มเกษตรกร</label><select class="form-select" name="cooperative_id" id="approvalCooperative"><option value="">เลือกหน่วยงานเดิม</option><option value="new"<?= $selectedCooperative === 'new' ? ' selected' : '' ?>>สร้างหน่วยงานใหม่จากคำขอนี้</option><?php foreach ($approvalCooperatives as $coop): ?><option data-province="<?= (int) $coop['province_id'] ?>" value="<?= (int) $coop['id'] ?>"<?= $selectedCooperative === (string) $coop['id'] ? ' selected' : '' ?>><?= e($coop['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="reviewNote">หมายเหตุ / เหตุผลที่ไม่อนุมัติ</label><textarea class="form-control" name="review_note" id="reviewNote" rows="3" maxlength="500"><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('review_note') : '') ?></textarea><p class="field-help mt-2">เมื่อสร้างหน่วยงานใหม่ ระบบจะผูกบัญชีนี้กับหน่วยงานโดยอัตโนมัติ</p></div>
      </div>
      <div class="approval-actions mt-3"><button class="btn btn-primary" name="decision" value="approve" type="submit"><?= app_icon('check') ?> อนุมัติบัญชี</button><button class="btn btn-outline-danger" name="decision" value="reject" type="submit" formnovalidate>ไม่อนุมัติ</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="surface records-section" aria-label="รายการผู้ใช้งาน">
  <form class="records-toolbar" action="index.php" method="get"><input type="hidden" name="page" value="users"><div class="search-field"><label class="visually-hidden" for="userSearch">ค้นหาผู้ใช้งาน</label><?= app_icon('search') ?><input class="form-control" type="search" id="userSearch" name="q" value="<?= e($searchUsers) ?>" maxlength="100" placeholder="ชื่อ ชื่อผู้ใช้งาน หรือหน่วยงาน"></div><div class="status-filter"><label class="visually-hidden" for="userStatus">สถานะผู้ใช้งาน</label><select class="form-select" id="userStatus" name="status"><?php foreach ($userStatuses as $code => $label): ?><option value="<?= e($code) ?>"<?= $filterStatus === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline-primary" type="submit">ค้นหา</button></form>
  <div class="results-summary">พบ <?= e(number_format($userCount)) ?> บัญชี · หน้า <?= $userPage ?> / <?= $totalUserPages ?></div>
  <?php if (!$accountRows): ?><div class="empty-state"><h2><?= $filterStatus === 'pending' ? 'ไม่มีคำขอรออนุมัติ' : 'ไม่พบบัญชีผู้ใช้งาน' ?></h2><p>เมื่อมีผู้สมัครใหม่ คำขอจะแสดงที่นี่</p></div><?php else: ?>
  <div class="records-wrap"><table class="table records-table mb-0"><caption class="visually-hidden">บัญชีผู้ใช้งานและสถานะการอนุมัติ</caption><thead><tr><th scope="col">ผู้ใช้งาน</th><th scope="col">หน่วยงาน / ประเภทที่ขอ</th><th scope="col">สถานะ</th><th scope="col">การดำเนินการ</th></tr></thead><tbody>
  <?php foreach ($accountRows as $account): ?>
    <tr><td class="record-primary"><strong><?= e($account['full_name']) ?></strong><small><?= e($account['username']) ?></small><small><?= e($account['email'] ?? '') ?></small></td><td data-label="หน่วยงาน"><span><?= e($account['organization'] ?? '—') ?><small class="account-meta"><?= e($account['requested_role'] ? request_roles()[$account['requested_role']] : $account['role_name']) ?><?= $account['organization_type'] ? ' · ' . e(organization_type_labels()[$account['organization_type']] ?? $account['organization_type']) : '' ?></small></span></td><td data-label="สถานะ"><span class="status-badge <?= $account['status'] === 'active' ? 'status-success' : ($account['status'] === 'pending' ? 'status-warning' : 'status-danger') ?>"><?= e($userStatuses[$account['status']]) ?></span></td><td class="record-action"><?php if ($account['status'] === 'pending' && $account['requested_role']): ?><a class="btn record-button" href="index.php?page=users&amp;review=<?= (int) $account['id'] ?>">พิจารณาคำขอ <?= app_icon('chevron') ?></a><?php elseif ($account['reviewed_at']): ?><span class="account-meta">ผู้ตรวจ: <?= e($account['reviewer_name'] ?? '') ?><br><?= e($account['reviewed_at']) ?> UTC</span><?php if ($account['review_note']): ?><span class="account-meta review-note"><?= e($account['review_note']) ?></span><?php endif; ?><?php else: ?><span class="account-meta">บัญชีที่สร้างโดยผู้ดูแล</span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($totalUserPages > 1): ?><nav class="user-pagination" aria-label="หน้ารายการผู้ใช้"><?php foreach ([$userPage - 1 => 'ก่อนหน้า', $userPage + 1 => 'ถัดไป'] as $number => $label): ?><?php if ($number >= 1 && $number <= $totalUserPages): ?><a class="btn btn-outline-primary" href="index.php?<?= e(http_build_query(['page' => 'users', 'status' => $filterStatus, 'q' => $searchUsers, 'p' => $number])) ?>"><?= e($label) ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
</section>
