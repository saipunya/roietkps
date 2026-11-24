<?php

require_once __DIR__ . '/loan-status.php';

function executive_dashboard_stage(array $row): array
{
    $applicationStages = [
        'draft' => ['กำลังจัดทำคำขอ', 'สหกรณ์กำลังจัดเตรียมข้อมูลคำขอ', 'neutral'],
        'submitted' => ['รอเจ้าหน้าที่ส่งเสริมรับตรวจ', 'รอรับเรื่องและเริ่มกลั่นกรอง', 'warning'],
        'promotion_review' => ['เจ้าหน้าที่ส่งเสริมกำลังกลั่นกรอง', 'อยู่ระหว่างตรวจข้อมูลและเอกสาร', 'warning'],
        'revision_requested' => ['ส่งกลับแก้ไข', 'รอติดตามการแก้ไขและส่งตรวจอีกครั้ง', 'danger'],
        'province_review' => ['รอผู้บริหารพิจารณา', 'เจ้าหน้าที่ส่งเสริมส่งต่อให้ระดับจังหวัดแล้ว', 'warning'],
        'approved' => ['อนุมัติแล้ว · รอสร้างสัญญา', 'รอระบบจัดทำสัญญาให้คำขอนี้', 'success'],
        'rejected' => ['ไม่อนุมัติ', 'สิ้นสุดการพิจารณาคำขอ', 'danger'],
        'cancelled' => ['ยกเลิก', 'คำขอถูกยกเลิกแล้ว', 'danger'],
    ];
    $contractStages = [
        'awaiting_preparation' => ['อนุมัติแล้ว · รอจัดทำสัญญา', 'รอผู้รับผิดชอบจัดทำรายละเอียดสัญญา', 'success'],
        'draft' => ['อนุมัติแล้ว · ร่างสัญญา', 'อยู่ระหว่างจัดทำร่างสัญญา', 'warning'],
        'pending_review' => ['อนุมัติแล้ว · รอตรวจสัญญา', 'อยู่ระหว่างตรวจสอบสัญญา', 'warning'],
        'awaiting_signature' => ['อนุมัติแล้ว · รอลงนาม', 'รอคู่สัญญาลงนาม', 'warning'],
        'signed' => ['อนุมัติแล้ว · ลงนามแล้ว', 'รอบันทึกการเบิกจ่าย', 'success'],
        'pending_disbursement' => ['อนุมัติแล้ว · รอเบิกจ่าย', 'สัญญาพร้อมรอขั้นตอนเบิกจ่าย', 'warning'],
        'active' => ['สัญญามีผลบังคับ', 'อยู่ระหว่างชำระเงินกู้', 'success'],
        'completed' => ['ชำระครบแล้ว', 'สัญญาเสร็จสิ้น', 'success'],
        'closed' => ['ปิดสัญญาแล้ว', 'สัญญาเสร็จสิ้น', 'success'],
        'cancelled' => ['สัญญาถูกยกเลิก', 'ยกเลิกในขั้นตอนสัญญา', 'danger'],
    ];

    $applicationStatus = (string) ($row['status'] ?? '');
    $contractStatus = (string) ($row['contract_status'] ?? '');
    if ($applicationStatus === 'approved' && isset($contractStages[$contractStatus])) {
        return ['label' => $contractStages[$contractStatus][0], 'description' => $contractStages[$contractStatus][1], 'tone' => $contractStages[$contractStatus][2]];
    }
    if (isset($applicationStages[$applicationStatus])) {
        return ['label' => $applicationStages[$applicationStatus][0], 'description' => $applicationStages[$applicationStatus][1], 'tone' => $applicationStages[$applicationStatus][2]];
    }
    return ['label' => 'อยู่ระหว่างดำเนินการ', 'description' => 'ตรวจสอบรายละเอียดของคำขอ', 'tone' => 'neutral'];
}

function executive_dashboard_pending_contract_statuses(): array
{
    return ['awaiting_preparation', 'draft', 'pending_review', 'awaiting_signature', 'signed', 'pending_disbursement'];
}

