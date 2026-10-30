<?php

$registryError = '';
$registryNotice = isset($_SESSION['registry_notice']) ? (string) $_SESSION['registry_notice'] : '';
unset($_SESSION['registry_notice']);
$db = app_db();
$showOrganizationForm = account_is_admin($currentUser) && isset($_GET['action']) && $_GET['action'] === 'new';
$editOrganizationId = account_is_admin($currentUser) && isset($_GET['edit']) && is_scalar($_GET['edit']) ? (int) $_GET['edit'] : 0;
$showOrganizationEdit = $editOrganizationId > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $registryError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            if ($operation === 'create_organization') {
                if (!account_is_admin($currentUser) || !account_can($currentUser, 'cooperatives.manage')) {
                    throw new DomainException('คุณไม่มีสิทธิ์เพิ่มข้อมูลหน่วยงาน');
                }
                $code = strtoupper(post_text('code'));
                $name = post_text('name');
                $organizationType = post_text('organization_type');
                $provinceId = filter_var(post_text('province_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $registrationNo = post_text('registration_no');
                $district = post_text('district');
                $subdistrict = post_text('subdistrict');
                $address = post_text('address');
                $postalCode = post_text('postal_code');
                $phone = post_text('phone');
                $email = post_text('email');
                if ($code !== '' && (!preg_match('/\A[A-Z0-9._\/-]{2,50}\z/', $code))) {
                    throw new DomainException('รหัสหน่วยงานใช้ได้เฉพาะภาษาอังกฤษ ตัวเลข . _ / หรือ -');
                }
                if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 200 || !in_array($organizationType, ['cooperative', 'farmer_group'], true)) {
                    throw new DomainException('กรุณากรอกชื่อและประเภทหน่วยงานให้ถูกต้อง');
                }
                if (!$provinceId || mb_strlen($district, 'UTF-8') < 2 || mb_strlen($district, 'UTF-8') > 100 || mb_strlen($address, 'UTF-8') < 5 || mb_strlen($address, 'UTF-8') > 500) {
                    throw new DomainException('กรุณาเลือกจังหวัดและกรอกอำเภอกับที่อยู่ให้ครบถ้วน');
                }
                if ($phone === '' || strlen($phone) > 30 || !preg_match('/\A[0-9+() .-]{8,30}\z/', $phone)) {
                    throw new DomainException('กรุณากรอกหมายเลขโทรศัพท์ให้ถูกต้อง');
                }
                if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('กรุณากรอกอีเมลให้ถูกต้อง');
                }
                foreach ([$registrationNo => 50, $subdistrict => 100, $postalCode => 10] as $value => $maximum) {
                    if (mb_strlen($value, 'UTF-8') > $maximum) {
                        throw new DomainException('ข้อมูลหน่วยงานยาวเกินกำหนด');
                    }
                }
                $province = $db->prepare('SELECT id FROM provinces WHERE id = ?');
                $province->execute([$provinceId]);
                if (!$province->fetchColumn()) {
                    throw new DomainException('ไม่พบจังหวัดที่เลือก');
                }
                if ($code === '') {
                    $code = 'ORG-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                }
                $db->beginTransaction();
                $duplicate = $db->prepare("SELECT id FROM cooperatives WHERE name = ? AND province_id = ? AND status = 'active' FOR UPDATE");
                $duplicate->execute([$name, $provinceId]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('มีหน่วยงานชื่อนี้ในจังหวัดที่เลือกแล้ว');
                }
                $insert = $db->prepare("INSERT INTO cooperatives (code, name, organization_type, registration_no, province_id, district, subdistrict, address, postal_code, phone, email, profile_status, profile_completed_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'complete', UTC_TIMESTAMP(), 'active')");
                $insert->execute([$code, $name, $organizationType, $registrationNo ?: null, $provinceId, $district, $subdistrict ?: null, $address, $postalCode ?: null, $phone, $email]);
                $organizationId = (int) $db->lastInsertId();
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'organization.create', 'cooperative', ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $organizationId, json_encode(['code' => $code, 'name' => $name, 'organization_type' => $organizationType, 'province_id' => (int) $provinceId], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_profile_completed($db, $organizationId, (int) $currentUser['id']);
                $db->commit();
                $_SESSION['registry_notice'] = 'เพิ่มข้อมูลหน่วยงานเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=registry&view=' . $organizationId, true, 303);
                exit;
            }
            if ($operation === 'update_organization') {
                if (!account_is_admin($currentUser) || !account_can($currentUser, 'cooperatives.manage')) {
                    throw new DomainException('คุณไม่มีสิทธิ์แก้ไขข้อมูลหน่วยงาน');
                }
                $organizationId = filter_var(post_text('organization_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $code = strtoupper(post_text('code'));
                $name = post_text('name');
                $organizationType = post_text('organization_type');
                $registrationNo = post_text('registration_no');
                $district = post_text('district');
                $subdistrict = post_text('subdistrict');
                $address = post_text('address');
                $postalCode = post_text('postal_code');
                $phone = post_text('phone');
                $email = post_text('email');
                if (!$organizationId || !preg_match('/\A[A-Z0-9._\/-]{2,50}\z/', $code)) {
                    throw new DomainException('กรุณาระบุรหัสหน่วยงานเป็นภาษาอังกฤษ ตัวเลข . _ / หรือ -');
                }
                if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 200 || !in_array($organizationType, ['cooperative', 'farmer_group'], true)) {
                    throw new DomainException('กรุณากรอกชื่อและประเภทหน่วยงานให้ถูกต้อง');
                }
                if (mb_strlen($district, 'UTF-8') < 2 || mb_strlen($district, 'UTF-8') > 100 || mb_strlen($address, 'UTF-8') < 5 || mb_strlen($address, 'UTF-8') > 500) {
                    throw new DomainException('กรุณากรอกอำเภอและที่อยู่ให้ครบถ้วน');
                }
                if ($phone === '' || strlen($phone) > 30 || !preg_match('/\A[0-9+() .-]{8,30}\z/', $phone)) {
                    throw new DomainException('กรุณากรอกหมายเลขโทรศัพท์ให้ถูกต้อง');
                }
                if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('กรุณากรอกอีเมลให้ถูกต้อง');
                }
                foreach ([$registrationNo => 50, $subdistrict => 100, $postalCode => 10] as $value => $maximum) {
                    if (mb_strlen($value, 'UTF-8') > $maximum) {
                        throw new DomainException('ข้อมูลหน่วยงานยาวเกินกำหนด');
                    }
                }
                $db->beginTransaction();
                $query = $db->prepare("SELECT * FROM cooperatives WHERE id = ? AND status = 'active' FOR UPDATE");
                $query->execute([$organizationId]);
                $oldOrganization = $query->fetch();
                if (!$oldOrganization) {
                    throw new DomainException('ไม่พบหน่วยงานที่ต้องการแก้ไข');
                }
                $duplicate = $db->prepare("SELECT id FROM cooperatives WHERE name = ? AND province_id = ? AND id <> ? AND status = 'active'");
                $duplicate->execute([$name, $oldOrganization['province_id'], $organizationId]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('มีหน่วยงานชื่อนี้ในจังหวัดเดียวกันแล้ว');
                }
                $update = $db->prepare("UPDATE cooperatives SET code = ?, name = ?, organization_type = ?, registration_no = ?, district = ?, subdistrict = ?, address = ?, postal_code = ?, phone = ?, email = ?, profile_status = 'complete', profile_completed_at = COALESCE(profile_completed_at, UTC_TIMESTAMP()) WHERE id = ?");
                $update->execute([$code, $name, $organizationType, $registrationNo ?: null, $district, $subdistrict ?: null, $address, $postalCode ?: null, $phone, $email, $organizationId]);
                $oldValues = ['code' => $oldOrganization['code'], 'name' => $oldOrganization['name'], 'organization_type' => $oldOrganization['organization_type'], 'registration_no' => $oldOrganization['registration_no'], 'district' => $oldOrganization['district'], 'subdistrict' => $oldOrganization['subdistrict'], 'address' => $oldOrganization['address'], 'postal_code' => $oldOrganization['postal_code'], 'phone' => $oldOrganization['phone'], 'email' => $oldOrganization['email']];
                $newValues = ['code' => $code, 'name' => $name, 'organization_type' => $organizationType, 'registration_no' => $registrationNo ?: null, 'district' => $district, 'subdistrict' => $subdistrict ?: null, 'address' => $address, 'postal_code' => $postalCode ?: null, 'phone' => $phone, 'email' => $email];
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_hash) VALUES (?, 'organization.admin_update', 'cooperative', ?, ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $organizationId, json_encode($oldValues, JSON_UNESCAPED_UNICODE), json_encode($newValues, JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                $_SESSION['registry_notice'] = 'แก้ไขข้อมูลหน่วยงานเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=registry&view=' . $organizationId, true, 303);
                exit;
            }
            if ($operation === 'save_profile') {
                if ($currentUser['role_code'] !== 'cooperative_user' || !account_can($currentUser, 'organizations.profile.edit')) {
                    throw new DomainException('คุณไม่มีสิทธิ์แก้ไขข้อมูลหน่วยงาน');
                }
                $organizationId = (int) $currentUser['cooperative_id'];
                $name = post_text('name');
                $organizationType = post_text('organization_type');
                $registrationNo = post_text('registration_no');
                $district = post_text('district');
                $subdistrict = post_text('subdistrict');
                $address = post_text('address');
                $postalCode = post_text('postal_code');
                $phone = post_text('phone');
                $email = post_text('email');
                if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 200) {
                    throw new DomainException('กรุณากรอกชื่อหน่วยงานให้ถูกต้อง');
                }
                if (!in_array($organizationType, ['cooperative', 'farmer_group'], true)) {
                    throw new DomainException('กรุณาเลือกประเภทหน่วยงาน');
                }
                if (mb_strlen($district, 'UTF-8') < 2 || mb_strlen($district, 'UTF-8') > 100 || mb_strlen($address, 'UTF-8') < 5 || mb_strlen($address, 'UTF-8') > 500) {
                    throw new DomainException('กรุณากรอกอำเภอและที่อยู่ให้ครบถ้วน');
                }
                if ($phone === '' || strlen($phone) > 30 || !preg_match('/\A[0-9+() .-]{8,30}\z/', $phone)) {
                    throw new DomainException('กรุณากรอกหมายเลขโทรศัพท์ให้ถูกต้อง');
                }
                if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('กรุณากรอกอีเมลให้ถูกต้อง');
                }
                foreach ([$registrationNo => 50, $subdistrict => 100, $postalCode => 10] as $value => $maximum) {
                    if (mb_strlen($value, 'UTF-8') > $maximum) {
                        throw new DomainException('ข้อมูลหน่วยงานยาวเกินกำหนด');
                    }
                }
                $query = $db->prepare("UPDATE cooperatives SET name = ?, organization_type = ?, registration_no = ?, district = ?, subdistrict = ?, address = ?, postal_code = ?, phone = ?, email = ?, profile_status = 'complete', profile_completed_at = COALESCE(profile_completed_at, UTC_TIMESTAMP()) WHERE id = ? AND status = 'active'");
                $query->execute([$name, $organizationType, $registrationNo ?: null, $district, $subdistrict ?: null, $address, $postalCode ?: null, $phone, $email, $organizationId]);
                if ($query->rowCount() === 0) {
                    $check = $db->prepare("SELECT id FROM cooperatives WHERE id = ? AND status = 'active'");
                    $check->execute([$organizationId]);
                    if (!$check->fetchColumn()) {
                        throw new DomainException('ไม่พบหน่วยงานที่ต้องการแก้ไข');
                    }
                }
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'organization.profile.update', 'cooperative', ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $organizationId, json_encode(['profile_status' => 'complete'], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_profile_completed($db, $organizationId, (int) $currentUser['id']);
                $_SESSION['registry_notice'] = 'บันทึกข้อมูลหน่วยงานเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=registry', true, 303);
                exit;
            }

            if ($operation === 'assign_officer') {
                if (!account_can($currentUser, 'organizations.assign_officer')) {
                    throw new DomainException('คุณไม่มีสิทธิ์มอบหมายเจ้าหน้าที่');
                }
                $organizationId = filter_var(post_text('organization_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $officerId = filter_var(post_text('officer_user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$organizationId || !$officerId) {
                    throw new DomainException('กรุณาเลือกหน่วยงานและเจ้าหน้าที่');
                }
                $query = $db->prepare("SELECT c.id, c.province_id FROM cooperatives c WHERE c.id = ? AND c.status = 'active'");
                $query->execute([$organizationId]);
                $organization = $query->fetch();
                if (!$organization || (role_is_provincial($currentUser['role_code']) && (int) $organization['province_id'] !== (int) $currentUser['province_id'])) {
                    throw new DomainException('หน่วยงานอยู่นอกพื้นที่รับผิดชอบ');
                }
                $query = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.province_id = ? AND u.status = 'active' AND r.code = 'promotion_officer'");
                $query->execute([$officerId, $organization['province_id']]);
                if (!$query->fetchColumn()) {
                    throw new DomainException('เจ้าหน้าที่ที่เลือกไม่พร้อมใช้งานหรืออยู่นอกพื้นที่');
                }
                $db->beginTransaction();
                $db->prepare("UPDATE organization_officer_assignments SET ends_on = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), is_primary = 0 WHERE cooperative_id = ? AND is_primary = 1 AND (ends_on IS NULL OR ends_on >= CURRENT_DATE())")->execute([$organizationId]);
                $db->prepare("INSERT INTO organization_officer_assignments (cooperative_id, officer_user_id, assigned_by, is_primary, starts_on) VALUES (?, ?, ?, 1, CURRENT_DATE())")->execute([$organizationId, $officerId, $currentUser['id']]);
                $db->prepare("UPDATE loan_applications SET assigned_officer_id = ? WHERE cooperative_id = ? AND assigned_officer_id IS NULL AND status IN ('submitted', 'promotion_review')")->execute([$officerId, $organizationId]);
                $audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_hash) VALUES (?, 'organization.officer.assign', 'cooperative', ?, ?, ?)");
                $audit->execute([(int) $currentUser['id'], $organizationId, json_encode(['officer_user_id' => (int) $officerId], JSON_UNESCAPED_UNICODE), hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')]);
                tracking_officer_assigned($db, (int) $organizationId, (int) $officerId, (int) $currentUser['id']);
                $db->commit();
                $_SESSION['registry_notice'] = 'มอบหมายเจ้าหน้าที่ส่งเสริมเรียบร้อยแล้ว';
                header('Location: dashboard.php?page=registry&view=' . (int) $organizationId, true, 303);
                exit;
            }
        } catch (DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            $registryError = $exception->getMessage();
            if (isset($operation) && $operation === 'create_organization') {
                $showOrganizationForm = true;
            } elseif (isset($operation) && $operation === 'update_organization') {
                $showOrganizationEdit = true;
                $editOrganizationId = (int) post_text('organization_id');
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof PDOException && isset($operation) && in_array($operation, ['create_organization', 'update_organization'], true) && isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
                http_response_code(422);
                $registryError = 'รหัสหน่วยงานนี้ถูกใช้แล้ว กรุณากำหนดรหัสอื่นหรือเว้นว่างเพื่อให้ระบบสร้างอัตโนมัติ';
                $showOrganizationForm = $operation === 'create_organization';
                $showOrganizationEdit = $operation === 'update_organization';
                $editOrganizationId = (int) post_text('organization_id');
            } else {
                throw $exception;
            }
        }
    }
}

