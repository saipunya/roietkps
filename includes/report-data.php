<?php

function load_report_counts(PDO $db, array $user): array
{
    $organizationScope = '1 = 1';
    $loanScope = '1 = 1';
    $organizationParameters = [];
    $loanParameters = [];

    if (role_is_provincial($user['role_code'])) {
        $organizationScope = $loanScope = 'c.province_id = ?';
        $organizationParameters = $loanParameters = [(int) $user['province_id']];
    } elseif ($user['role_code'] === 'promotion_officer') {
        $organizationScope = 'EXISTS (SELECT 1 FROM organization_officer_assignments oa WHERE oa.cooperative_id = c.id AND oa.officer_user_id = ? AND (oa.ends_on IS NULL OR oa.ends_on >= CURRENT_DATE()))';
        $loanScope = '(a.assigned_officer_id = ? OR ' . $organizationScope . ')';
        $organizationParameters = [(int) $user['id']];
        $loanParameters = [(int) $user['id'], (int) $user['id']];
    } elseif ($user['role_code'] === 'cooperative_user') {
        $organizationScope = 'c.id = ?';
        $loanScope = 'c.id = ?';
        $organizationParameters = $loanParameters = [(int) $user['cooperative_id']];
    } elseif (!in_array($user['role_code'], ['system_admin', 'central_officer'], true)) {
        return ['registry' => 0, 'loans' => 0, 'debt' => 0];
    }

    $count = function (string $sql, array $parameters) use ($db): int {
        $query = $db->prepare($sql);
        $query->execute($parameters);
        return (int) $query->fetchColumn();
    };

    return [
        'registry' => $count("SELECT COUNT(*) FROM cooperatives c WHERE {$organizationScope}", $organizationParameters),
        'loans' => $count("SELECT COUNT(*) FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE {$loanScope}", $loanParameters),
        'debt' => $count("SELECT COUNT(*) FROM loan_contracts lc JOIN loan_applications a ON a.id = lc.loan_application_id JOIN cooperatives c ON c.id = lc.cooperative_id WHERE lc.status IN ('active', 'completed', 'closed') AND {$loanScope}", $loanParameters),
    ];
}
