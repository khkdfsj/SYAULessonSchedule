<?php

declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/admin-access.php';

function lessonReadFormalVersion(): string
{
    $sourcePath = __DIR__ . '/curlGetSyauInfo.php';
    $source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
    if (!is_string($source)) {
        return '';
    }
    return preg_match("/define\\('APP_VERSION',\\s*'([^']+)'\\)/", $source, $matches)
        ? trim((string) $matches[1])
        : '';
}

function lessonHasNewerCandidate(): bool
{
    $candidatePath = '/www/server/nginx/html/LessonSchedule-test/candidate-version.txt';
    if (!is_file($candidatePath) || !is_readable($candidatePath)) {
        return false;
    }
    $candidateVersion = trim((string) file_get_contents($candidatePath));
    $formalVersion = lessonReadFormalVersion();
    if (!preg_match('/^\\d+\\.\\d+\\.\\d+$/', $candidateVersion)
        || !preg_match('/^\\d+\\.\\d+\\.\\d+$/', $formalVersion)) {
        return false;
    }
    return version_compare($candidateVersion, $formalVersion, '>');
}

if (lessonHasNewerCandidate()) {
    $session = lessonResolveAuthenticatedUser();
    if ($session !== null && lessonIsEnabledAdmin($session['user_id'])) {
        $query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
        header('Location: /LessonSchedule-test/' . ($query !== '' ? '?' . $query : ''), true, 302);
        exit;
    }
}

$formalIndex = __DIR__ . '/index.html';
if (!is_file($formalIndex)) {
    http_response_code(503);
    exit('课表页面暂不可用');
}
header('Content-Type: text/html; charset=utf-8');
readfile($formalIndex);
