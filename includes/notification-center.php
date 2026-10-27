<?php

$notificationError = '';
$notificationNotice = isset($_SESSION['notification_notice']) ? (string) $_SESSION['notification_notice'] : '';
unset($_SESSION['notification_notice']);
$db = app_db();

if (!notification_schema_ready($db)) {
    $notificationError = 'ระบบแจ้งเตือนยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $notificationError = 'แบบฟอร์มไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $operation = post_text('operation');
            if ($operation === 'mark_all_notifications') {
                $query = $db->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL');
                $query->execute([(int) $currentUser['id']]);
                $_SESSION['notification_notice'] = 'ทำเครื่องหมายว่าอ่านการแจ้งเตือนทั้งหมดแล้ว';
                header('Location: index.php?page=notifications', true, 303);
                exit;
            }
            if ($operation === 'open_notification') {
                $notificationId = filter_var(post_text('notification_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$notificationId) {
                    throw new DomainException('ไม่พบการแจ้งเตือนที่เลือก');
                }
                $query = $db->prepare('SELECT action_url FROM notifications WHERE id = ? AND user_id = ?');
                $query->execute([$notificationId, (int) $currentUser['id']]);
                $target = $query->fetchColumn();
                if ($target === false) {
                    throw new DomainException('ไม่พบการแจ้งเตือนที่เลือก');
                }
                $db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = ? AND user_id = ?')->execute([$notificationId, (int) $currentUser['id']]);
                header('Location: ' . notification_safe_action_url((string) $target), true, 303);
                exit;
            }
            throw new DomainException('รายการดำเนินการไม่ถูกต้อง');
        } catch (DomainException $exception) {
            http_response_code(422);
            $notificationError = $exception->getMessage();
        }
    }
}

$notificationFilter = isset($_GET['status']) && $_GET['status'] === 'unread' ? 'unread' : 'all';
$notificationRows = [];
$notificationTasks = [];
$notificationUnreadCount = 0;
$notificationOpenTaskCount = 0;

if (notification_schema_ready($db)) {
    $count = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $count->execute([(int) $currentUser['id']]);
    $notificationUnreadCount = (int) $count->fetchColumn();

    $where = $notificationFilter === 'unread' ? ' AND read_at IS NULL' : '';
    $query = $db->prepare("SELECT id, category, entity_type, entity_id, title, message, priority, action_url, read_at, created_at FROM notifications WHERE user_id = ?{$where} ORDER BY read_at IS NULL DESC, created_at DESC, id DESC LIMIT 100");
    $query->execute([(int) $currentUser['id']]);
    $notificationRows = $query->fetchAll();
    $notificationTasks = notification_open_tasks($db, (int) $currentUser['id'], 100);
    $notificationOpenTaskCount = count($notificationTasks);
}
