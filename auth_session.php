<?php

if (!defined('AUTH_SHARED_SECRET')) {
    define(
        'AUTH_SHARED_SECRET',
        getenv('LESSON_SCHEDULE_AUTH_SECRET') ?: 'LessonSchedule-v0.2-Shared-Secret-Replace-In-Production'
    );
}

if (!defined('AUTH_SESSION_TTL')) {
    define('AUTH_SESSION_TTL', 60 * 60 * 24 * 30);
}

function normalizeUserId($userId)
{
    $value = trim((string) $userId);
    if ($value === '') {
        return '';
    }

    return preg_match('/^[a-zA-Z0-9_\-]{5,20}$/', $value) ? $value : '';
}

function normalizeAuthExpire($expiresAt)
{
    $value = trim((string) $expiresAt);
    if ($value === '' || !ctype_digit($value)) {
        return 0;
    }

    return (int) $value;
}

function buildAuthSignature($userId, $expiresAt)
{
    return hash_hmac('sha256', normalizeUserId($userId) . '|' . normalizeAuthExpire($expiresAt), AUTH_SHARED_SECRET);
}

function issueAuthSession($userId, $ttlSeconds = AUTH_SESSION_TTL)
{
    $normalizedUserId = normalizeUserId($userId);
    $expiresAt = time() + max(60, (int) $ttlSeconds);

    return [
        'user_id' => $normalizedUserId,
        'auth_exp' => $expiresAt,
        'auth_sig' => buildAuthSignature($normalizedUserId, $expiresAt)
    ];
}

function isAuthSignatureValid($userId, $expiresAt, $signature)
{
    $normalizedUserId = normalizeUserId($userId);
    $normalizedExpire = normalizeAuthExpire($expiresAt);
    $normalizedSignature = trim((string) $signature);

    if ($normalizedUserId === '' || $normalizedExpire <= time() || $normalizedSignature === '') {
        return false;
    }

    $expected = buildAuthSignature($normalizedUserId, $normalizedExpire);
    return hash_equals($expected, $normalizedSignature);
}

function buildMaskedUserLabel($userId)
{
    $value = normalizeUserId($userId);
    $length = strlen($value);
    if ($length <= 4) {
        return $value;
    }

    return substr($value, 0, 2) . str_repeat('*', max(2, $length - 4)) . substr($value, -2);
}
