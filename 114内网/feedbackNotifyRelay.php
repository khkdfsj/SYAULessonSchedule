<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';

const ADMIN_USER_ID = '2023195077';
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
        CURLOPT_TIMEOUT => 6
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

function sendByAgent($agentId, $payload)
{
    $tokenBody = @file_get_contents('http://210.47.163.113/qywx/actn_' . $agentId . '.txt');
    $tokenData = json_decode((string) $tokenBody, true);
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
$eventKey = trim((string) ($input['event_key'] ?? ''));
$threadId = (int) ($input['thread_id'] ?? 0);
$recipient = trim((string) ($input['recipient_user_id'] ?? ''));
if (!in_array($eventKey, ['thread_created', 'user_replied', 'notification_test'], true)
    || $threadId <= 0 || $recipient !== ADMIN_USER_ID) {
    respond(400, ['success' => false, 'message' => 'invalid payload']);
}

$title = mb_substr(trim((string) ($input['title'] ?? '课表反馈通知')), 0, 80, 'UTF-8');
$description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500, 'UTF-8');
$url = trim((string) ($input['url'] ?? ''));
if (strpos($url, 'https://debug.91nongye.cn/LessonSchedule/') !== 0) {
    respond(400, ['success' => false, 'message' => 'invalid url']);
}

$safeDescription = nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'), false);
$message = [
    'touser' => ADMIN_USER_ID,
    'msgtype' => 'textcard',
    'textcard' => [
        'title' => $title,
        'description' => '<div class="gray">' . date('Y-m-d H:i:s') . '</div><div class="normal">' . $safeDescription . '</div>',
        'url' => $url,
        'btntxt' => '查看反馈'
    ],
    'safe' => 0,
    'enable_duplicate_check' => 1,
    'duplicate_check_interval' => 60
];

$attempts = [];
foreach ([PRIMARY_AGENT_ID, FALLBACK_AGENT_ID] as $agentId) {
    $result = sendByAgent($agentId, $message);
    $attempts[] = ['agent_id' => $agentId, 'errcode' => (int) ($result['errcode'] ?? -1)];
    if ((int) ($result['errcode'] ?? -1) === 0) {
        respond(200, ['success' => true, 'agent_id' => (string) $agentId]);
    }
}

respond(502, ['success' => false, 'message' => 'application message send failed', 'attempts' => $attempts]);

