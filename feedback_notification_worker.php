<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'Off');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$lockHandle = fopen('/tmp/lesson_schedule_feedback_notification_worker.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
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

if (!ensureFeedbackNotificationTable($conn)) {
    $conn->close();
    exit(1);
}

$result = $conn->query(
    'SELECT l.id, l.notification_key, l.event_key, l.thread_id, l.reply_id, l.actor_user_id,
            l.recipient_user_id, l.recipient_type, l.thread_title, l.message_content, l.retry_count,
            COALESCE(NULLIF(l.thread_title, \'\'), t.title, \'课表反馈\') AS resolved_thread_title
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
    $notificationKey = trim((string) ($row['notification_key'] ?? ''));
    if ($notificationKey === '') {
        $notificationKey = 'legacy:' . $logId . ':' . $row['recipient_user_id'];
    }
    $content = trim((string) ($row['message_content'] ?? ''));
    if ($content === '') {
        $content = '你关注的反馈有新进展，请点击查看详情。';
    }
    $delivery = deliverFeedbackNotification(
        $notificationKey,
        $row['event_key'],
        (int) $row['thread_id'],
        $row['actor_user_id'],
        $row['recipient_user_id'],
        $row['recipient_type'] ?: 'admin',
        $row['resolved_thread_title'],
        $content
    );

    if ($delivery['success']) {
        $stmt = $conn->prepare(
            'UPDATE feedback_notification_logs
             SET notification_key = COALESCE(notification_key, ?), success = 1, provider_agent_id = ?,
                 detail = \'重试发送成功\', retry_count = retry_count + 1, next_retry_at = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('ssi', $notificationKey, $delivery['agent_id'], $logId);
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
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
