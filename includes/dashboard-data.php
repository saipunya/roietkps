<?php

function dashboard_value(PDO $db, string $sql, array $parameters = [])
{
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchColumn();
}

function load_role_dashboard(PDO $db, array $user): array
{
    $role = $user['role_code'];
    $stats = [];
    $tasks = [];

    if ($role === 'system_admin') {
        $stats = [
            ['label' => 'บัญชีรออนุมัติ', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM users WHERE status = 'pending'"), 'unit' => 'บัญชี', 'note' => 'ตรวจสอบและกำหนดสิทธิ์', 'icon' => 'users', 'tone' => 'amber'],
            ['label' => 'หน่วยงานในระบบ', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM cooperatives WHERE status = 'active'"), 'unit' => 'แห่ง', 'note' => 'สหกรณ์และกลุ่มเกษตรกร', 'icon' => 'building', 'tone' => 'green'],
            ['label' => 'คำขออนุมัติแล้ว', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_applications WHERE status = 'approved'"), 'unit' => 'รายการ', 'note' => 'พร้อมเข้าสู่การจัดทำสัญญา', 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'สัญญาที่ใช้งาน', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_contracts WHERE status = 'active'"), 'unit' => 'สัญญา', 'note' => 'อยู่ระหว่างการชำระ', 'icon' => 'document', 'tone' => 'blue'],
        ];
        $tasks = [
            ['title' => 'คำขอสมัครสมาชิก', 'description' => 'ตรวจสอบบัญชีและสร้างหน่วยงานใหม่', 'count' => $stats[0]['value'], 'unit' => 'บัญชี', 'href' => 'dashboard.php?page=users&status=pending', 'icon' => 'users', 'tone' => 'amber'],
            ['title' => 'ข้อมูลหน่วยงานไม่ครบ', 'description' => 'ติดตามการกรอกข้อมูลพื้นฐาน', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM cooperatives WHERE status = 'active' AND profile_status = 'incomplete'"), 'unit' => 'แห่ง', 'href' => 'dashboard.php?page=registry', 'icon' => 'building', 'tone' => 'blue'],
            ['title' => 'สัญญารอดำเนินการ', 'description' => 'ตรวจสอบรายการตั้งแต่จัดทำสัญญาจนถึงรอเบิกจ่าย', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_contracts WHERE status IN ('awaiting_preparation', 'draft', 'pending_review', 'awaiting_signature', 'signed', 'pending_disbursement')"), 'unit' => 'สัญญา', 'href' => 'dashboard.php?page=contracts', 'icon' => 'file-check', 'tone' => 'green'],
        ];
        $heading = 'ภาพรวมผู้ดูแลระบบ';
        $description = 'จัดการบัญชี หน่วยงาน และตรวจสอบความพร้อมของข้อมูลในระบบ';
        $scopeSql = '1 = 1';
        $scopeParameters = [];
    } elseif (role_is_provincial($role)) {
        $province = (int) $user['province_id'];
        $stats = [
            ['label' => 'หน่วยงานในจังหวัด', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM cooperatives WHERE province_id = ? AND status = 'active'", [$province]), 'unit' => 'แห่ง', 'note' => 'ในพื้นที่รับผิดชอบ', 'icon' => 'building', 'tone' => 'green'],
            ['label' => 'รออนุมัติขั้นสุดท้าย', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE c.province_id = ? AND a.status = 'province_review'", [$province]), 'unit' => 'รายการ', 'note' => 'เจ้าหน้าที่ส่งเสริมส่งต่อแล้ว', 'icon' => 'check', 'tone' => 'amber'],
            ['label' => 'สัญญารอดำเนินการ', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_contracts lc JOIN cooperatives c ON c.id = lc.cooperative_id WHERE c.province_id = ? AND lc.status IN ('awaiting_preparation', 'draft', 'pending_review', 'awaiting_signature', 'signed', 'pending_disbursement')", [$province]), 'unit' => 'สัญญา', 'note' => 'ตั้งแต่จัดทำสัญญาจนถึงรอเบิกจ่าย', 'icon' => 'file-check', 'tone' => 'blue'],
            ['label' => 'เงินต้นคงเหลือ', 'value' => number_format((float) dashboard_value($db, "SELECT COALESCE(SUM(lc.outstanding_principal), 0) FROM loan_contracts lc JOIN cooperatives c ON c.id = lc.cooperative_id WHERE c.province_id = ? AND lc.status = 'active'", [$province]) / 1000000, 2), 'unit' => 'ล้านบาท', 'note' => 'สัญญาที่อยู่ระหว่างชำระ', 'icon' => 'wallet', 'tone' => 'green'],
        ];
        $tasks = [
            ['title' => 'คำขอรอตรวจระดับจังหวัด', 'description' => 'ตรวจสอบความเห็นและเอกสารก่อนอนุมัติ', 'count' => $stats[1]['value'], 'unit' => 'รายการ', 'href' => 'dashboard.php?page=loans', 'icon' => 'check', 'tone' => 'amber'],
            ['title' => 'สัญญารอดำเนินการ', 'description' => 'จัดทำ ตรวจ ลงนาม หรือบันทึกการเบิกจ่าย', 'count' => $stats[2]['value'], 'unit' => 'สัญญา', 'href' => 'dashboard.php?page=contracts', 'icon' => 'file-check', 'tone' => 'blue'],
            ['title' => 'หน่วยงานยังไม่มีเจ้าหน้าที่รับผิดชอบ', 'description' => 'มอบหมายเจ้าหน้าที่ส่งเสริม', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM cooperatives c WHERE c.province_id = ? AND c.status = 'active' AND NOT EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = c.id AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()))", [$province]), 'unit' => 'แห่ง', 'href' => 'dashboard.php?page=registry', 'icon' => 'users', 'tone' => 'blue'],
            ['title' => 'งวดที่เลยกำหนด', 'description' => 'ติดตามรายการที่ยังชำระไม่ครบ', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM repayment_schedules rs JOIN loan_contracts lc ON lc.id = rs.contract_id JOIN cooperatives c ON c.id = lc.cooperative_id WHERE c.province_id = ? AND rs.due_date < CURRENT_DATE() AND rs.status IN ('scheduled', 'partial', 'overdue')", [$province]), 'unit' => 'งวด', 'href' => 'dashboard.php?page=debt', 'icon' => 'alert', 'tone' => 'red'],
        ];
        $heading = 'ภาพรวมงานระดับจังหวัด';
        $description = 'ตรวจสอบ อนุมัติ และติดตามสัญญาในจังหวัดที่รับผิดชอบ';
        $scopeSql = 'c.province_id = ?';
        $scopeParameters = [$province];
    } elseif ($role === 'promotion_officer') {
        $userId = (int) $user['id'];
        $stats = [
            ['label' => 'หน่วยงานที่รับผิดชอบ', 'value' => (int) dashboard_value($db, "SELECT COUNT(DISTINCT cooperative_id) FROM organization_officer_assignments WHERE officer_user_id = ? AND (ends_on IS NULL OR ends_on >= CURRENT_DATE())", [$userId]), 'unit' => 'แห่ง', 'note' => 'ตามการมอบหมายปัจจุบัน', 'icon' => 'building', 'tone' => 'green'],
            ['label' => 'คำขอรอตรวจ', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_applications WHERE assigned_officer_id = ? AND status IN ('submitted', 'promotion_review')", [$userId]), 'unit' => 'รายการ', 'note' => 'ตรวจเอกสารและให้ความเห็น', 'icon' => 'document', 'tone' => 'amber'],
            ['label' => 'ส่งกลับแก้ไข', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_applications WHERE assigned_officer_id = ? AND status = 'revision_requested'", [$userId]), 'unit' => 'รายการ', 'note' => 'รอหน่วยงานส่งข้อมูลใหม่', 'icon' => 'alert', 'tone' => 'blue'],
            ['label' => 'งวดที่ต้องติดตาม', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM repayment_schedules rs JOIN loan_contracts lc ON lc.id = rs.contract_id JOIN organization_officer_assignments oa ON oa.cooperative_id = lc.cooperative_id WHERE oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()) AND rs.due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY) AND rs.status IN ('scheduled', 'partial', 'overdue')", [$userId]), 'unit' => 'งวด', 'note' => 'ครบกำหนดภายใน 30 วัน', 'icon' => 'clock', 'tone' => 'red'],
        ];
        $tasks = [
            ['title' => 'ตรวจเอกสารคำขอกู้', 'description' => 'ตรวจไฟล์และส่งกลับแก้ไขเป็นรายเอกสาร', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id WHERE a.assigned_officer_id = ? AND a.status IN ('submitted', 'promotion_review') AND d.status = 'uploaded' AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)", [$userId]), 'unit' => 'ฉบับ', 'href' => 'dashboard.php?page=loan-documents', 'icon' => 'folder', 'tone' => 'amber'],
            ['title' => 'ตรวจข้อมูลคำขอกู้', 'description' => 'ให้ความเห็นและส่งต่อให้จังหวัด', 'count' => $stats[1]['value'], 'unit' => 'รายการ', 'href' => 'dashboard.php?page=loans', 'icon' => 'document', 'tone' => 'blue'],
            ['title' => 'ติดตามเอกสารที่ส่งกลับ', 'description' => 'เอกสารที่รอหน่วยงานอัปโหลดฉบับแก้ไข', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id WHERE a.assigned_officer_id = ? AND d.status = 'needs_revision' AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)", [$userId]), 'unit' => 'ฉบับ', 'href' => 'dashboard.php?page=loan-documents', 'icon' => 'clock', 'tone' => 'blue'],
            ['title' => 'ติดตามกำหนดชำระ', 'description' => 'ติดต่อหน่วยงานก่อนถึงกำหนด', 'count' => $stats[3]['value'], 'unit' => 'งวด', 'href' => 'dashboard.php?page=debt', 'icon' => 'alert', 'tone' => 'red'],
        ];
        $heading = 'งานของเจ้าหน้าที่ส่งเสริม';
        $description = 'ตรวจเอกสาร ให้ความเห็น และติดตามหน่วยงานที่ได้รับมอบหมาย';
        $scopeSql = "EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = a.cooperative_id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()))";
        $scopeParameters = [$userId];
    } elseif ($role === 'cooperative_user') {
        $cooperative = (int) $user['cooperative_id'];
        $profile = $db->prepare('SELECT name, profile_status FROM cooperatives WHERE id = ?');
        $profile->execute([$cooperative]);
        $organization = $profile->fetch() ?: ['name' => 'หน่วยงานของคุณ', 'profile_status' => 'incomplete'];
        $stats = [
            ['label' => 'ความพร้อมของข้อมูล', 'value' => $organization['profile_status'] === 'complete' ? 100 : 20, 'unit' => '%', 'note' => $organization['profile_status'] === 'complete' ? 'ข้อมูลหน่วยงานครบแล้ว' : 'กรุณากรอกข้อมูลหน่วยงานให้ครบ', 'icon' => 'building', 'tone' => $organization['profile_status'] === 'complete' ? 'green' : 'amber'],
            ['label' => 'คำขอกู้ทั้งหมด', 'value' => (int) dashboard_value($db, 'SELECT COUNT(*) FROM loan_applications WHERE cooperative_id = ?', [$cooperative]), 'unit' => 'รายการ', 'note' => 'รวมทุกสถานะ', 'icon' => 'document', 'tone' => 'blue'],
            ['label' => 'สัญญาที่ใช้งาน', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_contracts WHERE cooperative_id = ? AND status = 'active'", [$cooperative]), 'unit' => 'สัญญา', 'note' => 'อยู่ระหว่างการชำระ', 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'เงินต้นคงเหลือ', 'value' => number_format((float) dashboard_value($db, "SELECT COALESCE(SUM(outstanding_principal), 0) FROM loan_contracts WHERE cooperative_id = ? AND status = 'active'", [$cooperative]) / 1000000, 2), 'unit' => 'ล้านบาท', 'note' => 'รวมสัญญาที่ยังไม่ปิด', 'icon' => 'wallet', 'tone' => 'amber'],
        ];
        $tasks = [
            ['title' => 'ข้อมูลหน่วยงาน', 'description' => $organization['profile_status'] === 'complete' ? 'ข้อมูลพร้อมใช้งาน' : 'กรอกข้อมูลและเอกสารพื้นฐานให้ครบ', 'count' => $organization['profile_status'] === 'complete' ? 0 : 1, 'unit' => 'งาน', 'href' => 'dashboard.php?page=registry', 'icon' => 'building', 'tone' => 'blue'],
            ['title' => 'ข้อมูลคำขอที่ต้องแก้ไข', 'description' => 'แก้ไขวงเงิน ผู้ค้ำ หรือรายละเอียดคำขอ', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_applications WHERE cooperative_id = ? AND status = 'revision_requested'", [$cooperative]), 'unit' => 'รายการ', 'href' => 'dashboard.php?page=loans', 'icon' => 'document', 'tone' => 'red'],
            ['title' => 'เอกสารที่ต้องแก้ไข', 'description' => 'อัปโหลดไฟล์ฉบับแก้ไขตามความเห็นเจ้าหน้าที่', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_documents d JOIN loan_applications a ON a.id = d.loan_application_id WHERE a.cooperative_id = ? AND d.status = 'needs_revision' AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)", [$cooperative]), 'unit' => 'ฉบับ', 'href' => 'dashboard.php?page=loan-documents', 'icon' => 'folder', 'tone' => 'red'],
            ['title' => 'งวดชำระถัดไป', 'description' => 'กำหนดชำระภายใน 30 วัน', 'count' => (int) dashboard_value($db, "SELECT COUNT(*) FROM repayment_schedules rs JOIN loan_contracts lc ON lc.id = rs.contract_id WHERE lc.cooperative_id = ? AND rs.due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY) AND rs.status IN ('scheduled', 'partial')", [$cooperative]), 'unit' => 'งวด', 'href' => 'dashboard.php?page=debt', 'icon' => 'clock', 'tone' => 'amber'],
        ];
        $heading = $organization['name'];
        $description = 'ติดตามข้อมูล คำขอกู้ สัญญา และกำหนดชำระของหน่วยงาน';
        $scopeSql = 'a.cooperative_id = ?';
        $scopeParameters = [$cooperative];
    } else {
        $heading = 'ภาพรวมกองทุน';
        $description = 'ข้อมูลคำขอ สัญญา และการติดตามงานทุกพื้นที่';
        $stats = [
            ['label' => 'หน่วยงานในระบบ', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM cooperatives WHERE status = 'active'"), 'unit' => 'แห่ง', 'note' => 'ทุกพื้นที่', 'icon' => 'building', 'tone' => 'green'],
            ['label' => 'คำขอทั้งหมด', 'value' => (int) dashboard_value($db, 'SELECT COUNT(*) FROM loan_applications'), 'unit' => 'รายการ', 'note' => 'รวมทุกสถานะ', 'icon' => 'document', 'tone' => 'blue'],
            ['label' => 'สัญญาที่ใช้งาน', 'value' => (int) dashboard_value($db, "SELECT COUNT(*) FROM loan_contracts WHERE status = 'active'"), 'unit' => 'สัญญา', 'note' => 'อยู่ระหว่างชำระ', 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'เงินต้นคงเหลือ', 'value' => number_format((float) dashboard_value($db, "SELECT COALESCE(SUM(outstanding_principal), 0) FROM loan_contracts WHERE status = 'active'") / 1000000, 2), 'unit' => 'ล้านบาท', 'note' => 'ภาพรวมทุกพื้นที่', 'icon' => 'wallet', 'tone' => 'amber'],
        ];
        $tasks = [];
        $scopeSql = '1 = 1';
        $scopeParameters = [];
    }

    $query = $db->prepare("SELECT a.id, a.application_no, a.purpose, a.requested_amount, a.status, a.updated_at, c.name AS organization_name FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE {$scopeSql} ORDER BY a.updated_at DESC, a.id DESC LIMIT 5");
    $query->execute($scopeParameters);

    return [
        'heading' => $heading,
        'description' => $description,
        'stats' => $stats,
        'tasks' => $tasks,
        'my_tasks' => notification_open_tasks($db, (int) $user['id'], 5),
        'recent' => $query->fetchAll(),
    ];
}
