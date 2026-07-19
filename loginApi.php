<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'Off');
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';

define('PROFILE_API_URL', 'https://syauinfo.syau.edu.cn/LessonSchedule/student_profile.php');
define('PROFILE_API_TIMEOUT', 60);
define('PROFILE_API_CONNECT_TIMEOUT', 8);
define('WECHAT_BROWSER_USER_AGENT', 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/33.0.0.0 Mobile Safari/537.36 MicroMessenger/6.0.0.54_r849063.501 NetType/WIFI');

function sendJson($code, $msg, $data = [], $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode([
        'code' => (int) $code,
        'msg' => (string) $msg,
        'data' => is_array($data) ? $data : []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonInput()
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function trimText($value, $maxLength = 120)
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function getProfileApiUrl()
{
    return PROFILE_API_URL;
}

function requestProfileApi($url, array $payload)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: ' . WECHAT_BROWSER_USER_AGENT,
        'Content-Length: ' . strlen($body)
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, PROFILE_API_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, PROFILE_API_CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'body' => $responseBody,
        'http_status' => $httpStatus,
        'curl_error' => $curlError
    ];
}

function sanitizeProfile(array $profile)
{
    return [
        'student_number' => normalizeUserId($profile['student_number'] ?? ''),
        'full_name' => trimText($profile['full_name'] ?? '', 64),
        'grade' => trimText($profile['grade'] ?? '', 32),
        'department' => trimText($profile['department'] ?? '', 64),
        'major' => trimText($profile['major'] ?? '', 64),
        'admission_grade' => trimText($profile['admission_grade'] ?? '', 16),
        'schooling_type' => trimText($profile['schooling_type'] ?? '', 32)
    ];
}

function normalizeLoginPayload(array $input)
{
    $payload = [
        'account' => trimText($input['account'] ?? '', 64),
        'password' => is_string($input['password'] ?? null) || is_numeric($input['password'] ?? null)
            ? (string) $input['password']
            : ''
    ];

    if (array_key_exists('captcha_token', $input)) {
        $payload['captcha_token'] = trim((string) $input['captcha_token']);
    }
    if (array_key_exists('captcha_code', $input)) {
        $payload['captcha_code'] = trim((string) $input['captcha_code']);
    }
    if (array_key_exists('enable_ocr', $input)) {
        $payload['enable_ocr'] = $input['enable_ocr'] === true;
    }

    return $payload;
}

try {
    $input = readJsonInput();
    $payload = normalizeLoginPayload($input);

    if ($payload['account'] === '' || $payload['password'] === '') {
        sendJson(400, '账号和密码不能为空', [], 400);
    }

    $apiUrl = getProfileApiUrl();
    $upstream = requestProfileApi($apiUrl, $payload);
    if ($upstream['body'] === false || $upstream['body'] === null || $upstream['curl_error'] !== '') {
        sendJson(502, '登录服务暂时不可用', [], 502);
    }

    $decoded = json_decode($upstream['body'], true);
    if (!is_array($decoded)) {
        sendJson(502, '登录服务返回格式异常', [], 502);
    }

    $code = (int) ($decoded['code'] ?? 502);
    $message = trim((string) ($decoded['message'] ?? $decoded['msg'] ?? '登录失败'));
    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

    if ($code !== 200) {
        $httpStatus = $upstream['http_status'] >= 100 && $upstream['http_status'] < 600
            ? $upstream['http_status']
            : $code;
        sendJson($code, $message !== '' ? $message : '登录失败', $data, $httpStatus);
    }

    $profile = sanitizeProfile(is_array($data['profile'] ?? null) ? $data['profile'] : []);
    $userId = normalizeUserId($profile['student_number'] ?? '');
    if ($userId === '') {
        sendJson(502, '登录成功但未解析到有效学号', [], 502);
    }

    $session = issueAuthSession($userId);
    sendJson(200, '登录成功', [
        'user_id' => $userId,
        'auth_exp' => $session['auth_exp'],
        'auth_sig' => $session['auth_sig'],
        'auth_source' => 'manual',
        'profile' => $profile
    ]);
} catch (Throwable $throwable) {
    sendJson(500, '手动登录处理失败', [], 500);
}
