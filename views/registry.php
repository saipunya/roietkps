<?php if ($registryNotice !== ''): ?><div class="alert alert-success" role="status"><?= e($registryNotice) ?></div><?php endif; ?>
<?php if ($registryError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($registryError) ?></div><?php endif; ?>

<?php if ($showOrganizationForm):
    $newOrganizationProvince = post_text('province_id') ?: (count($registryProvinces) === 1 ? (string) $registryProvinces[0]['id'] : '');
    $newOrganizationType = post_text('organization_type') ?: 'cooperative';
?>
<section class="surface organization-profile mb-4" aria-labelledby="createOrganizationTitle">
  <div class="surface-heading"><div><h2 id="createOrganizationTitle">เพิ่มสหกรณ์ / กลุ่มเกษตรกร</h2><p>Admin บันทึกข้อมูลหน่วยงานเข้าทะเบียนได้โดยตรง</p></div><a class="text-link" href="dashboard.php?page=registry">ปิด</a></div>
  <form class="organization-form" action="dashboard.php?page=registry&amp;action=new" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="create_organization">
    <div class="registration-grid">
      <div class="auth-field"><label class="form-label" for="newOrganizationName">ชื่อหน่วยงาน</label><input class="form-control" id="newOrganizationName" name="name" maxlength="200" value="<?= e(post_text('name')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="newOrganizationType">ประเภทหน่วยงาน</label><select class="form-select" id="newOrganizationType" name="organization_type" required><option value="cooperative"<?= $newOrganizationType === 'cooperative' ? ' selected' : '' ?>>สหกรณ์</option><option value="farmer_group"<?= $newOrganizationType === 'farmer_group' ? ' selected' : '' ?>>กลุ่มเกษตรกร</option></select></div>
      <div class="auth-field"><label class="form-label" for="newOrganizationCode">รหัสหน่วยงาน</label><input class="form-control" id="newOrganizationCode" name="code" maxlength="50" pattern="[A-Za-z0-9._\/\-]{2,50}" placeholder="เว้นว่างเพื่อให้ระบบสร้างอัตโนมัติ" value="<?= e(post_text('code')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="newRegistrationNo">เลขทะเบียน</label><input class="form-control" id="newRegistrationNo" name="registration_no" maxlength="50" value="<?= e(post_text('registration_no')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="newOrganizationProvince">จังหวัด</label><select class="form-select" id="newOrganizationProvince" name="province_id" required><option value="">โปรดเลือกจังหวัด</option><?php foreach ($registryProvinces as $province): ?><option value="<?= (int) $province['id'] ?>"<?= $newOrganizationProvince === (string) $province['id'] ? ' selected' : '' ?>><?= e($province['name']) ?></option><?php endforeach; ?></select></div>
      <div class="auth-field"><label class="form-label" for="newDistrict">อำเภอ</label><input class="form-control" id="newDistrict" name="district" maxlength="100" value="<?= e(post_text('district')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="newSubdistrict">ตำบล</label><input class="form-control" id="newSubdistrict" name="subdistrict" maxlength="100" value="<?= e(post_text('subdistrict')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="newPostalCode">รหัสไปรษณีย์</label><input class="form-control" id="newPostalCode" name="postal_code" maxlength="10" value="<?= e(post_text('postal_code')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="newOrganizationPhone">โทรศัพท์</label><input class="form-control" id="newOrganizationPhone" name="phone" maxlength="30" value="<?= e(post_text('phone')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="newOrganizationEmail">อีเมล</label><input class="form-control" type="email" id="newOrganizationEmail" name="email" maxlength="254" value="<?= e(post_text('email')) ?>" required></div>
    </div>
    <div class="auth-field"><label class="form-label" for="newOrganizationAddress">ที่อยู่</label><textarea class="form-control" id="newOrganizationAddress" name="address" rows="3" maxlength="500" required><?= e(post_text('address')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('building') ?> เพิ่มหน่วยงาน</button><a class="btn btn-outline-primary" href="dashboard.php?page=registry">ยกเลิก</a></div>
  </form>
</section>
<?php endif; ?>

<?php if ($showOrganizationEdit && $selectedOrganization):
    $editValue = function (string $key) use ($selectedOrganization): string {
        return $_SERVER['REQUEST_METHOD'] === 'POST' && post_text('operation') === 'update_organization'
            ? post_text($key)
            : (string) ($selectedOrganization[$key] ?? '');
    };
    $editOrganizationType = $editValue('organization_type') ?: 'cooperative';
?>
<section class="surface organization-profile mb-4" aria-labelledby="editOrganizationTitle">
  <div class="surface-heading"><div><h2 id="editOrganizationTitle">แก้ไขข้อมูลหน่วยงาน</h2><p>Admin แก้ไขข้อมูลทะเบียนโดยระบบเก็บประวัติเดิมและข้อมูลใหม่ไว้</p></div><a class="text-link" href="dashboard.php?page=registry&amp;view=<?= (int) $selectedOrganization['id'] ?>">ยกเลิก</a></div>
  <form class="organization-form" action="dashboard.php?page=registry&amp;edit=<?= (int) $selectedOrganization['id'] ?>" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="update_organization"><input type="hidden" name="organization_id" value="<?= (int) $selectedOrganization['id'] ?>">
    <div class="registration-grid">
      <div class="auth-field"><label class="form-label" for="editOrganizationName">ชื่อหน่วยงาน</label><input class="form-control" id="editOrganizationName" name="name" maxlength="200" value="<?= e($editValue('name')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="editOrganizationType">ประเภทหน่วยงาน</label><select class="form-select" id="editOrganizationType" name="organization_type" required><option value="cooperative"<?= $editOrganizationType === 'cooperative' ? ' selected' : '' ?>>สหกรณ์</option><option value="farmer_group"<?= $editOrganizationType === 'farmer_group' ? ' selected' : '' ?>>กลุ่มเกษตรกร</option></select></div>
      <div class="auth-field"><label class="form-label" for="editOrganizationCode">รหัสหน่วยงาน</label><input class="form-control" id="editOrganizationCode" name="code" maxlength="50" pattern="[A-Za-z0-9._\/\-]{2,50}" value="<?= e($editValue('code')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="editRegistrationNo">เลขทะเบียน</label><input class="form-control" id="editRegistrationNo" name="registration_no" maxlength="50" value="<?= e($editValue('registration_no')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="editProvinceName">จังหวัด</label><input class="form-control" id="editProvinceName" value="<?= e($selectedOrganization['province_name']) ?>" disabled></div>
      <div class="auth-field"><label class="form-label" for="editDistrict">อำเภอ</label><input class="form-control" id="editDistrict" name="district" maxlength="100" value="<?= e($editValue('district')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="editSubdistrict">ตำบล</label><input class="form-control" id="editSubdistrict" name="subdistrict" maxlength="100" value="<?= e($editValue('subdistrict')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="editPostalCode">รหัสไปรษณีย์</label><input class="form-control" id="editPostalCode" name="postal_code" maxlength="10" value="<?= e($editValue('postal_code')) ?>"></div>
      <div class="auth-field"><label class="form-label" for="editOrganizationPhone">โทรศัพท์</label><input class="form-control" id="editOrganizationPhone" name="phone" maxlength="30" value="<?= e($editValue('phone')) ?>" required></div>
      <div class="auth-field"><label class="form-label" for="editOrganizationEmail">อีเมล</label><input class="form-control" type="email" id="editOrganizationEmail" name="email" maxlength="254" value="<?= e($editValue('email')) ?>" required></div>
    </div>
    <div class="auth-field"><label class="form-label" for="editOrganizationAddress">ที่อยู่</label><textarea class="form-control" id="editOrganizationAddress" name="address" rows="3" maxlength="500" required><?= e($editValue('address')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= app_icon('check') ?> บันทึกการแก้ไข</button><a class="btn btn-outline-primary" href="dashboard.php?page=registry&amp;view=<?= (int) $selectedOrganization['id'] ?>">ยกเลิก</a></div>
  </form>
</section>
<?php endif; ?>

<?php if ($currentUser['role_code'] === 'cooperative_user' && $selectedOrganization): ?>
<section class="surface organization-profile" aria-labelledby="organizationProfileTitle">
  <div class="surface-heading"><div><h2 id="organizationProfileTitle">ข้อมูลสหกรณ์ / กลุ่มเกษตรกร</h2><p>ข้อมูลนี้ใช้ประกอบคำขอกู้และการจัดทำสัญญา</p></div><span class="status-badge <?= $selectedOrganization['profile_status'] === 'complete' ? 'status-success' : 'status-warning' ?>"><?= $selectedOrganization['profile_status'] === 'complete' ? 'ข้อมูลครบแล้ว' : 'กรุณากรอกข้อมูลให้ครบ' ?></span></div>
  <form class="organization-form" action="dashboard.php?page=registry" method="post">
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
    <div class="surface-heading"><div><h2><?= e($selectedOrganization['name']) ?></h2><p><?= $selectedOrganization['organization_type'] === 'farmer_group' ? 'กลุ่มเกษตรกร' : 'สหกรณ์' ?> · จังหวัด<?= e($selectedOrganization['province_name']) ?></p></div><div class="surface-heading-actions"><?php if (account_is_admin($currentUser)): ?><a class="btn btn-outline-primary btn-sm" href="dashboard.php?page=registry&amp;edit=<?= (int) $selectedOrganization['id'] ?>">แก้ไขข้อมูล</a><?php endif; ?><a class="text-link" href="dashboard.php?page=registry">ปิด</a></div></div>
    <div class="review-body">
      <dl class="organization-detail-grid"><div><dt>สถานะข้อมูล</dt><dd><?= $selectedOrganization['profile_status'] === 'complete' ? 'ข้อมูลครบแล้ว' : 'ข้อมูลยังไม่ครบ' ?></dd></div><div><dt>อำเภอ</dt><dd><?= e($selectedOrganization['district'] ?? '—') ?></dd></div><div><dt>โทรศัพท์</dt><dd><?= e($selectedOrganization['phone'] ?? '—') ?></dd></div><div><dt>อีเมล</dt><dd><?= e($selectedOrganization['email'] ?? '—') ?></dd></div></dl>
      <?php if (account_can($currentUser, 'organizations.assign_officer')): ?>
      <form action="dashboard.php?page=registry&amp;view=<?= (int) $selectedOrganization['id'] ?>" method="post" class="assignment-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="operation" value="assign_officer"><input type="hidden" name="organization_id" value="<?= (int) $selectedOrganization['id'] ?>">
        <label class="form-label" for="assignedOfficer">เจ้าหน้าที่ส่งเสริมที่รับผิดชอบ</label><div class="assignment-controls"><select class="form-select" id="assignedOfficer" name="officer_user_id" required><option value="">เลือกเจ้าหน้าที่</option><?php foreach ($availableOfficers as $officer): ?><option value="<?= (int) $officer['id'] ?>"><?= e($officer['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit"><?= app_icon('users') ?> มอบหมาย</button></div>
        <?php if (!$availableOfficers): ?><p class="field-help mt-2">ยังไม่มีบัญชีเจ้าหน้าที่ส่งเสริมที่ใช้งานในจังหวัดนี้</p><?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <section class="surface records-section"><div class="loan-list-heading"><div><strong>ทะเบียนหน่วยงาน</strong><span>ทั้งหมด <?= count($registryRows) ?> หน่วยงาน</span></div><?php if (account_is_admin($currentUser)): ?><a class="btn btn-primary" href="dashboard.php?page=registry&amp;action=new"><?= app_icon('building') ?> เพิ่มหน่วยงาน</a><?php endif; ?></div><?php if (!$registryRows): ?><div class="empty-state"><?= app_icon('building') ?><h2>ยังไม่มีข้อมูลหน่วยงาน</h2><p>Admin สามารถเพิ่มหน่วยงานได้โดยตรง หรือสร้างจากขั้นตอนอนุมัติสมาชิก</p></div><?php else: ?><div class="records-wrap"><table class="table records-table mb-0" id="recordsTable"><thead><tr><th>ชื่อหน่วยงาน / รหัส</th><th>ประเภท</th><th>พื้นที่</th><th>เจ้าหน้าที่รับผิดชอบ</th><th>สถานะข้อมูล</th><th data-no-export></th></tr></thead><tbody><?php foreach ($registryRows as $organization): ?><tr><td class="record-primary"><strong><?= e($organization['name']) ?></strong><small><?= e($organization['code']) ?></small></td><td data-label="ประเภท"><?= $organization['organization_type'] === 'farmer_group' ? 'กลุ่มเกษตรกร' : 'สหกรณ์' ?></td><td data-label="พื้นที่"><?= e(($organization['district'] ? $organization['district'] . ' · ' : '') . $organization['province_name']) ?></td><td data-label="เจ้าหน้าที่"><?= e($organization['officer_name'] ?? 'ยังไม่มอบหมาย') ?></td><td data-label="สถานะ"><span class="status-badge <?= $organization['profile_status'] === 'complete' ? 'status-success' : 'status-warning' ?>"><?= $organization['profile_status'] === 'complete' ? 'ครบแล้ว' : 'ยังไม่ครบ' ?></span></td><td class="record-action" data-no-export><a class="btn record-button" href="dashboard.php?page=registry&amp;view=<?= (int) $organization['id'] ?>">รายละเอียด <?= app_icon('chevron') ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?>
