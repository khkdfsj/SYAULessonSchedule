<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'Off');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$hour = (int) date('G');
if ($hour >= 22 || $hour < 6) {
    exit(0);
}

require_once __DIR__ . '/auth_session.php';

function trimText($value, $maxLength)
{
    $text = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

require_once __DIR__ . '/feedback_notification.php';

$conn = @new mysqli('127.0.0.1', 'LessonTable', 'syau8848@', 'LessonTable', 3306);
if ($conn->connect_errno) {
    exit(1);
}
$conn->set_charset('utf8mb4');

$result = $conn->query(
    'SELECT l.id, l.event_key, l.thread_id, l.actor_user_id, l.recipient_user_id, l.retry_count,
            COALESCE(t.title, \'课表反馈\') AS thread_title
     FROM feedback_notification_logs l
     LEFT JOIN feedback_threads t ON t.id = l.thread_id
     WHERE l.success = 0
       AND l.retry_count < 8
       AND l.next_retry_at IS NOT NULL
       AND l.next_retry_at <= NOW()
     ORDER BY l.next_retry_at ASC, l.id ASC
     LIMIT 5'
);

if (!$result) {
    $conn->close();
    exit(1);
}

while ($row = $result->fetch_assoc()) {
    $logId = (int) $row['id'];
    $retryCount = (int) $row['retry_count'];
    $success = sendFeedbackAdminNotificationToUser(
        $conn,
        $row['event_key'],
        (int) $row['thread_id'],
        $row['actor_user_id'],
        $row['recipient_user_id'],
        $row['thread_title'],
        '此前通知发送失败，现已自动补发，请点击查看反馈详情。',
        false
    );

    if ($success) {
        $stmt = $conn->prepare(
            'UPDATE feedback_notification_logs
             SET success = 1, detail = \'自动补发成功\', retry_count = retry_count + 1, next_retry_at = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('i', $logId);
    } else {
        $nextRetryCount = $retryCount + 1;
        $delayMinutes = min(30, 2 ** min(5, $nextRetryCount));
        $nextRetryAt = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
        $stmt = $conn->prepare(
            'UPDATE feedback_notification_logs
             SET detail = \'自动补发失败\', retry_count = ?, next_retry_at = ?
             WHERE id = ?'
        );
        $stmt->bind_param('isi', $nextRetryCount, $nextRetryAt, $logId);
    }
    $stmt->execute();
    $stmt->close();
}

$conn->close();
