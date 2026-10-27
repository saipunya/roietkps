<?php if (!isset($currentUser) || !account_is_admin($currentUser)) { http_response_code(403); exit; } ?>
<?php if ($approvalNotice): ?><div class="alert alert-success" role="status"><?= e($approvalNotice) ?></div><?php endif; ?>
<?php if ($approvalError): ?><div class="alert alert-danger" role="alert"><?= e($approvalError) ?></div><?php endif; ?>

<?php if ($managedAccount):
    $managementProtected = (int) $managedAccount['id'] === (int) $currentUser['id'] || in_array($managedAccount['role_code'], ['system_admin', 'applicant'], true);
    $editingPost = $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'update_account' && (int) post_text('user_id') === (int) $managedAccount['id'];
    $managedUsername = $editingPost ? post_text('username') : $managedAccount['username'];
    $managedFullName = $editingPost ? post_text('full_name') : $managedAccount['full_name'];
    $managedEmail = $editingPost ? post_text('email') : ($managedAccount['email'] ?? '');
    $managedRole = $editingPost ? post_text('role_code') : $managedAccount['role_code'];
    $managedProvince = $editingPost ? post_text('province_id') : (string) ($managedAccount['province_id'] ?? '');
    $managedCooperative = $editingPost ? post_text('cooperative_id') : (string) ($managedAccount['cooperative_id'] ?? '');
?>
<section class="surface review-panel account-management-panel mb-4" aria-labelledby="manageAccountTitle">
  <div class="surface-heading"><div><h2 id="manageAccountTitle">จัดการบัญชีผู้ใช้งาน</h2><p><?= e($managedAccount['full_name']) ?> · <?= e($managedAccount['role_name']) ?></p></div><a class="text-link" href="index.php?page=users&amp;status=<?= e($managedAccount['status']) ?>">ปิด</a></div>
  <div class="review-body">
    <?php if ($managementProtected): ?>
      <div class="alert alert-info mb-0" role="status">บัญชีผู้ดูแลระบบและบัญชีของคุณได้รับการป้องกัน ไม่สามารถแก้ไขหรือระงับจากเมนูนี้ได้</div>
    <?php else: ?>
    <form action="index.php?page=users" method="post" id="managedAccountForm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="update_account"><input type="hidden" name="user_id" value="<?= (int) $managedAccount['id'] ?>"><input type="hidden" name="return_status" value="<?= e($managedAccount['status']) ?>">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="managedFullName">ชื่อ–นามสกุล</label><input class="form-control" id="managedFullName" name="full_name" maxlength="150" value="<?= e($managedFullName) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="managedUsername">ชื่อผู้ใช้งาน</label><input class="form-control" id="managedUsername" name="username" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9._-]{3,49}" value="<?= e($managedUsername) ?>" required><p class="field-help mt-2">ใช้ภาษาอังกฤษ ตัวเลข . _ หรือ -</p></div>
        <div class="col-md-6"><label class="form-label" for="managedEmail">อีเมล</label><input class="form-control" id="managedEmail" type="email" name="email" maxlength="254" value="<?= e($managedEmail) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="managedRole">บทบาท</label><select class="form-select" name="role_code" id="managedRole" required><?php foreach (assignable_roles() as $code => $name): ?><option value="<?= e($code) ?>"<?= $managedRole === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="managedProvinceField"><label class="form-label" for="managedProvince">จังหวัดที่รับผิดชอบ</label><select class="form-select" name="province_id" id="managedProvince"><option value="">โปรดเลือกจังหวัด</option><?php foreach ($managementProvinces as $province): ?><option value="<?= (int) $province['id'] ?>"<?= $managedProvince === (string) $province['id'] ? ' selected' : '' ?>><?= e($province['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="managedCooperativeField"><label class="form-label" for="managedCooperative">สหกรณ์ / กลุ่มเกษตรกร</label><select class="form-select" name="cooperative_id" id="managedCooperative"><option value="">โปรดเลือกหน่วยงาน</option><?php foreach ($managementCooperatives as $coop): ?><option data-province="<?= (int) $coop['province_id'] ?>" value="<?= (int) $coop['id'] ?>"<?= $managedCooperative === (string) $coop['id'] ? ' selected' : '' ?>><?= e($coop['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="approval-actions mt-3"><button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกข้อมูลผู้ใช้งาน</button></div>
    </form>
    <section class="account-status-management" id="accountStatus" aria-labelledby="accountStatusTitle">
      <div><h3 id="accountStatusTitle"><?= $managedAccount['status'] === 'active' ? 'ระงับบัญชีผู้ใช้งาน' : 'เปิดใช้งานบัญชีอีกครั้ง' ?></h3><p><?= $managedAccount['status'] === 'active' ? 'ผู้ใช้งานจะออกจากระบบทันทีและไม่สามารถเข้าสู่ระบบได้' : 'ผู้ใช้งานจะสามารถเข้าสู่ระบบด้วยรหัสผ่านเดิมได้อีกครั้ง' ?></p></div>
      <form action="index.php?page=users" method="post" data-confirm="<?= $managedAccount['status'] === 'active' ? 'ยืนยันการระงับบัญชีผู้ใช้งานนี้?' : 'ยืนยันการเปิดใช้งานบัญชีนี้อีกครั้ง?' ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="set_account_status"><input type="hidden" name="user_id" value="<?= (int) $managedAccount['id'] ?>"><input type="hidden" name="new_status" value="<?= $managedAccount['status'] === 'active' ? 'suspended' : 'active' ?>">
        <label class="form-label" for="statusReason">เหตุผล / หมายเหตุ</label><textarea class="form-control" id="statusReason" name="status_reason" rows="3" minlength="3" maxlength="500" required><?= e($_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'set_account_status' ? post_text('status_reason') : '') ?></textarea>
        <button class="btn <?= $managedAccount['status'] === 'active' ? 'btn-outline-danger' : 'btn-primary' ?> mt-3" type="submit"><?= app_icon($managedAccount['status'] === 'active' ? 'ban' : 'check') ?> <?= $managedAccount['status'] === 'active' ? 'ระงับบัญชี' : 'เปิดใช้งานบัญชี' ?></button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($reviewRequest):
    $selectedRole = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('role_code') : $reviewRequest['requested_role'];
    $selectedProvince = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('province_id') : $defaultApprovalProvince;
    $selectedCooperative = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('cooperative_id') : $defaultApprovalCooperative;
