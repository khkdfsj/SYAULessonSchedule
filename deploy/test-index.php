<?php

declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once '/www/server/nginx/html/LessonSchedule/auth_session.php';
require_once '/www/server/nginx/html/LessonSchedule/admin-access.php';

function denyTestAccess(): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>管理员内测</title><style>body{margin:0;background:#f3f6fb;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{box-sizing:border-box;width:min(92vw,460px);margin:18vh auto 0;padding:28px 24px;border-radius:22px;background:#fff;box-shadow:0 18px 48px rgba(15,23,42,.1)}h1{margin:0 0 12px;font-size:22px}p{margin:0 0 22px;color:#64748b;line-height:1.7}a{display:block;padding:13px 16px;border-radius:13px;background:#2563eb;color:#fff;text-align:center;text-decoration:none;font-weight:700}</style></head><body><main class="card"><h1>管理员内测</h1><p>当前测试版本仅向已认证管理员开放。请先从企业微信进入正式课表并确认管理员身份，再打开本页面。</p><a href="/LessonSchedule/">返回正式版</a></main></body></html>';
    exit;
}

$session = lessonResolveAuthenticatedUser();
if ($session === null || !lessonIsEnabledAdmin($session['user_id'])) {
    denyTestAccess();
}

$candidate = __DIR__ . '/candidate.html';
if (!is_file($candidate)) {
    http_response_code(503);
    exit('内测版本尚未部署');
}
header('Content-Type: text/html; charset=utf-8');
readfile($candidate);
