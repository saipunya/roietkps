<?php

require_once __DIR__ . '/loan-status.php';

function public_dashboard_value(PDO $db, string $sql, array $parameters = [])
{
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchColumn();
}

function public_dashboard_month_label(string $monthKey): string
{
    $monthNames = [
        1 => 'ม.ค.',
        2 => 'ก.พ.',
        3 => 'มี.ค.',
        4 => 'เม.ย.',
        5 => 'พ.ค.',
        6 => 'มิ.ย.',
        7 => 'ก.ค.',
        8 => 'ส.ค.',
        9 => 'ก.ย.',
        10 => 'ต.ค.',
        11 => 'พ.ย.',
        12 => 'ธ.ค.',
    ];

    $date = DateTimeImmutable::createFromFormat('!Y-m', $monthKey);
    return $date ? $monthNames[(int) $date->format('n')] : $monthKey;
}

function load_public_dashboard_data(PDO $db, bool $includeRecentLoans = false): array
{
    $cooperativeCount = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(*) FROM cooperatives WHERE status = 'active'"
    );
    $approvedAmount = (float) public_dashboard_value(
        $db,
        "SELECT COALESCE(SUM(COALESCE(approved_amount, requested_amount)), 0) FROM loan_applications WHERE status = 'approved'"
    );
    $approvedRequests = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(*) FROM loan_applications WHERE status = 'approved'"
    );
    $pendingRequests = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(*) FROM loan_applications WHERE status IN ('submitted', 'promotion_review', 'revision_requested', 'province_review')"
    );
    $principalPaid = (float) public_dashboard_value(
        $db,
        'SELECT COALESCE(SUM(principal_amount), 0) FROM repayments WHERE voided_at IS NULL'
    );
    $overdue = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(DISTINCT contract_id) FROM repayment_schedules WHERE due_date < CURRENT_DATE() AND status IN ('scheduled', 'partial', 'overdue')"
    );
    $dueSoon = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(DISTINCT contract_id) FROM repayment_schedules WHERE due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY) AND status IN ('scheduled', 'partial')"
    );
    $waitingDocuments = (int) public_dashboard_value(
        $db,
        "SELECT COUNT(*) FROM loan_applications a WHERE a.status IN ('draft', 'submitted', 'promotion_review', 'revision_requested') AND (a.status = 'revision_requested' OR EXISTS (SELECT 1 FROM loan_documents d WHERE d.loan_application_id = a.id AND d.status = 'needs_revision' AND NOT EXISTS (SELECT 1 FROM loan_documents newer WHERE newer.replaces_document_id = d.id)))"
    );

    $currentMonth = new DateTimeImmutable('first day of this month 00:00:00');
    $monthKeys = [];
    for ($offset = 5; $offset >= 0; $offset--) {
        $monthKeys[] = $currentMonth->modify('-' . $offset . ' months')->format('Y-m');
    }
    $approvedByMonth = array_fill_keys($monthKeys, 0.0);
    $receivedByMonth = array_fill_keys($monthKeys, 0.0);
    $periodStart = $monthKeys[0] . '-01 00:00:00';

    $query = $db->prepare("SELECT DATE_FORMAT(approved_at, '%Y-%m') AS month_key, COALESCE(SUM(COALESCE(approved_amount, requested_amount)), 0) AS total FROM loan_applications WHERE status = 'approved' AND approved_at >= ? GROUP BY DATE_FORMAT(approved_at, '%Y-%m')");
    $query->execute([$periodStart]);
    foreach ($query->fetchAll() as $row) {
        if (array_key_exists($row['month_key'], $approvedByMonth)) {
            $approvedByMonth[$row['month_key']] = round((float) $row['total'] / 1000000, 2);
        }
    }

    $query = $db->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month_key, COALESCE(SUM(principal_amount), 0) AS total FROM repayments WHERE paid_at >= ? AND voided_at IS NULL GROUP BY DATE_FORMAT(paid_at, '%Y-%m')");
    $query->execute([$periodStart]);
    foreach ($query->fetchAll() as $row) {
        if (array_key_exists($row['month_key'], $receivedByMonth)) {
            $receivedByMonth[$row['month_key']] = round((float) $row['total'] / 1000000, 2);
        }
    }

    $statusCounts = $db->query("SELECT
        SUM(a.status = 'draft') AS draft_count,
        SUM(a.status IN ('submitted', 'promotion_review', 'province_review')) AS pending_count,
        SUM(a.status = 'revision_requested') AS revision_count,
        SUM(a.status = 'approved' AND (lc.status IS NULL OR lc.status NOT IN ('completed', 'closed', 'cancelled'))) AS approved_count,
        SUM(a.status = 'approved' AND lc.status IN ('completed', 'closed')) AS completed_count,
        SUM(a.status = 'cancelled' OR lc.status = 'cancelled') AS cancelled_count,
        SUM(a.status = 'rejected') AS rejected_count
        FROM loan_applications a
        LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id")->fetch() ?: [];

    $recentLoans = [];
    if ($includeRecentLoans) {
        $query = $db->query("SELECT a.application_no, a.requested_amount, a.status, a.created_at, c.name AS organization_name, lc.status AS contract_status FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id LEFT JOIN loan_contracts lc ON lc.loan_application_id = a.id ORDER BY a.created_at DESC, a.id DESC LIMIT 4");
        foreach ($query->fetchAll() as $row) {
            $lifecycle = loan_lifecycle_status($row['status'], $row['contract_status']);
            $recentLoans[] = [
                'application_no' => $row['application_no'],
                'organization_name' => $row['organization_name'],
                'amount' => (float) $row['requested_amount'],
                'date' => date('d/m/Y', strtotime($row['created_at'])),
                'status' => $lifecycle['label'],
            ];
        }
    }

    return [
        'summary' => [
            'cooperatives' => $cooperativeCount,
            'approved_amount' => round($approvedAmount / 1000000, 2),
            'approved_requests' => $approvedRequests,
            'pending_requests' => $pendingRequests,
            'principal_paid' => round($principalPaid / 1000000, 2),
            'overdue' => $overdue,
            'due_soon' => $dueSoon,
            'waiting_documents' => $waitingDocuments,
        ],
        'fund_flow' => [
            'labels' => array_map('public_dashboard_month_label', $monthKeys),
            'approved' => array_values($approvedByMonth),
            'received' => array_values($receivedByMonth),
        ],
        'request_status' => [
            'labels' => ['ฉบับร่าง', 'เสนอขออนุมัติ', 'ส่งกลับแก้ไข', 'อนุมัติ', 'เสร็จสิ้น', 'ยกเลิก', 'ไม่อนุมัติ'],
            'values' => [
                (int) ($statusCounts['draft_count'] ?? 0),
                (int) ($statusCounts['pending_count'] ?? 0),
                (int) ($statusCounts['revision_count'] ?? 0),
                (int) ($statusCounts['approved_count'] ?? 0),
                (int) ($statusCounts['completed_count'] ?? 0),
                (int) ($statusCounts['cancelled_count'] ?? 0),
                (int) ($statusCounts['rejected_count'] ?? 0),
            ],
        ],
        'recent_loans' => $recentLoans,
    ];
}
