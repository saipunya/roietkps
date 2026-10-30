<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function workspace_page_definitions(): array
{
    return [
        'dashboard' => ['title' => 'แดชบอร์ด', 'description' => 'สรุปงานและรายการที่คุณควรดำเนินการ', 'icon' => 'dashboard', 'permission' => 'dashboard.view'],
        'registry' => ['title' => 'ทะเบียนสหกรณ์', 'description' => 'ค้นหาข้อมูลสหกรณ์และพื้นที่รับผิดชอบ', 'icon' => 'building', 'permission' => 'cooperatives.view'],
        'loans' => ['title' => 'ทะเบียนเงินกู้', 'description' => 'ติดตามคำขอกู้และผลการพิจารณาในที่เดียว', 'icon' => 'document', 'permission' => 'loans.view'],
        'loan-documents' => ['title' => 'เอกสารคำขอกู้', 'description' => 'อัปโหลด แก้ไข และตรวจเอกสารคำขอกู้แยกจากข้อมูลคำขอ', 'icon' => 'folder', 'permission' => 'loans.view'],
        'contracts' => ['title' => 'สัญญาเงินกู้', 'description' => 'จัดทำสัญญา บันทึกการเบิกจ่าย และกำหนดตารางชำระ', 'icon' => 'file-check', 'permission' => 'contracts.view'],
        'debt' => ['title' => 'ติดตามหนี้', 'description' => 'ตรวจสอบกำหนดชำระและรายการที่ต้องติดตาม', 'icon' => 'clock', 'permission' => 'debt.view'],
        'reports' => ['title' => 'รายงาน', 'description' => 'เลือกชุดข้อมูลที่ต้องการตรวจสอบหรือส่งออก', 'icon' => 'chart', 'permission' => 'reports.view'],
        'documents' => ['title' => 'คู่มือและเอกสาร', 'description' => 'ค้นหา ดาวน์โหลด และเผยแพร่เอกสารของระบบ', 'icon' => 'folder', 'permission' => null],
        'users' => ['title' => 'ผู้ใช้งานและสิทธิ์', 'description' => 'ตรวจคำขอสมัครสมาชิกและกำหนดสิทธิ์เข้าใช้งาน', 'icon' => 'users', 'permission' => 'users.manage'],
        'notifications' => ['title' => 'งานและการแจ้งเตือน', 'description' => 'ติดตามงานที่ต้องดำเนินการ กำหนดเวลา และข่าวสารของคุณ', 'icon' => 'bell', 'permission' => 'notifications.view', 'hidden' => true],
    ];
}

function app_icon($name)
{
    $icons = [
        'dashboard' => 'grid-1x2-fill',
        'home' => 'house-door-fill',
        'building' => 'bank2',
        'document' => 'file-earmark-text-fill',
        'file-check' => 'file-earmark-check-fill',
        'clock' => 'clock-history',
        'chart' => 'bar-chart-line-fill',
        'users' => 'people-fill',
        'menu' => 'list',
        'arrow' => 'arrow-right',
        'chevron' => 'chevron-right',
        'search' => 'search',
        'download' => 'cloud-arrow-down-fill',
        'upload' => 'cloud-arrow-up-fill',
        'folder' => 'folder2-open',
        'file' => 'file-earmark-arrow-down-fill',
        'calendar' => 'calendar3',
        'new' => 'stars',
        'pin' => 'pin-angle-fill',
        'unpin' => 'pin-angle',
        'info' => 'info-circle-fill',
        'check' => 'check-circle-fill',
        'wallet' => 'wallet2',
        'alert' => 'exclamation-triangle-fill',
        'location' => 'geo-alt-fill',
        'leaf' => 'leaf-fill',
        'login' => 'box-arrow-in-right',
        'lock' => 'shield-lock-fill',
        'user' => 'person-fill',
        'eye' => 'eye-fill',
        'eye-off' => 'eye-slash-fill',
        'bell' => 'bell-fill',
        'briefcase' => 'briefcase-fill',
        'ban' => 'slash-circle-fill',
    ];
    $icon = isset($icons[$name]) ? $icons[$name] : $icons['document'];
    return '<i class="bi bi-' . $icon . ' app-icon" aria-hidden="true"></i>';
}

function status_badge($status)
{
    $tones = [
        'อนุมัติ' => 'success',
        'อนุมัติแล้ว' => 'success',
        'ดำเนินงาน' => 'success',
        'ปกติ' => 'success',
        'ส่งตรวจแล้ว' => 'warning',
        'เจ้าหน้าที่ส่งเสริมกำลังตรวจ' => 'warning',
        'รอจังหวัดตรวจสอบ' => 'warning',
        'รอเอกสาร' => 'warning',
        'ใกล้ครบกำหนด' => 'warning',
        'ส่งกลับแก้ไข' => 'danger',
        'ไม่อนุมัติ' => 'danger',
        'ค้างชำระ' => 'danger',
        'รอตรวจสอบ' => 'warning',
    ];
    $tone = isset($tones[$status]) ? $tones[$status] : 'neutral';
    return '<span class="status-badge status-' . $tone . '"><span class="status-dot"></span>' . e($status) . '</span>';
}

function detail_button(array $details)
{
    return '<button class="btn record-button" type="button" data-bs-toggle="modal" data-bs-target="#recordModal" data-details="' . e(json_encode($details, JSON_UNESCAPED_UNICODE)) . '">ดูรายละเอียด<span class="visually-hidden"> ' . e(reset($details)) . '</span>' . app_icon('chevron') . '</button>';
}
