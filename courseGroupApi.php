<?php

/**
 * 课表公网服务器的课程群转发接口。
 *
 * 本文件只负责校验课表登录签名和转发请求，不连接建群数据库、不保存学生名单，
 * 所有课程归属验证、课程群创建和入群操作仍由 114、113 内网服务完成。
 */

require_once __DIR__ . '/auth_session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function courseGroupSend($code, $message, $data = array(), $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode(array_merge(array(
        'code' => (int) $code,
        'msg' => (string) $message
    ), is_array($data) ? $data : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function courseGroupPostJson($url, $payload)
{
    $body = json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            // 学校公网入口会校验企业微信浏览器 UA；与现有课表接口保持一致才能转入 114。
            'User-Agent: Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/33.0.0.0 Mobile Safari/537.36 MicroMessenger/6.0.0.54_r849063.501 NetType/WIFI',
            'Content-Length: ' . strlen($body)
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ));
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('body' => $response, 'error' => $error, 'status' => $status);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    courseGroupSend(405, '仅支持 POST 请求', array(), 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    courseGroupSend(400, '请求参数格式错误', array(), 400);
}

$userId = normalizeUserId(isset($input['user_id']) ? $input['user_id'] : '');
$authExp = isset($input['auth_exp']) ? $input['auth_exp'] : '';
$authSig = isset($input['auth_sig']) ? $input['auth_sig'] : '';
$action = isset($input['action']) ? trim((string) $input['action']) : '';
$allowedActions = array('status', 'create', 'resolve', 'skipMissing', 'join');

if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
    courseGroupSend(401, '身份认证已失效，请重新认证', array(), 401);
}
if (!in_array($action, $allowedActions, true)) {
    courseGroupSend(400, '课程群操作类型无效', array(), 400);
}

// 114 与本服务使用相同的签名密钥，114 会再次验证身份和课程归属。
$upstream = courseGroupPostJson(
    'https://syauinfo.syau.edu.cn/LessonSchedule/course_group_bridge.php',
    $input
);
if ($upstream['body'] === false || $upstream['error'] !== '') {
    courseGroupSend(502, '课程群服务暂时无法连接，请稍后重试', array(), 502);
}

$decoded = json_decode($upstream['body'], true);
if (!is_array($decoded)) {
    courseGroupSend(502, '课程群服务返回格式异常，请稍后重试', array(), 502);
}

$status = $upstream['status'] >= 400 && $upstream['status'] < 600 ? $upstream['status'] : 200;
http_response_code($status);
echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
