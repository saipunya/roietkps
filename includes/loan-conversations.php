<?php

require_once __DIR__ . '/loan-documents.php';

function loan_conversation_channels(array $user, array $loan): array
{
    if (!account_can($user, 'loans.view')) {
        return [];
    }
    $channels = [
        'cooperative' => 'สหกรณ์ ↔ เจ้าหน้าที่ส่งเสริม',
        'province' => 'เจ้าหน้าที่ส่งเสริม ↔ เจ้าหน้าที่จังหวัด',
    ];
    if ($user['role_code'] === 'cooperative_user' && (int) $user['cooperative_id'] === (int) $loan['cooperative_id']) {
        return ['cooperative' => $channels['cooperative']];
    }
    if ((int) ($user['province_id'] ?? 0) !== (int) $loan['organization_province_id']) {
        return [];
    }
    if ($user['role_code'] === 'promotion_officer' && (int) $user['id'] === (int) $loan['assigned_officer_id']) {
        return $channels;
    }
    return role_is_provincial($user['role_code']) ? ['province' => $channels['province']] : [];
}

function loan_conversation_url(int $applicationId, string $channel): string
{
    return 'dashboard.php?page=loans&view=' . $applicationId . '#loan-board-' . $channel;
}

function loan_conversation_messages(PDO $db, int $applicationId, array $channels): array
{
    if (!$channels) {
        return [];
    }
    $query = $db->prepare('SELECT m.id AS message_id, m.body, m.created_at, u.full_name,
        f.id AS attachment_id, f.original_name, f.file_size
        FROM loan_messages m JOIN users u ON u.id = m.author_user_id
        LEFT JOIN loan_message_attachments f ON f.loan_message_id = m.id
        WHERE m.loan_application_id = ? AND m.channel = ? ORDER BY m.id DESC, f.id');
    $byChannel = [];
    foreach (array_keys($channels) as $channel) {
        $query->execute([$applicationId, $channel]);
        $messages = [];
        foreach ($query as $row) {
            $messageId = (int) $row['message_id'];
            if (!isset($messages[$messageId])) {
                $messages[$messageId] = [
                    'full_name' => $row['full_name'],
                    'body' => $row['body'],
                    'created_at' => $row['created_at'],
                    'attachments' => [],
                ];
            }
            if ($row['attachment_id'] !== null) {
                $messages[$messageId]['attachments'][] = [
                    'attachment_id' => $row['attachment_id'],
                    'original_name' => $row['original_name'],
                    'file_size' => $row['file_size'],
                ];
            }
        }
        $byChannel[$channel] = $messages;
    }
    return $byChannel;
}

function loan_conversation_recipients(PDO $db, array $author, array $loan, string $channel): array
{
    if ($author['role_code'] === 'promotion_officer') {
        return $channel === 'cooperative'
            ? notification_active_user_ids($db, 'cooperative_user', null, (int) $loan['cooperative_id'])
            : notification_provincial_user_ids($db, (int) $loan['organization_province_id']);
    }
    if (!$loan['assigned_officer_id']) {
        return [];
    }
    $query = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.province_id = ? AND u.status = 'active' AND r.is_active = 1 AND r.code = 'promotion_officer'");
    $query->execute([$loan['assigned_officer_id'], $loan['organization_province_id']]);
    $recipientId = $query->fetchColumn();
    return $recipientId ? [(int) $recipientId] : [];
}

// Save the upload message in the same transaction as its documents.
function loan_conversation_post(PDO $db, array $author, int $applicationId, string $channel, string $token, string $body, bool $notify = true): bool
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $token) || !mb_check_encoding($body, 'UTF-8') || mb_strlen($body, 'UTF-8') > 2000) {
        throw new DomainException('กรุณาตรวจสอบข้อความและลองส่งอีกครั้ง ข้อความยาวได้ไม่เกิน 2,000 ตัวอักษร');
    }
    $query = $db->prepare('SELECT a.application_no, a.cooperative_id, a.assigned_officer_id, c.province_id AS organization_province_id
        FROM loan_applications a JOIN cooperatives c ON c.id = a.cooperative_id WHERE a.id = ? FOR UPDATE');
    $query->execute([$applicationId]);
    $loan = $query->fetch();
    $channels = $loan ? loan_conversation_channels($author, $loan) : [];
    if (!isset($channels[$channel])) {
        throw new DomainException('คุณไม่มีสิทธิ์บันทึกข้อความสำหรับคำขอนี้');
    }

    $query = $db->prepare('SELECT id FROM loan_messages WHERE loan_application_id = ? AND channel = ? AND author_user_id = ? AND submission_token = ?');
    $query->execute([$applicationId, $channel, (int) $author['id'], $token]);
    if ($query->fetchColumn()) {
        return false;
    }

    $query = $db->prepare('INSERT INTO loan_messages (loan_application_id, channel, author_user_id, submission_token, body) VALUES (?, ?, ?, ?, ?)');
    $query->execute([$applicationId, $channel, (int) $author['id'], $token, $body]);
    if (!$notify) {
        return true;
    }
    $messageId = (int) $db->lastInsertId();
    notification_create_for_users(
        $db,
        loan_conversation_recipients($db, $author, $loan, $channel),
        'loan_message',
        'ข้อความใหม่เกี่ยวกับคำขอกู้ ' . $loan['application_no'],
        $channels[$channel],
        'normal',
        loan_conversation_url($applicationId, $channel),
        'loan-message:' . $messageId,
        'loan_message',
        $messageId
    );
    return true;
}

function loan_conversation_attachment_access_row(PDO $db, array $user, int $attachmentId): ?array
{
    $query = $db->prepare('SELECT f.*, m.channel, a.cooperative_id, a.assigned_officer_id, c.province_id AS organization_province_id
        FROM loan_message_attachments f JOIN loan_messages m ON m.id = f.loan_message_id
        JOIN loan_applications a ON a.id = m.loan_application_id JOIN cooperatives c ON c.id = a.cooperative_id WHERE f.id = ?');
    $query->execute([$attachmentId]);
    $attachment = $query->fetch();
    if (!$attachment) {
        return null;
    }
    $channels = loan_conversation_channels($user, $attachment);
    return isset($channels[$attachment['channel']]) ? $attachment : null;
}
