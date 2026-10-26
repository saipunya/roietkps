<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_icon($name)
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'building' => '<path d="m3 9 9-6 9 6H3ZM5 10v9m7-9v9m7-9v9M3 21h18"/>',
        'document' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"/><path d="M14 3v6h6M8 13h8m-8 4h5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart' => '<path d="M4 3v17h17M8 15v-4m5 4V7m5 8V5"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 15v5h16v-5"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>',
        'check' => '<path d="m5 12 4 4L19 6"/><circle cx="12" cy="12" r="9"/>',
        'wallet' => '<path d="M20 8V5H5a2 2 0 0 0 0 4h16v11H5a2 2 0 0 1-2-2V7m18 6h-5v3h5"/>',
        'alert' => '<path d="m10.3 4-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0ZM12 9v4m0 3v.1"/>',
        'pin' => '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 0 1 14 0Z"/><circle cx="12" cy="10" r="2"/>',
        'leaf' => '<path d="M20 3C10 3 3 6 5 14s16 7 15-11ZM4 21 15 10"/>',
        'login' => '<path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5M3 12h12m-5-5 5 5-5 5"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="m3 3 18 18M10 5a12 12 0 0 1 12 7 19 19 0 0 1-3 4M6 6a19 19 0 0 0-4 6s3 7 10 7a12 12 0 0 0 5-1m-7-8a3 3 0 0 0 4 4"/>',
    ];
    $path = isset($paths[$name]) ? $paths[$name] : $paths['document'];
    return '<svg class="app-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

function status_badge($status)
{
    $tones = ['อนุมัติ' => 'success', 'ดำเนินงาน' => 'success', 'ปกติ' => 'success', 'รอเอกสาร' => 'warning', 'ใกล้ครบกำหนด' => 'warning', 'ค้างชำระ' => 'danger', 'รอตรวจสอบ' => 'warning'];
    $tone = isset($tones[$status]) ? $tones[$status] : 'neutral';
    return '<span class="status-badge status-' . $tone . '"><span class="status-dot"></span>' . e($status) . '</span>';
}

function detail_button(array $details)
{
    return '<button class="btn record-button" type="button" data-bs-toggle="modal" data-bs-target="#recordModal" data-details="' . e(json_encode($details, JSON_UNESCAPED_UNICODE)) . '">ดูรายละเอียด<span class="visually-hidden"> ' . e(reset($details)) . '</span>' . app_icon('chevron') . '</button>';
}
