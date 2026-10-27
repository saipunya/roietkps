<?php if ($registryNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($registryNotice) ?></div><?php endif; ?>
<?php if ($registryError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($registryError) ?></div><?php endif; ?>

<?php if ($currentUser['role_code'] === 'cooperative_user' && $selectedOrganization): ?>
<section class="surface organization-profile" aria-labelledby="organizationProfileTitle">
  <div class="surface-heading"><div><h2 id="organizationProfileTitle">ข้อมูลสหกรณ์ / กลุ่มเกษตรกร</h2><p>ข้อมูลนี้ใช้ประกอบคำขอกู้และการจัดทำสัญญา</p></div><span class="status-badge <?= $selectedOrganization['profile_status'] === 'complete' ? 'status-success' : 'status-warning' ?>"><?= $selectedOrganization['profile_status'] === 'complete' ? 'ข้อมูลครบแล้ว' : 'กรุณากรอกข้อมูลให้ครบ' ?></span></div>
  <form class="organization-form" action="index.php?page=registry" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="save_profile">
    <div class="registration-grid">
      <div class="auth-field"><label class="form-label" for="organizationName">ชื่อหน่วยงาน</label><input class="form-control" id="organizationName" name="name" maxlength="200" value="<?= e($selectedOrganization['name']) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="organizationTypeProfile">ประเภทหน่วยงาน</label><select class="form-select" id="organizationTypeProfile" name="organization_type" required><option value="cooperative"<?= $selectedOrganization['organization_type'] === 'cooperative' ? ' selected' : '' ?>>สหกรณ์</option><option value="farmer_group"<?= $selectedOrganization['organization_type'] === 'farmer_group' ? ' selected' : '' ?>>กลุ่มเกษตรกร</option></select></div>
      <div class="auth-field"><label class="form-label" for="registrationNo">เลขทะเบียน</label><input class="form-control" id="registrationNo" name="registration_no" maxlength="50" value="<?= e($selectedOrganization['registration_no'] ?? '') ?>"></div>
      <div class="auth-field"><label class="form-label" for="provinceName">จังหวัด</label><input class="form-control" id="provinceName" value="<?= e($selectedOrganization['province_name']) ?>" disabled></div>
      <div class="auth-field"><label class="form-label" for="district">อำเภอ</label><input class="form-control" id="district" name="district" maxlength="100" value="<?= e($selectedOrganization['district'] ?? '') ?>" required></div>
      <div class="auth-field"><label class="form-label" for="subdistrict">ตำบล</label><input class="form-control" id="subdistrict" name="subdistrict" maxlength="100" value="<?= e($selectedOrganization['subdistrict'] ?? '') ?>"></div>
      <div class="auth-field"><label class="form-label" for="organizationPhone">โทรศัพท์</label><input class="form-control" id="organizationPhone" name="phone" maxlength="30" value="<?= e($selectedOrganization['phone'] ?? '') ?>" required></div>
      <div class="auth-field"><label class="form-label" for="organizationEmail">อีเมล</label><input class="form-control" type="email" id="organizationEmail" name="email" maxlength="254" value="<?= e($selectedOrganization['email'] ?? '') ?>" required></div>
      <div class="auth-field"><label class="form-label" for="postalCode">รหัสไปรษณีย์</label><input class="form-control" id="postalCode" name="postal_code" maxlength="10" value="<?= e($selectedOrganization['postal_code'] ?? '') ?>"></div>
    </div>
    <div class="auth-field"><label class="form-label" for="organizationAddress">ที่อยู่</label><textarea class="form-control" id="organizationAddress" name="address" rows="3" maxlength="500" required><?= e($selectedOrganization['address'] ?? '') ?></textarea></div>
    <button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกข้อมูลหน่วยงาน</button>
  </form>
</section>
<?php else: ?>
  <?php if ($selectedOrganization): ?>
  <section class="surface review-panel mb-4">
    <div class="surface-heading"><div><h2><?= e($selectedOrganization['name']) ?></h2><p><?= $selectedOrganization['organization_type'] === 'farmer_group' ? 'กลุ่มเกษตรกร' : 'สหกรณ์' ?> · จังหวัด<?= e($selectedOrganization['province_name']) ?></p></div><a class="text-link" href="index.php?page=registry">ปิด</a></div>
    <div class="review-body">
      <dl class="organization-detail-grid"><div><dt>สถานะข้อมูล</dt><dd><?= $selectedOrganization['profile_status'] === 'complete' ? 'ข้อมูลครบแล้ว' : 'ข้อมูลยังไม่ครบ' ?></dd></div><div><dt>อำเภอ</dt><dd><?= e($selectedOrganization['district'] ?? '—') ?></dd></div><div><dt>โทรศัพท์</dt><dd><?= e($selectedOrganization['phone'] ?? '—') ?></dd></div><div><dt>อีเมล</dt><dd><?= e($selectedOrganization['email'] ?? '—') ?></dd></div></dl>
      <?php if (account_can($currentUser, 'organizations.assign_officer')): ?>
      <form action="index.php?page=registry&amp;view=<?= (int) $selectedOrganization['id'] ?>" method="post" class="assignment-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="assign_officer"><input type="hidden" name="organization_id" value="<?= (int) $selectedOrganization['id'] ?>">
        <label class="form-label" for="assignedOfficer">เจ้าหน้าที่ส่งเสริมที่รับผิดชอบ</label><div class="assignment-controls"><select class="form-select" id="assignedOfficer" name="officer_user_id" required><option value="">เลือกเจ้าหน้าที่</option><?php foreach ($availableOfficers as $officer): ?><option value="<?= (int) $officer['id'] ?>"><?= e($officer['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit"><?= app_icon('users') ?> มอบหมาย</button></div>
        <?php if (!$availableOfficers): ?><p class="field-help mt-2">ยังไม่มีบัญชีเจ้าหน้าที่ส่งเสริมที่ใช้งานในจังหวัดนี้</p><?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <section class="surface records-section"><div class="results-summary">ทั้งหมด <?= count($registryRows) ?> หน่วยงาน</div><?php if (!$registryRows): ?><div class="empty-state"><?= app_icon('building') ?><h2>ยังไม่มีข้อมูลหน่วยงาน</h2><p>หน่วยงานจะถูกสร้างเมื่อ Admin อนุมัติคำขอสมัครสมาชิก</p></div><?php else: ?><div class="records-wrap"><table class="table records-table mb-0" id="recordsTable"><thead><tr><th>ชื่อหน่วยงาน / รหัส</th><th>ประเภท</th><th>พื้นที่</th><th>เจ้าหน้าที่รับผิดชอบ</th><th>สถานะข้อมูล</th><th data-no-export></th></tr></thead><tbody><?php foreach ($registryRows as $organization): ?><tr><td class="record-primary"><strong><?= e($organization['name']) ?></strong><small><?= e($organization['code']) ?></small></td><td data-label="ประเภท"><?= $organization['organization_type'] === 'farmer_group' ? 'กลุ่มเกษตรกร' : 'สหกรณ์' ?></td><td data-label="พื้นที่"><?= e(($organization['district'] ? $organization['district'] . ' · ' : '') . $organization['province_name']) ?></td><td data-label="เจ้าหน้าที่"><?= e($organization['officer_name'] ?? 'ยังไม่มอบหมาย') ?></td><td data-label="สถานะ"><span class="status-badge <?= $organization['profile_status'] === 'complete' ? 'status-success' : 'status-warning' ?>"><?= $organization['profile_status'] === 'complete' ? 'ครบแล้ว' : 'ยังไม่ครบ' ?></span></td><td class="record-action" data-no-export><a class="btn record-button" href="index.php?page=registry&amp;view=<?= (int) $organization['id'] ?>">รายละเอียด <?= app_icon('chevron') ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?>