function load_executive_dashboard(PDO $db, array $user): array
{
    if (($user['role_code'] ?? '') !== 'provincial_director' || !account_can($user, 'loans.view')) {
        throw new DomainException('หน้านี้สำหรับผู้บริหารสหกรณ์จังหวัดเท่านั้น');
    }
    $provinceId = (int) ($user['province_id'] ?? 0);
    if ($provinceId < 1) {
        throw new DomainException('ไม่พบพื้นที่รับผิดชอบของบัญชีผู้บริหาร');
    }

    $applicationSummary = $db->prepare("SELECT a.status, COUNT(*) AS item_count,
        COALESCE(SUM(COALESCE(a.approved_amount, a.requested_amount)), 0) AS total_amount
        FROM loan_applications a
        JOIN cooperatives c ON c.id = a.cooperative_id
        WHERE c.province_id = ?
        GROUP BY a.status");
    $applicationSummary->execute([$provinceId]);
    $byApplicationStatus = [];
    foreach ($applicationSummary->fetchAll() as $row) {
        $byApplicationStatus[(string) $row['status']] = [
            'count' => (int) $row['item_count'],
            'amount' => (float) $row['total_amount'],
        ];
    }

    $pendingContractStatuses = executive_dashboard_pending_contract_statuses();
    $contractSummary = $db->prepare("SELECT lc.status, COUNT(*) AS item_count
        FROM loan_contracts lc
        JOIN cooperatives c ON c.id = lc.cooperative_id
        WHERE c.province_id = ?
        GROUP BY lc.status");
    $contractSummary->execute([$provinceId]);
    $contractCounts = [];
    foreach ($contractSummary->fetchAll() as $row) {
        $contractCounts[(string) $row['status']] = (int) $row['item_count'];
    }
    $pendingContractCount = array_sum(array_intersect_key($contractCounts, array_flip($pendingContractStatuses)));

    $workflowDefinitions = [
        'submitted' => ['ส่งมาแล้ว', 'รอเจ้าหน้าที่ส่งเสริมรับตรวจ', 'blue'],
        'promotion_review' => ['กลั่นกรอง', 'เจ้าหน้าที่ส่งเสริมกำลังตรวจ', 'amber'],
        'revision_requested' => ['ส่งกลับแก้ไข', 'รอติดตามการแก้ไข', 'red'],
        'province_review' => ['รอพิจารณา', 'รอผู้บริหารระดับจังหวัด', 'amber'],
        'approved' => ['อนุมัติแล้ว', 'เข้าสู่กระบวนการสัญญา', 'green'],
    ];
    $workflow = [];
    foreach ($workflowDefinitions as $status => [$label, $description, $tone]) {
        $workflow[] = [
            'status' => $status,
            'label' => $label,
            'description' => $description,
            'count' => $byApplicationStatus[$status]['count'] ?? 0,
            'tone' => $tone,
        ];
    }

    $rows = $db->prepare("SELECT a.id, a.application_no, a.purpose, a.requested_amount, a.approved_amount, a.status,
            a.updated_at, c.name AS organization_name, officer.full_name AS officer_name,
            lc.id AS contract_id, lc.contract_no, lc.status AS contract_status, lc.principal_amount
        FROM loan_applications a
        JOIN cooperatives c ON c.id = a.cooperative_id
        LEFT JOIN users officer ON officer.id = a.assigned_officer_id
        LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id
        WHERE c.province_id = ?
        ORDER BY FIELD(a.status, 'province_review', 'revision_requested', 'promotion_review', 'submitted', 'draft', 'approved', 'rejected', 'cancelled'),
            a.updated_at DESC, a.id DESC
        LIMIT 30");
    $rows->execute([$provinceId]);
    $recent = $rows->fetchAll();
    foreach ($recent as &$row) {
        $row['stage'] = executive_dashboard_stage($row);
    }
    unset($row);

    return [
        'stats' => [
            ['label' => 'อนุมัติแล้ว', 'value' => $byApplicationStatus['approved']['count'] ?? 0, 'unit' => 'คำขอ', 'note' => 'วงเงิน ' . number_format($byApplicationStatus['approved']['amount'] ?? 0, 2) . ' บาท', 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'รอผู้บริหารพิจารณา', 'value' => $byApplicationStatus['province_review']['count'] ?? 0, 'unit' => 'คำขอ', 'note' => 'ส่งต่อจากเจ้าหน้าที่ส่งเสริมแล้ว', 'icon' => 'alert', 'tone' => 'amber'],
            ['label' => 'ส่งกลับแก้ไข', 'value' => $byApplicationStatus['revision_requested']['count'] ?? 0, 'unit' => 'คำขอ', 'note' => 'รอติดตามผลการแก้ไข', 'icon' => 'document', 'tone' => 'red'],
            ['label' => 'สัญญารอดำเนินการ', 'value' => $pendingContractCount, 'unit' => 'สัญญา', 'note' => 'ตั้งแต่จัดทำจนถึงรอเบิกจ่าย', 'icon' => 'file-check', 'tone' => 'blue'],
        ],
        'workflow' => $workflow,
        'follow_ups' => [
            ['title' => 'รอพิจารณาระดับจังหวัด', 'description' => 'คำขอที่ครบการกลั่นกรองและรอผลพิจารณา', 'count' => $byApplicationStatus['province_review']['count'] ?? 0, 'href' => 'dashboard.php?page=loans&status=province_review', 'icon' => 'alert', 'tone' => 'amber'],
            ['title' => 'รอการแก้ไข', 'description' => 'ติดตามเจ้าหน้าที่ส่งเสริมและสหกรณ์ให้แก้ไขตามความเห็น', 'count' => $byApplicationStatus['revision_requested']['count'] ?? 0, 'href' => 'dashboard.php?page=loans', 'icon' => 'document', 'tone' => 'red'],
            ['title' => 'สัญญาที่ยังไม่เสร็จ', 'description' => 'ตั้งแต่ร่างสัญญาจนถึงรอเบิกจ่าย', 'count' => $pendingContractCount, 'href' => 'dashboard.php?page=contracts', 'icon' => 'file-check', 'tone' => 'blue'],
        ],
        'recent' => $recent,
    ];
}