?>
<section class="surface review-panel mb-4" aria-labelledby="reviewTitle">
  <div class="surface-heading"><div><h2 id="reviewTitle">พิจารณาคำขอสมัครสมาชิก</h2><p><?= e($reviewRequest['full_name']) ?> · <?= e($reviewRequest['username']) ?></p></div><a class="text-link" href="index.php?page=users">ปิด</a></div>
  <div class="review-body">
    <p><strong>หน่วยงาน:</strong> <?= e($reviewRequest['organization']) ?><br><strong>ประเภทหน่วยงาน:</strong> <?= e(organization_type_labels()[$reviewRequest['organization_type']] ?? $reviewRequest['organization_type']) ?><br><strong>อีเมล:</strong> <?= e($reviewRequest['email']) ?><br><strong>ประเภทบัญชี:</strong> <?= e(request_roles()[$reviewRequest['requested_role']]) ?></p>
    <form action="index.php?page=users" method="post" id="approvalForm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="review_registration"><input type="hidden" name="user_id" value="<?= (int) $reviewRequest['id'] ?>">
      <div class="row g-3">
        <div class="col-12"><label class="form-label" for="approvalRole">บทบาทที่อนุมัติ</label><select class="form-select" name="role_code" id="approvalRole" required><?php foreach (assignable_roles() as $code => $name): ?><option value="<?= e($code) ?>"<?= $selectedRole === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="approvalProvinceField"><label class="form-label" for="approvalProvince">จังหวัดที่รับผิดชอบ</label><select class="form-select" name="province_id" id="approvalProvince"><option value="">เลือกจังหวัด</option><?php foreach ($approvalProvinces as $province): ?><option value="<?= (int) $province['id'] ?>"<?= $selectedProvince === (string) $province['id'] ? ' selected' : '' ?>><?= e($province['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6" id="approvalCooperativeField"><label class="form-label" for="approvalCooperative">สหกรณ์ / กลุ่มเกษตรกร</label><select class="form-select" name="cooperative_id" id="approvalCooperative"><option value="" disabled>โปรดเลือกหน่วยงาน</option><option value="new"<?= $selectedCooperative === 'new' ? ' selected' : '' ?>>สร้างหน่วยงานใหม่จากคำขอนี้</option><?php foreach ($approvalCooperatives as $coop): ?><option data-province="<?= (int) $coop['province_id'] ?>" value="<?= (int) $coop['id'] ?>"<?= $selectedCooperative === (string) $coop['id'] ? ' selected' : '' ?>><?= e($coop['name']) ?></option><?php endforeach; ?></select><p class="field-help mt-2">เมื่อเลือกหน่วยงานเดิม ระบบจะกำหนดจังหวัดของหน่วยงานนั้นให้อัตโนมัติ</p></div>
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
    <tr>
      <td class="record-primary"><strong><?= e($account['full_name']) ?></strong><small><?= e($account['username']) ?></small><small><?= e($account['email'] ?? '') ?></small></td>
      <td data-label="หน่วยงาน"><span><?= e($account['organization'] ?? '—') ?><small class="account-meta"><?= e($account['status'] === 'pending' && $account['requested_role'] ? (request_roles()[$account['requested_role']] ?? $account['role_name']) : $account['role_name']) ?><?= $account['organization_type'] ? ' · ' . e(organization_type_labels()[$account['organization_type']] ?? $account['organization_type']) : '' ?></small></span></td>
      <td data-label="สถานะ"><span class="status-badge <?= $account['status'] === 'active' ? 'status-success' : ($account['status'] === 'pending' ? 'status-warning' : 'status-danger') ?>"><?= e($userStatuses[$account['status']]) ?></span></td>
      <td class="record-action" data-no-export>
        <?php if ($account['status'] === 'pending' && $account['requested_role']): ?>
          <a class="btn record-button" href="index.php?page=users&amp;review=<?= (int) $account['id'] ?>">พิจารณาคำขอ <?= app_icon('chevron') ?></a>
        <?php elseif ((int) $account['id'] === (int) $currentUser['id']): ?>
          <span class="account-meta">บัญชีของคุณ</span>
        <?php elseif ($account['role_code'] === 'system_admin' || $account['role_code'] === 'applicant'): ?>
          <span class="account-meta">บัญชีที่ได้รับการป้องกัน</span>
        <?php else: ?>
          <div class="record-actions-group"><a class="btn record-button" href="index.php?page=users&amp;status=<?= e($filterStatus) ?>&amp;edit=<?= (int) $account['id'] ?>">แก้ไข <?= app_icon('chevron') ?></a><a class="btn record-button <?= $account['status'] === 'active' ? 'record-button-danger' : '' ?>" href="index.php?page=users&amp;status=<?= e($filterStatus) ?>&amp;edit=<?= (int) $account['id'] ?>#accountStatus"><?= $account['status'] === 'active' ? 'ระงับบัญชี' : 'เปิดใช้งาน' ?></a></div>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($totalUserPages > 1): ?><nav class="user-pagination" aria-label="หน้ารายการผู้ใช้"><?php foreach ([$userPage - 1 => 'ก่อนหน้า', $userPage + 1 => 'ถัดไป'] as $number => $label): ?><?php if ($number >= 1 && $number <= $totalUserPages): ?><a class="btn btn-outline-primary" href="index.php?<?= e(http_build_query(['page' => 'users', 'status' => $filterStatus, 'q' => $searchUsers, 'p' => $number])) ?>"><?= e($label) ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
</section>
