<?php

// ชุดข้อมูลของโมดูลธุรกิจจะถูกเชื่อมต่อฐานข้อมูลในขั้นตอนพัฒนาของแต่ละโมดูล
$cooperatives = [];
$loans = [];
$debts = [];

$dashboardData = [
    'summary' => [
        'cooperatives' => 42,
        'approved_amount' => 18.60,
        'approved_requests' => 24,
        'principal_paid' => 6.45,
        'overdue' => 3,
        'due_soon' => 4,
        'waiting_documents' => 4,
    ],
    'fund_flow' => [
        'labels' => ['พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.'],
        'approved' => [1.80, 2.40, 3.10, 2.60, 4.00, 4.70],
        'received' => [0.60, 0.85, 1.10, 0.90, 1.35, 1.65],
    ],
    'request_status' => [
        'labels' => ['อนุมัติแล้ว', 'อยู่ระหว่างพิจารณา', 'รอเอกสาร', 'รับคำขอ'],
        'values' => [24, 7, 4, 3],
    ],
];
