<?php

declare(strict_types=1);

function lessonReadExactCookie(string $name): string
{
    $cookieHeader = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    foreach (explode(';', $cookieHeader) as $segment) {
        $parts = explode('=', trim($segment), 2);
        if (count($parts) === 2 && rawurldecode($parts[0]) === $name) {
            return rawurldecode($parts[1]);
        }
    }
    return '';
}

function lessonNormalizeAuthPayload($payload): ?array
{
    if (is_array($payload) && ($payload['type'] ?? '') === 'object' && is_array($payload['data'] ?? null)) {
        $payload = $payload['data'];
    }
    if (!is_array($payload)) {
        return null;
    }

    $userId = normalizeUserId($payload['UserID'] ?? $payload['userId'] ?? $payload['user_id'] ?? '');
    $authExp = normalizeAuthExpire($payload['authExp'] ?? $payload['auth_exp'] ?? '');
    $authSig = trim((string) ($payload['authSig'] ?? $payload['auth_sig'] ?? ''));
    if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
        return null;
    }

    return [
        'user_id' => $userId,
        'auth_exp' => $authExp,
        'auth_sig' => $authSig,
    ];
}

function lessonResolveAuthenticatedUser(): ?array
{
    $routeSession = lessonNormalizeAuthPayload($_GET);
    if ($routeSession !== null) {
        return $routeSession;
    }

    $rawSession = lessonReadExactCookie('LessonSchedule.AuthSession');
    if ($rawSession === '') {
        return null;
    }
    return lessonNormalizeAuthPayload(json_decode($rawSession, true));
}

function lessonLoadTestReleaseConfig(): ?array
{
    $path = '/www/server/nginx/html/LessonSchedule/.test-release-config.php';
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $config = require $path;
    return is_array($config) ? $config : null;
}

function lessonIsEnabledAdmin(string $userId): bool
{
    $config = lessonLoadTestReleaseConfig();
    if ($config === null) {
        return false;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();
    if (!$conn) {
        return false;
    }
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $connected = @$conn->real_connect(
        (string) ($config['db_host'] ?? '127.0.0.1'),
        (string) ($config['db_user'] ?? ''),
        (string) ($config['db_pass'] ?? ''),
        (string) ($config['db_name'] ?? ''),
        (int) ($config['db_port'] ?? 3306)
    );
    if (!$connected) {
        $conn->close();
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
    if (!$stmt) {
        $conn->close();
        return false;
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isAdmin = (bool) ($result && $result->fetch_row());
    $stmt->close();
    $conn->close();
    return $isAdmin;
}
