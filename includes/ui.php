<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_icon($name)
{
    $icons = [
        'dashboard' => 'grid-1x2-fill',
        'building' => 'bank2',
        'document' => 'file-earmark-text-fill',
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
        'pin' => 'geo-alt-fill',
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
    $tones = ['อนุมัติ' => 'success', 'ดำเนินงาน' => 'success', 'ปกติ' => 'success', 'รอเอกสาร' => 'warning', 'ใกล้ครบกำหนด' => 'warning', 'ค้างชำระ' => 'danger', 'รอตรวจสอบ' => 'warning'];
    $tone = isset($tones[$status]) ? $tones[$status] : 'neutral';
    return '<span class="status-badge status-' . $tone . '"><span class="status-dot"></span>' . e($status) . '</span>';
}

function detail_button(array $details)
{
    return '<button class="btn record-button" type="button" data-bs-toggle="modal" data-bs-target="#recordModal" data-details="' . e(json_encode($details, JSON_UNESCAPED_UNICODE)) . '">ดูรายละเอียด<span class="visually-hidden"> ' . e(reset($details)) . '</span>' . app_icon('chevron') . '</button>';
}
