<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';

const PRIMARY_AGENT_ID = 1000060;
const FALLBACK_AGENT_ID = 1000038;

function respond($status, $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function postJson($url, $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['errcode' => -1, 'errmsg' => $error ?: 'request failed'];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : ['errcode' => -1, 'errmsg' => 'invalid response'];
}

function getJson($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 2
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['error' => $error ?: 'request failed'];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : ['error' => 'invalid response'];
}

function sendByAgent($agentId, $payload)
{
    $tokenData = getJson('http://210.47.163.113/qywx/actn_' . $agentId . '.txt');
    if (!is_array($tokenData) || empty($tokenData['access_token'])) {
        return ['errcode' => -1, 'errmsg' => 'access token unavailable'];
    }
    $payload['agentid'] = (int) $agentId;
    return postJson(
        'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . rawurlencode($tokenData['access_token']),
        $payload
    );
}

$rawBody = file_get_contents('php://input');
$timestamp = trim((string) ($_SERVER['HTTP_X_LESSON_TIMESTAMP'] ?? ''));
$signature = trim((string) ($_SERVER['HTTP_X_LESSON_SIGNATURE'] ?? ''));
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    respond(401, ['success' => false, 'message' => 'request expired']);
}
$expected = hash_hmac('sha256', $timestamp . "\n" . $rawBody, AUTH_SHARED_SECRET);
if ($signature === '' || !hash_equals($expected, $signature)) {
    respond(401, ['success' => false, 'message' => 'invalid signature']);
}

$input = json_decode($rawBody, true);
$notificationKey = trim((string) ($input['notification_key'] ?? ''));
$eventKey = trim((string) ($input['event_key'] ?? ''));
$threadId = (int) ($input['thread_id'] ?? 0);
$recipient = trim((string) ($input['recipient_user_id'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9:_-]{10,160}$/', $notificationKey)
    || !in_array($eventKey, ['thread_created', 'user_replied', 'admin_replied', 'notification_test'], true)
    || $threadId <= 0 || !preg_match('/^\d{8,12}$/', $recipient)) {
    respond(400, ['success' => false, 'message' => 'invalid payload']);
}

$dedupeDir = sys_get_temp_dir() . '/lesson_schedule_feedback_notification_dedupe';
if (!is_dir($dedupeDir) && !mkdir($dedupeDir, 0700, true) && !is_dir($dedupeDir)) {
    respond(500, ['success' => false, 'message' => 'dedupe storage unavailable']);
}
$dedupeFile = $dedupeDir . '/' . hash('sha256', $notificationKey) . '.json';
$dedupeHandle = fopen($dedupeFile, 'c+');
if (!$dedupeHandle || !flock($dedupeHandle, LOCK_EX)) {
    respond(500, ['success' => false, 'message' => 'dedupe lock unavailable']);
}
$existing = json_decode((string) stream_get_contents($dedupeHandle), true);
if (is_array($existing) && ($existing['status'] ?? '') === 'success') {
    respond(200, [
        'success' => true,
        'duplicate' => true,
        'agent_id' => (string) ($existing['agent_id'] ?? '')
    ]);
}

$title = mb_substr(trim((string) ($input['title'] ?? '课表反馈通知')), 0, 80, 'UTF-8');
$description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500, 'UTF-8');
$url = trim((string) ($input['url'] ?? ''));
if (strpos($url, 'https://debug.91nongye.cn/LessonSchedule/') !== 0) {
    respond(400, ['success' => false, 'message' => 'invalid url']);
}

$safeDescription = nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'), false);
$message = [
    'touser' => $recipient,
    'msgtype' => 'textcard',
    'textcard' => [
        'title' => $title,
        'description' => '<div class="normal">' . $safeDescription . '</div>',
        'url' => $url,
        'btntxt' => '查看反馈'
    ],
    'safe' => 0,
    'enable_duplicate_check' => 1,
    'duplicate_check_interval' => 1800
];

$attempts = [];
foreach ([PRIMARY_AGENT_ID, FALLBACK_AGENT_ID] as $agentId) {
    $result = sendByAgent($agentId, $message);
    $attempts[] = ['agent_id' => $agentId, 'errcode' => (int) ($result['errcode'] ?? -1)];
    if ((int) ($result['errcode'] ?? -1) === 0) {
        rewind($dedupeHandle);
        ftruncate($dedupeHandle, 0);
        fwrite($dedupeHandle, json_encode([
            'status' => 'success',
            'agent_id' => (string) $agentId,
            'sent_at' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($dedupeHandle);
        respond(200, ['success' => true, 'agent_id' => (string) $agentId]);
    }
}

respond(502, ['success' => false, 'message' => 'application message send failed', 'attempts' => $attempts]);