$registryRows = [];
$selectedOrganization = null;
$assignedOfficer = null;
$availableOfficers = [];
$registryProvinces = [];

if ($showOrganizationForm || $showOrganizationEdit) {
    $registryProvinces = $db->query('SELECT id, name FROM provinces ORDER BY name')->fetchAll();
}

if ($currentUser['role_code'] === 'cooperative_user') {
    $query = $db->prepare('SELECT c.*, p.name AS province_name FROM cooperatives c JOIN provinces p ON p.id = c.province_id WHERE c.id = ?');
    $query->execute([(int) $currentUser['cooperative_id']]);
    $selectedOrganization = $query->fetch();
} else {
    $parameters = [];
    if (role_is_provincial($currentUser['role_code'])) {
        $where = 'c.province_id = ?';
        $parameters[] = (int) $currentUser['province_id'];
    } elseif ($currentUser['role_code'] === 'promotion_officer') {
        $where = "EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = c.id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()))";
        $parameters[] = (int) $currentUser['id'];
    } else {
        $where = '1 = 1';
    }
    $query = $db->prepare("SELECT c.*, p.name AS province_name, u.full_name AS officer_name FROM cooperatives c JOIN provinces p ON p.id = c.province_id LEFT JOIN organization_officer_assignments oa ON oa.cooperative_id = c.id AND oa.is_primary = 1 AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()) LEFT JOIN users u ON u.id = oa.officer_user_id WHERE {$where} ORDER BY c.name");
    $query->execute($parameters);
    $registryRows = $query->fetchAll();
    $viewId = $showOrganizationEdit ? $editOrganizationId : (isset($_GET['view']) && is_scalar($_GET['view']) ? (int) $_GET['view'] : 0);
    foreach ($registryRows as $row) {
        if ((int) $row['id'] === $viewId) {
            $selectedOrganization = $row;
            break;
        }
    }
    if ($selectedOrganization && account_can($currentUser, 'organizations.assign_officer')) {
        $query = $db->prepare("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'promotion_officer' AND u.status = 'active' AND u.province_id = ? ORDER BY u.full_name");
        $query->execute([$selectedOrganization['province_id']]);
        $availableOfficers = $query->fetchAll();
    }
}
