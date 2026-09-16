<?php

declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once '/www/server/nginx/html/LessonSchedule/auth_session.php';

$testConfigPath = '/www/server/nginx/html/LessonSchedule/.test-release-config.php';
if (!is_file($testConfigPath)) {
    http_response_code(503);
    exit('内测身份校验尚未配置');
}
$testConfig = require $testConfigPath;
if (!is_array($testConfig)) {
    http_response_code(503);
    exit('内测身份校验配置无效');
}

function readExactCookie(string $name): string
{
    $cookieHeader = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    foreach (explode(';', $cookieHeader) as $segment) {
        $parts = explode('=', trim($segment), 2);
        if (count($parts) !== 2 || rawurldecode($parts[0]) !== $name) {
            continue;
        }
        return rawurldecode($parts[1]);
    }
    return '';
}

function denyTestAccess(): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>管理员内测</title><style>body{margin:0;background:#f3f6fb;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{box-sizing:border-box;width:min(92vw,460px);margin:18vh auto 0;padding:28px 24px;border-radius:22px;background:#fff;box-shadow:0 18px 48px rgba(15,23,42,.1)}h1{margin:0 0 12px;font-size:22px}p{margin:0 0 22px;color:#64748b;line-height:1.7}a{display:block;padding:13px 16px;border-radius:13px;background:#2563eb;color:#fff;text-align:center;text-decoration:none;font-weight:700}</style></head><body><main class="card"><h1>管理员内测</h1><p>当前测试版本仅向已认证管理员开放。请先从企业微信进入正式课表并确认管理员身份，再打开本页面。</p><a href="/LessonSchedule/">返回正式版</a></main></body></html>';
    exit;
}

$rawSession = readExactCookie('LessonSchedule.AuthSession');
$decodedSession = $rawSession !== '' ? json_decode($rawSession, true) : null;
if (is_array($decodedSession) && ($decodedSession['type'] ?? '') === 'object' && is_array($decodedSession['data'] ?? null)) {
    $decodedSession = $decodedSession['data'];
}
if (!is_array($decodedSession)) {
    denyTestAccess();
}

$userId = normalizeUserId($decodedSession['userId'] ?? $decodedSession['user_id'] ?? '');
$authExp = normalizeAuthExpire($decodedSession['authExp'] ?? $decodedSession['auth_exp'] ?? '');
$authSig = trim((string) ($decodedSession['authSig'] ?? $decodedSession['auth_sig'] ?? ''));
if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
    denyTestAccess();
}

$conn = new mysqli(
    (string) ($testConfig['db_host'] ?? '127.0.0.1'),
    (string) ($testConfig['db_user'] ?? ''),
    (string) ($testConfig['db_pass'] ?? ''),
    (string) ($testConfig['db_name'] ?? ''),
    (int) ($testConfig['db_port'] ?? 3306)
);
if ($conn->connect_error) {
    http_response_code(503);
    exit('内测身份校验暂不可用');
}
$conn->set_charset('utf8mb4');
$stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
if (!$stmt) {
    $conn->close();
    denyTestAccess();
}
$stmt->bind_param('s', $userId);
$stmt->execute();
$result = $stmt->get_result();
$isAdmin = $result && $result->fetch_row();
$stmt->close();
$conn->close();
if (!$isAdmin) {
    denyTestAccess();
}

$candidate = __DIR__ . '/candidate.html';
if (!is_file($candidate)) {
    http_response_code(503);
    exit('内测版本尚未部署');
}
header('Content-Type: text/html; charset=utf-8');
readfile($candidate);
