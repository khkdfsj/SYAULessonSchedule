<?php

require_once __DIR__ . '/auth_session.php';

define('FEEDBACK_NOTIFICATION_RELAY', 'https://syauinfo.syau.edu.cn/LessonSchedule/feedbackNotifyRelay.php');

function ensureFeedbackNotificationTable($conn)
{
    return $conn instanceof mysqli && $conn->query(
        'CREATE TABLE IF NOT EXISTS feedback_notification_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(80) NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            actor_user_id VARCHAR(20) NOT NULL,
            recipient_user_id VARCHAR(20) NOT NULL,
            provider_agent_id VARCHAR(20) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            detail VARCHAR(500) NOT NULL DEFAULT \'\',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_feedback_notification_thread (thread_id, created_at),
            KEY idx_feedback_notification_success (success, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function getFeedbackAdminNotificationRecipients($conn)
{
    if (!$conn instanceof mysqli) {
        return [];
    }
    $result = $conn->query(
        'SELECT user_id FROM feedback_admins WHERE enabled = 1 ORDER BY user_id ASC'
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

function saveFeedbackNotificationLog($conn, $eventKey, $threadId, $actorUserId, $recipient, $agentId, $success, $detail)
{
    if (!ensureFeedbackNotificationTable($conn)) {
        return;
    }
    $safeDetail = trimText($detail, 500);
    $stmt = $conn->prepare(
        'INSERT INTO feedback_notification_logs
         (event_key, thread_id, actor_user_id, recipient_user_id, provider_agent_id, success, detail, retry_count, next_retry_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, IF(? = 1, NULL, DATE_ADD(NOW(), INTERVAL 2 MINUTE)), NOW())'
    );
    if (!$stmt) {
        return;
    }
    $ok = $success ? 1 : 0;
    $stmt->bind_param('sisssisi', $eventKey, $threadId, $actorUserId, $recipient, $agentId, $ok, $safeDetail, $ok);
    $stmt->execute();
    $stmt->close();
}

function sendFeedbackAdminNotificationToUser($conn, $eventKey, $threadId, $actorUserId, $recipient, $threadTitle, $content, $saveLog = true)
{
    if (isFeedbackNotificationOfflineTime()) {
        if ($saveLog) {
            saveFeedbackNotificationLog(
                $conn,
                $eventKey,
                (int) $threadId,
                $actorUserId,
                $recipient,
                '',
                false,
                '夜间等待自动补发'
            );
        }
        return false;
    }

    $eventTitle = $eventKey === 'thread_created' ? '收到新的课表反馈' : '课表反馈有新回复';
    $description = "反馈编号：#{$threadId}\n提交用户：{$actorUserId}\n标题：" . trimText($threadTitle, 80)
        . "\n内容：" . trimText(preg_replace('/\s+/', ' ', (string) $content), 180);
    $payload = [
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
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5
    ]);
    $responseBody = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $response = json_decode((string) $responseBody, true);
    $success = $httpStatus === 200 && is_array($response) && !empty($response['success']);
    $agentId = is_array($response) ? (string) ($response['agent_id'] ?? '') : '';
    $detail = $success
        ? '发送成功'
        : ($curlError !== '' ? $curlError : ('HTTP ' . $httpStatus . ' ' . (is_array($response) ? ($response['message'] ?? '') : 'invalid response')));
    if ($saveLog) {
        saveFeedbackNotificationLog($conn, $eventKey, (int) $threadId, $actorUserId, $recipient, $agentId, $success, $detail);
    }
    return $success;
}

function sendFeedbackAdminNotification($conn, $eventKey, $threadId, $actorUserId, $threadTitle, $content)
{
    $recipients = getFeedbackAdminNotificationRecipients($conn);
    if (!$recipients) {
        return false;
    }

    $allSucceeded = true;
    foreach ($recipients as $recipient) {
        if (!sendFeedbackAdminNotificationToUser(
            $conn,
            $eventKey,
            $threadId,
            $actorUserId,
            $recipient,
            $threadTitle,
            $content
        )) {
            $allSucceeded = false;
        }
    }
    return $allSucceeded;
}

