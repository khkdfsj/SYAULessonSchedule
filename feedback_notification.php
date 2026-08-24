<?php

require_once __DIR__ . '/auth_session.php';

define('FEEDBACK_NOTIFICATION_RELAY', 'https://syauinfo.syau.edu.cn/LessonSchedule/feedbackNotifyRelay.php');

function feedbackNotificationTrimText($value, $maxLength)
{
    $text = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function ensureFeedbackNotificationTable($conn)
{
    if (!$conn instanceof mysqli) {
        return false;
    }
    if (!$conn->query(
        'CREATE TABLE IF NOT EXISTS feedback_notification_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_key VARCHAR(160) DEFAULT NULL,
            event_key VARCHAR(80) NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            reply_id BIGINT UNSIGNED DEFAULT NULL,
            actor_user_id VARCHAR(20) NOT NULL,
            recipient_user_id VARCHAR(20) NOT NULL,
            recipient_type VARCHAR(20) NOT NULL DEFAULT \'admin\',
            provider_agent_id VARCHAR(20) DEFAULT NULL,
            thread_title VARCHAR(120) NOT NULL DEFAULT \'\',
            message_content VARCHAR(500) NOT NULL DEFAULT \'\',
            success TINYINT(1) NOT NULL DEFAULT 0,
            detail VARCHAR(500) NOT NULL DEFAULT \'\',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_feedback_notification_key (notification_key),
            KEY idx_feedback_notification_thread (thread_id, created_at),
            KEY idx_feedback_notification_success (success, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )) {
        return false;
    }

    $columns = [
        'notification_key' => 'VARCHAR(160) DEFAULT NULL AFTER id',
        'reply_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER thread_id',
        'recipient_type' => 'VARCHAR(20) NOT NULL DEFAULT \'admin\' AFTER recipient_user_id',
        'thread_title' => 'VARCHAR(120) NOT NULL DEFAULT \'\' AFTER provider_agent_id',
        'message_content' => 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER thread_title'
    ];
    foreach ($columns as $name => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM feedback_notification_logs LIKE '{$name}'");
        if (!$result || $result->num_rows === 0) {
            if (!$conn->query("ALTER TABLE feedback_notification_logs ADD COLUMN {$name} {$definition}")) {
                return false;
            }
        }
    }
    $indexResult = $conn->query("SHOW INDEX FROM feedback_notification_logs WHERE Key_name = 'uq_feedback_notification_key'");
    if (!$indexResult || $indexResult->num_rows === 0) {
        if (!$conn->query('ALTER TABLE feedback_notification_logs ADD UNIQUE KEY uq_feedback_notification_key (notification_key)')) {
            return false;
        }
    }
    return true;
}

function getFeedbackAdminNotificationRecipients($conn)
{
    if (!$conn instanceof mysqli) {
        return [];
    }
    $result = $conn->query(
        'SELECT user_id FROM feedback_admins
         WHERE enabled = 1 AND notification_enabled = 1
         ORDER BY user_id ASC'
    );
    if (!$result) {
        return [];
    }
    $recipients = [];
    while ($row = $result->fetch_assoc()) {
        $userId = normalizeUserId($row['user_id'] ?? '');
        if (preg_match('/^\d{8,12}$/', $userId)) {
            $recipients[] = $userId;
        }
    }
    return array_values(array_unique($recipients));
}

function isFeedbackNotificationOfflineTime()
{
    $hour = (int) date('G');
    return $hour >= 22 || $hour < 6;
}

function buildFeedbackNotificationKey($eventKey, $threadId, $replyId, $recipient)
{
    return feedbackNotificationTrimText($eventKey, 40) . ':' . (int) $threadId . ':' . (int) $replyId . ':' . normalizeUserId($recipient);
}

function reserveFeedbackNotification($conn, $notificationKey, $eventKey, $threadId, $replyId, $actorUserId, $recipient, $recipientType, $threadTitle, $content)
{
    if (!ensureFeedbackNotificationTable($conn)) {
        return false;
    }
    $safeTitle = feedbackNotificationTrimText($threadTitle, 120);
    $safeContent = feedbackNotificationTrimText(preg_replace('/\s+/', ' ', (string) $content), 500);
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO feedback_notification_logs
         (notification_key, event_key, thread_id, reply_id, actor_user_id, recipient_user_id, recipient_type,
          provider_agent_id, thread_title, message_content, success, detail, retry_count, next_retry_at, created_at)
         VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, ?, \'\', ?, ?, 0, \'准备发送\', 0, NULL, NOW())'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssiisssss', $notificationKey, $eventKey, $threadId, $replyId, $actorUserId, $recipient, $recipientType, $safeTitle, $safeContent);
    $stmt->execute();
    $reserved = $stmt->affected_rows === 1;
    $stmt->close();
    return $reserved;
}

function updateFeedbackNotificationResult($conn, $notificationKey, $success, $agentId, $detail)
{
    $ok = $success ? 1 : 0;
    $safeDetail = feedbackNotificationTrimText($detail, 500);
    $stmt = $conn->prepare(
        'UPDATE feedback_notification_logs
         SET success = ?, provider_agent_id = ?, detail = ?,
             next_retry_at = IF(? = 1, NULL, DATE_ADD(NOW(), INTERVAL 2 MINUTE))
         WHERE notification_key = ?'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('issis', $ok, $agentId, $safeDetail, $ok, $notificationKey);
    $stmt->execute();
    $stmt->close();
}

function deliverFeedbackNotification($notificationKey, $eventKey, $threadId, $actorUserId, $recipient, $recipientType, $threadTitle, $content)
{
    if (isFeedbackNotificationOfflineTime()) {
        return ['success' => false, 'agent_id' => '', 'detail' => '夜间等待发送'];
    }

    $isUserRecipient = $recipientType === 'user';
    $eventTitle = $isUserRecipient
        ? '你的课表反馈有新回复'
        : ($eventKey === 'thread_created' ? '收到新的课表反馈' : '课表反馈有新回复');
    $description = "反馈编号：#{$threadId}\n标题：" . feedbackNotificationTrimText($threadTitle, 80);
    if ($isUserRecipient) {
        $description .= "\n管理员回复：" . feedbackNotificationTrimText(preg_replace('/\s+/', ' ', (string) $content), 180);
    } else {
        $description .= "\n提交用户：{$actorUserId}\n内容：" . feedbackNotificationTrimText(preg_replace('/\s+/', ' ', (string) $content), 180);
    }
    $payload = [
        'notification_key' => $notificationKey,
        'event_key' => $eventKey,
        'thread_id' => (int) $threadId,
        'recipient_user_id' => $recipient,
        'title' => $eventTitle,
        'description' => $description,
        'url' => 'https://debug.91nongye.cn/LessonSchedule/#/pages/feedback-detail/index?threadId=' . (int) $threadId
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . "\n" . $body, AUTH_SHARED_SECRET);

    $ch = curl_init(FEEDBACK_NOTIFICATION_RELAY);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 MicroMessenger/8.0.0 wxwork/4.1.0',
            'X-Lesson-Timestamp: ' . $timestamp,
            'X-Lesson-Signature: ' . $signature
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 18
    ]);
    $responseBody = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $response = json_decode((string) $responseBody, true);
    $success = $httpStatus === 200 && is_array($response) && !empty($response['success']);
    $agentId = is_array($response) ? (string) ($response['agent_id'] ?? '') : '';
    $detail = $success
        ? (!empty($response['duplicate']) ? '去重确认成功' : '即时发送成功')
        : ($curlError !== '' ? $curlError : ('HTTP ' . $httpStatus . ' ' . (is_array($response) ? ($response['message'] ?? '') : 'invalid response')));
    return ['success' => $success, 'agent_id' => $agentId, 'detail' => $detail];
}

function queueFeedbackNotification($conn, $eventKey, $threadId, $replyId, $actorUserId, $recipient, $recipientType, $threadTitle, $content)
{
    $notificationKey = buildFeedbackNotificationKey($eventKey, $threadId, $replyId, $recipient);
    if (!reserveFeedbackNotification($conn, $notificationKey, $eventKey, $threadId, $replyId, $actorUserId, $recipient, $recipientType, $threadTitle, $content)) {
        return true;
    }
    $result = deliverFeedbackNotification($notificationKey, $eventKey, $threadId, $actorUserId, $recipient, $recipientType, $threadTitle, $content);
    updateFeedbackNotificationResult($conn, $notificationKey, $result['success'], $result['agent_id'], $result['detail']);
    return $result['success'];
}

function sendFeedbackAdminNotification($conn, $eventKey, $threadId, $replyId, $actorUserId, $threadTitle, $content)
{
    $recipients = getFeedbackAdminNotificationRecipients($conn);
    if (!$recipients) {
        return false;
    }
    $allSucceeded = true;
    foreach ($recipients as $recipient) {
        if (!queueFeedbackNotification($conn, $eventKey, $threadId, $replyId, $actorUserId, $recipient, 'admin', $threadTitle, $content)) {
            $allSucceeded = false;
        }
    }
    return $allSucceeded;
}

function sendFeedbackUserNotification($conn, $threadId, $replyId, $actorUserId, $recipient, $threadTitle, $content)
{
    $recipient = normalizeUserId($recipient);
    if (!preg_match('/^\d{8,12}$/', $recipient) || $recipient === normalizeUserId($actorUserId)) {
        return false;
    }
    return queueFeedbackNotification($conn, 'admin_replied', $threadId, $replyId, $actorUserId, $recipient, 'user', $threadTitle, $content);
}
