<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'Off');
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/feedback_notification.php';

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');
define('ADMIN_DEBUG_SESSION_TTL', 60 * 60 * 2);

function sendJson($code, $msg, $data = [], $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
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

function dbConnect($strict = true)
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();
    if (!$conn) {
        if ($strict) {
            sendJson(500, '数据库连接失败', [], 500);
        }
        return null;
    }

    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
        if ($strict) {
            sendJson(500, '数据库连接失败', [], 500);
        }
        return null;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function logFeedbackError($conn, $message, $userId = null)
{
    if (!$conn instanceof mysqli) {
        return;
    }

    $stmt = $conn->prepare('INSERT INTO error_logs (message, user_id) VALUES (?, ?)');
    if (!$stmt) {
        return;
    }

    $safeMessage = trim((string) $message);
    $safeUserId = $userId !== null ? normalizeUserId($userId) : null;
    $stmt->bind_param('ss', $safeMessage, $safeUserId);
    $stmt->execute();
    $stmt->close();
}

function trimText($value, $maxLength = 5000)
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

function readInt($value, $default = 0)
{
    if ($value === null || $value === '') {
        return $default;
    }
    return (int) $value;
}

function normalizeAdminDisplayName($value)
{
    $name = preg_replace('/\s+/u', ' ', trim((string) $value));
    return trimText($name, 30);
}

function textLength($value)
{
    $text = (string) $value;
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function hasCompletedFeedbackTemplate($type, $content)
{
    $sections = $type === 'issue'
        ? ['异常现象', '发生时间', '操作过程', '已尝试方法', '设备环境']
        : ['目前的问题', '希望如何改进', '预期效果'];

    foreach ($sections as $section) {
        $pattern = '/【' . preg_quote($section, '/') . '】\s*(.*?)(?=【|$)/us';
        if (!preg_match($pattern, (string) $content, $matches)) {
            return false;
        }
        $answer = trim((string) ($matches[1] ?? ''));
        if ($answer === '' || strpos($answer, '[请填写') !== false || textLength($answer) < 4) {
            return false;
        }
    }
    return true;
}

function getAdminProfile($conn, $userId)
{
    if (!$conn instanceof mysqli) {
        return null;
    }

    $normalizedUserId = normalizeUserId($userId);
    if ($normalizedUserId === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT user_id, display_name, is_super_admin, notification_enabled
         FROM feedback_admins
         WHERE user_id = ? AND enabled = 1
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $normalizedUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'user_id' => $row['user_id'],
        'display_name' => (string) ($row['display_name'] ?? ''),
        'is_super_admin' => (int) ($row['is_super_admin'] ?? 0) === 1,
        'notification_enabled' => (int) ($row['notification_enabled'] ?? 1) === 1
    ];
}

function isAdminUser($conn, $userId)
{
    return getAdminProfile($conn, $userId) !== null;
}

function logAdminDebugStart($conn, $adminUserId, $targetUserId)
{
    if (!$conn instanceof mysqli) {
        return false;
    }

    $created = $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_debug_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id VARCHAR(20) NOT NULL,
            target_user_id VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_debug_created_at (created_at),
            KEY idx_admin_debug_target (target_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    if (!$created) {
        return false;
    }

    $stmt = $conn->prepare(
        'INSERT INTO admin_debug_audit (admin_user_id, target_user_id, created_at)
         VALUES (?, ?, NOW())'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $adminUserId, $targetUserId);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function buildSessionContext($conn, $input)
{
    $userId = normalizeUserId($input['user_id'] ?? $input['UserID'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? '');
    $authSig = trim((string) ($input['auth_sig'] ?? ''));
    $authenticated = isAuthSignatureValid($userId, $authExp, $authSig);
    $adminProfile = ($authenticated && $conn instanceof mysqli) ? getAdminProfile($conn, $userId) : null;

    return [
        'user_id' => $userId,
        'auth_exp' => $authExp,
        'auth_sig' => $authSig,
        'authenticated' => $authenticated,
        'is_admin' => $adminProfile !== null,
        'is_super_admin' => $adminProfile ? $adminProfile['is_super_admin'] : false,
        'admin_display_name' => $adminProfile ? $adminProfile['display_name'] : '',
        'admin_notification_enabled' => $adminProfile ? $adminProfile['notification_enabled'] : false
    ];
}

function requireAuth($context)
{
    if (!($context['authenticated'] ?? false)) {
        sendJson(401, '请在白天重新认证后再执行此操作', ['requiresAuth' => true], 401);
    }
}

function requireAdmin($context)
{
    requireAuth($context);
    if (!($context['is_admin'] ?? false)) {
        sendJson(403, '无管理员权限', [], 403);
    }
}

function requireSuperAdmin($context)
{
    requireAdmin($context);
    if (!($context['is_super_admin'] ?? false)) {
        sendJson(403, '仅超级管理员可管理管理员成员', [], 403);
    }
}

function countEnabledSuperAdmins($conn)
{
    $result = $conn->query('SELECT COUNT(*) AS total FROM feedback_admins WHERE enabled = 1 AND is_super_admin = 1');
    $row = $result ? $result->fetch_assoc() : null;
    return (int) ($row['total'] ?? 0);
}

function getAdminMemberList($conn)
{
    $result = $conn->query(
        'SELECT user_id, display_name, is_super_admin, notification_enabled, created_at, updated_at
         FROM feedback_admins
         WHERE enabled = 1
         ORDER BY is_super_admin DESC, created_at ASC, user_id ASC'
    );
    if (!$result) {
        sendJson(500, '管理员列表获取失败', [], 500);
    }

    $list = [];
    while ($row = $result->fetch_assoc()) {
        $list[] = [
            'user_id' => $row['user_id'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'is_super_admin' => (int) ($row['is_super_admin'] ?? 0) === 1,
            'notification_enabled' => (int) ($row['notification_enabled'] ?? 1) === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    return $list;
}

function getThreadById($conn, $threadId)
{
    $stmt = $conn->prepare(
        'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
         FROM feedback_threads
         WHERE id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    $thread = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $thread ?: null;
}

function canViewThread($thread, $context)
{
    if (!$thread) {
        return false;
    }

    if ($thread['visibility'] === 'public' && $thread['type'] === 'suggestion') {
        return true;
    }

    if (!($context['authenticated'] ?? false)) {
        return false;
    }

    if (($context['is_admin'] ?? false) || $thread['user_id'] === ($context['user_id'] ?? '')) {
        return true;
    }

    return false;
}

function canReplyThread($thread, $context)
{
    if (!$thread || !($context['authenticated'] ?? false)) {
        return false;
    }

    if ($thread['type'] === 'suggestion' && $thread['visibility'] === 'public') {
        return true;
    }

    return ($context['is_admin'] ?? false) || $thread['user_id'] === ($context['user_id'] ?? '');
}

function getLikedByMe($conn, $threadId, $userId)
{
    $normalizedUserId = normalizeUserId($userId);
    if ($normalizedUserId === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM feedback_likes WHERE thread_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $threadId, $normalizedUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $liked = $result && $result->num_rows > 0;
    $stmt->close();

    return $liked;
}

function getAuthorLabel($threadOrReply, $context, $threadVisibility = 'public')
{
    $role = $threadOrReply['role'] ?? 'user';
    $userId = $threadOrReply['user_id'] ?? '';

    if ($role === 'admin') {
        $adminDisplayName = normalizeAdminDisplayName($threadOrReply['admin_display_name'] ?? '');
        return $adminDisplayName === '' ? '管理员' : ('管理员•' . $adminDisplayName);
    }

    if (($context['authenticated'] ?? false) && ($context['user_id'] ?? '') === $userId) {
        return '我';
    }

    if (($context['is_admin'] ?? false) && $threadVisibility !== 'public') {
        return $userId;
    }

    return buildMaskedUserLabel($userId);
}

function getPinnedReplySummary($conn, $pinnedReplyId, $context, $threadVisibility)
{
    if (!$pinnedReplyId) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT r.id, r.thread_id, r.user_id, r.role, r.content, r.is_pinned, r.created_at,
                COALESCE(a.display_name, \'\') AS admin_display_name
         FROM feedback_replies r
         LEFT JOIN feedback_admins a ON a.user_id = r.user_id
         WHERE r.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $pinnedReplyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'content' => $row['content'],
        'created_at' => $row['created_at'],
        'display_name' => getAuthorLabel($row, $context, $threadVisibility)
    ];
}

function formatThreadRow($conn, $row, $context)
{
    $visibility = $row['visibility'];

    return [
        'id' => (int) $row['id'],
        'type' => $row['type'],
        'user_id' => $row['user_id'],
        'title' => $row['title'],
        'content' => $row['content'],
        'template_key' => $row['template_key'],
        'status' => $row['status'],
        'visibility' => $visibility,
        'reply_count' => (int) $row['reply_count'],
        'like_count' => (int) $row['like_count'],
        'pinned_reply_id' => $row['pinned_reply_id'] ? (int) $row['pinned_reply_id'] : null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'last_reply_at' => $row['last_reply_at'],
        'author_display_name' => getAuthorLabel($row, $context, $visibility),
        'liked_by_me' => ($row['type'] === 'suggestion' && ($context['authenticated'] ?? false))
            ? getLikedByMe($conn, (int) $row['id'], $context['user_id'])
            : false,
        'pinned_reply' => getPinnedReplySummary($conn, (int) $row['pinned_reply_id'], $context, $visibility)
    ];
}

function fetchThreadList($conn, $scope, $context, $page, $pageSize, $filters = [])
{
    $offset = max(0, ($page - 1) * $pageSize);
    $status = trim((string) ($filters['status'] ?? ''));
    if (!in_array($status, ['', 'open', 'replied', 'closed'], true)) {
        $status = '';
    }
    $defaultSortBy = $scope === 'suggestions' ? 'last_reply_at' : 'created_at';
    $sortBy = trim((string) ($filters['sort_by'] ?? ''));
    if ($sortBy === '') {
        $sortBy = $defaultSortBy;
    }
    if (!in_array($sortBy, ['created_at', 'updated_at', 'last_reply_at'], true)) {
        $sortBy = 'created_at';
    }
    $sortOrder = strtolower(trim((string) ($filters['sort_order'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
    $keyword = trimText($filters['keyword'] ?? '', 80);
    $keywordLike = '%' . $keyword . '%';
    $orderSql = $sortBy . ' ' . $sortOrder . ', id ' . $sortOrder;

    if ($scope === 'my_issues') {
        requireAuth($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\' AND user_id = ?
               AND (? = \'\' OR status = ?)
               AND (? = \'\' OR title LIKE ? OR content LIKE ? OR user_id LIKE ? OR CAST(id AS CHAR) = ?)
             ORDER BY ' . $orderSql . '
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ssssssssii', $context['user_id'], $status, $status, $keyword, $keywordLike, $keywordLike, $keywordLike, $keyword, $pageSize, $offset);
    } elseif ($scope === 'admin_pending_issues') {
        requireAdmin($context);
        $status = 'open';
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\' AND status = \'open\'
               AND (? = \'\' OR title LIKE ? OR content LIKE ? OR user_id LIKE ? OR CAST(id AS CHAR) = ?)
             ORDER BY ' . $orderSql . '
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sssssii', $keyword, $keywordLike, $keywordLike, $keywordLike, $keyword, $pageSize, $offset);
    } elseif ($scope === 'admin_all_issues') {
        requireAdmin($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\'
               AND (? = \'\' OR status = ?)
               AND (? = \'\' OR title LIKE ? OR content LIKE ? OR user_id LIKE ? OR CAST(id AS CHAR) = ?)
             ORDER BY ' . $orderSql . '
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sssssssii', $status, $status, $keyword, $keywordLike, $keywordLike, $keywordLike, $keyword, $pageSize, $offset);
    } elseif ($scope === 'admin_suggestions') {
        requireAdmin($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'suggestion\' AND visibility = \'public\'
               AND (? = \'\' OR status = ?)
               AND (? = \'\' OR title LIKE ? OR content LIKE ? OR user_id LIKE ? OR CAST(id AS CHAR) = ?)
             ORDER BY ' . $orderSql . '
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sssssssii', $status, $status, $keyword, $keywordLike, $keywordLike, $keywordLike, $keyword, $pageSize, $offset);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'suggestion\' AND visibility = \'public\'
             ORDER BY last_reply_at DESC, created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $pageSize, $offset);
    }

    if (!$stmt) {
        sendJson(500, '查询反馈列表失败', [], 500);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $threads = [];
    while ($row = $result->fetch_assoc()) {
        $threads[] = formatThreadRow($conn, $row, $context);
    }
    $stmt->close();

    return $threads;
}

function fetchReplyList($conn, $thread, $context)
{
    $threadId = (int) $thread['id'];
    $stmt = $conn->prepare(
        'SELECT r.id, r.thread_id, r.user_id, r.role, r.content, r.is_pinned, r.created_at,
                COALESCE(a.display_name, \'\') AS admin_display_name
         FROM feedback_replies r
         LEFT JOIN feedback_admins a ON a.user_id = r.user_id
         WHERE r.thread_id = ?
         ORDER BY r.is_pinned DESC, r.created_at ASC, r.id ASC'
    );
    if (!$stmt) {
        sendJson(500, '查询回复失败', [], 500);
    }

    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    $replies = [];
    while ($row = $result->fetch_assoc()) {
        $replies[] = [
            'id' => (int) $row['id'],
            'thread_id' => (int) $row['thread_id'],
            'user_id' => $row['user_id'],
            'role' => $row['role'],
            'content' => $row['content'],
            'is_pinned' => (int) $row['is_pinned'] === 1,
            'created_at' => $row['created_at'],
            'display_name' => getAuthorLabel($row, $context, $thread['visibility']),
            'can_delete' => ($context['is_admin'] ?? false)
                && $row['role'] === 'admin'
                && $row['user_id'] === ($context['user_id'] ?? '')
        ];
    }
    $stmt->close();

    return $replies;
}

function refreshThreadCounters($conn, $threadId)
{
    $replyStmt = $conn->prepare('SELECT COUNT(*) AS total, MAX(created_at) AS last_reply_at FROM feedback_replies WHERE thread_id = ?');
    $replyStmt->bind_param('i', $threadId);
    $replyStmt->execute();
    $replyRow = $replyStmt->get_result()->fetch_assoc();
    $replyCount = (int) ($replyRow['total'] ?? 0);
    $lastReplyAt = (string) ($replyRow['last_reply_at'] ?? '');
    $replyStmt->close();

    $likeStmt = $conn->prepare('SELECT COUNT(*) AS total FROM feedback_likes WHERE thread_id = ?');
    $likeStmt->bind_param('i', $threadId);
    $likeStmt->execute();
    $likeCount = (int) $likeStmt->get_result()->fetch_assoc()['total'];
    $likeStmt->close();

    $updateStmt = $conn->prepare(
        'UPDATE feedback_threads
         SET reply_count = ?, like_count = ?, updated_at = NOW(), last_reply_at = COALESCE(NULLIF(?, \'\'), created_at)
         WHERE id = ?'
    );
    $updateStmt->bind_param('iisi', $replyCount, $likeCount, $lastReplyAt, $threadId);
    $updateStmt->execute();
    $updateStmt->close();

    return [
        'reply_count' => $replyCount,
        'like_count' => $likeCount
    ];
}

function setPinnedReply($conn, $threadId, $replyId)
{
    $clearStmt = $conn->prepare('UPDATE feedback_replies SET is_pinned = 0 WHERE thread_id = ?');
    $clearStmt->bind_param('i', $threadId);
    $clearStmt->execute();
    $clearStmt->close();

    if ($replyId > 0) {
        $pinStmt = $conn->prepare('UPDATE feedback_replies SET is_pinned = 1 WHERE id = ? AND thread_id = ?');
        $pinStmt->bind_param('ii', $replyId, $threadId);
        $pinStmt->execute();
        $pinStmt->close();

        $threadStmt = $conn->prepare('UPDATE feedback_threads SET pinned_reply_id = ?, updated_at = NOW() WHERE id = ?');
        $threadStmt->bind_param('ii', $replyId, $threadId);
        $threadStmt->execute();
        $threadStmt->close();
        return;
    }

    $threadStmt = $conn->prepare('UPDATE feedback_threads SET pinned_reply_id = NULL, updated_at = NOW() WHERE id = ?');
    $threadStmt->bind_param('i', $threadId);
    $threadStmt->execute();
    $threadStmt->close();
}

$conn = null;
$context = [
    'user_id' => '',
    'auth_exp' => 0,
    'auth_sig' => '',
    'authenticated' => false,
    'is_admin' => false,
    'is_super_admin' => false,
    'admin_display_name' => '',
    'admin_notification_enabled' => false
];

try {
    $input = readJsonInput();
    $action = trim((string) ($input['action'] ?? $_GET['action'] ?? ''));

    if ($action === 'session') {
        $sessionConn = dbConnect(false);
        $context = buildSessionContext($sessionConn, $input);
        if ($sessionConn instanceof mysqli) {
            $sessionConn->close();
        }
        sendJson(200, '会话状态获取成功', [
            'authenticated' => $context['authenticated'],
            'is_admin' => $context['is_admin'],
            'is_super_admin' => $context['is_super_admin'],
            'admin_display_name' => $context['admin_display_name'],
            'admin_notification_enabled' => $context['admin_notification_enabled'],
            'user_id' => $context['user_id'],
            'masked_user_id' => buildMaskedUserLabel($context['user_id']),
            'auth_exp' => $context['auth_exp']
        ]);
    }

    $conn = dbConnect();
    $context = buildSessionContext($conn, $input);

    if ($action === 'admin_member_list') {
        requireAdmin($context);
        sendJson(200, '管理员列表获取成功', [
            'list' => getAdminMemberList($conn),
            'current_user_id' => $context['user_id'],
            'is_super_admin' => $context['is_super_admin'],
            'admin_display_name' => $context['admin_display_name'],
            'admin_notification_enabled' => $context['admin_notification_enabled']
        ]);
    }

    if ($action === 'admin_profile_update') {
        requireAdmin($context);
        $displayName = normalizeAdminDisplayName($input['display_name'] ?? '');
        $stmt = $conn->prepare(
            'UPDATE feedback_admins
             SET display_name = ?, updated_at = NOW()
             WHERE user_id = ? AND enabled = 1'
        );
        if (!$stmt) {
            sendJson(500, '管理员名称保存失败', [], 500);
        }
        $stmt->bind_param('ss', $displayName, $context['user_id']);
        $stmt->execute();
        $stmt->close();
        sendJson(200, '管理员名称已保存', [
            'user_id' => $context['user_id'],
            'display_name' => $displayName,
            'is_super_admin' => $context['is_super_admin']
        ]);
    }

    if ($action === 'admin_notification_update') {
        requireAdmin($context);
        $notificationEnabled = !empty($input['notification_enabled']) ? 1 : 0;
        $stmt = $conn->prepare(
            'UPDATE feedback_admins
             SET notification_enabled = ?, updated_at = NOW()
             WHERE user_id = ? AND enabled = 1'
        );
        if (!$stmt) {
            sendJson(500, '消息提醒设置保存失败', [], 500);
        }
        $stmt->bind_param('is', $notificationEnabled, $context['user_id']);
        $stmt->execute();
        $stmt->close();
        sendJson(200, '消息提醒设置已保存', [
            'user_id' => $context['user_id'],
            'notification_enabled' => $notificationEnabled === 1
        ]);
    }

    if ($action === 'admin_member_add') {
        requireSuperAdmin($context);
        $targetUserId = normalizeUserId($input['target_user_id'] ?? '');
        if (!preg_match('/^\d{8,12}$/', $targetUserId)) {
            sendJson(400, '请输入正确的学号', [], 400);
        }
        $displayName = normalizeAdminDisplayName($input['display_name'] ?? '');
        $isSuperAdmin = !empty($input['is_super_admin']) ? 1 : 0;
        $stmt = $conn->prepare(
            'INSERT INTO feedback_admins
             (user_id, display_name, is_super_admin, notification_enabled, enabled, created_at, updated_at)
             VALUES (?, ?, ?, 1, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 display_name = VALUES(display_name),
                 is_super_admin = VALUES(is_super_admin),
                 notification_enabled = 1,
                 enabled = 1,
                 updated_at = NOW()'
        );
        if (!$stmt) {
            sendJson(500, '新增管理员失败', [], 500);
        }
        $stmt->bind_param('ssi', $targetUserId, $displayName, $isSuperAdmin);
        $stmt->execute();
        $stmt->close();
        sendJson(200, '管理员已添加', [
            'user_id' => $targetUserId,
            'display_name' => $displayName,
            'is_super_admin' => $isSuperAdmin === 1
        ]);
    }

    if ($action === 'admin_member_update') {
        requireSuperAdmin($context);
        $targetUserId = normalizeUserId($input['target_user_id'] ?? '');
        $profile = getAdminProfile($conn, $targetUserId);
        if (!$profile) {
            sendJson(404, '管理员不存在', [], 404);
        }

        $nextIsSuperAdmin = !empty($input['is_super_admin']);
        if ($profile['is_super_admin'] && !$nextIsSuperAdmin && countEnabledSuperAdmins($conn) <= 1) {
            sendJson(400, '至少需要保留一名超级管理员', [], 400);
        }

        $isSuperAdmin = $nextIsSuperAdmin ? 1 : 0;
        $stmt = $conn->prepare(
            'UPDATE feedback_admins
             SET is_super_admin = ?, updated_at = NOW()
             WHERE user_id = ? AND enabled = 1'
        );
        if (!$stmt) {
            sendJson(500, '管理员权限更新失败', [], 500);
        }
        $stmt->bind_param('is', $isSuperAdmin, $targetUserId);
        $stmt->execute();
        $stmt->close();
        sendJson(200, '管理员权限已更新', [
            'user_id' => $targetUserId,
            'is_super_admin' => $nextIsSuperAdmin
        ]);
    }

    if ($action === 'admin_member_remove') {
        requireSuperAdmin($context);
        $targetUserId = normalizeUserId($input['target_user_id'] ?? '');
        $profile = getAdminProfile($conn, $targetUserId);
        if (!$profile) {
            sendJson(404, '管理员不存在', [], 404);
        }
        if ($targetUserId === $context['user_id']) {
            sendJson(400, '不能移除当前登录的管理员账号', [], 400);
        }
        if ($profile['is_super_admin'] && countEnabledSuperAdmins($conn) <= 1) {
            sendJson(400, '至少需要保留一名超级管理员', [], 400);
        }

        $stmt = $conn->prepare(
            'UPDATE feedback_admins
             SET enabled = 0, is_super_admin = 0, updated_at = NOW()
             WHERE user_id = ? AND enabled = 1'
        );
        if (!$stmt) {
            sendJson(500, '移除管理员失败', [], 500);
        }
        $stmt->bind_param('s', $targetUserId);
        $stmt->execute();
        $stmt->close();
        sendJson(200, '管理员已移除', ['user_id' => $targetUserId]);
    }

    if ($action === 'admin_debug_start') {
        requireAdmin($context);

        $targetUserId = normalizeUserId($input['target_user_id'] ?? '');
        if ($targetUserId === '' || !preg_match('/^\d{8,12}$/', $targetUserId)) {
            sendJson(400, '请输入正确的学号', [], 400);
        }
        if ($targetUserId === $context['user_id']) {
            sendJson(400, '当前已经是该身份', [], 400);
        }

        $debugSession = issueAuthSession($targetUserId, ADMIN_DEBUG_SESSION_TTL);
        if (!logAdminDebugStart($conn, $context['user_id'], $targetUserId)) {
            sendJson(500, '调试身份记录失败，请稍后重试', [], 500);
        }

        sendJson(200, '调试身份已准备', [
            'admin_user_id' => $context['user_id'],
            'target_user_id' => $targetUserId,
            'session' => $debugSession,
            'expires_at' => $debugSession['auth_exp']
        ]);
    }

    if ($action === 'thread_list') {
        $scope = trim((string) ($input['scope'] ?? 'suggestions'));
        $page = max(1, readInt($input['page'] ?? 1, 1));
        $pageSize = min(50, max(1, readInt($input['page_size'] ?? 20, 20)));
        $filters = [
            'status' => $input['status'] ?? '',
            'sort_by' => $input['sort_by'] ?? '',
            'sort_order' => $input['sort_order'] ?? 'desc',
            'keyword' => $input['keyword'] ?? ''
        ];
        $threads = fetchThreadList($conn, $scope, $context, $page, $pageSize, $filters);

        sendJson(200, '反馈列表获取成功', [
            'scope' => $scope,
            'page' => $page,
            'page_size' => $pageSize,
            'list' => $threads
        ]);
    }

    if ($action === 'thread_detail') {
        $threadId = readInt($input['thread_id'] ?? 0, 0);
        if ($threadId <= 0) {
            sendJson(400, '缺少 thread_id', [], 400);
        }

        $thread = getThreadById($conn, $threadId);
        if (!$thread) {
            sendJson(404, '反馈不存在', [], 404);
        }

        if (!canViewThread($thread, $context)) {
            sendJson(403, '无权查看该反馈', [], 403);
        }

        $replies = fetchReplyList($conn, $thread, $context);
        sendJson(200, '反馈详情获取成功', [
            'thread' => formatThreadRow($conn, $thread, $context),
            'replies' => $replies,
            'permissions' => [
                'can_reply' => canReplyThread($thread, $context),
                'can_admin' => $context['is_admin'],
                'can_view_private' => canViewThread($thread, $context)
            ]
        ]);
    }

    if ($action === 'thread_create') {
        requireAuth($context);

        $type = trim((string) ($input['type'] ?? ''));
        if (!in_array($type, ['issue', 'suggestion'], true)) {
            sendJson(400, '反馈类型不正确', [], 400);
        }

        $title = trimText($input['title'] ?? '', 120);
        $content = trimText($input['content'] ?? '', 4000);
        $templateKey = trimText($input['template_key'] ?? '', 64);
        if ($title === '' || $content === '') {
            sendJson(400, '标题和内容不能为空', [], 400);
        }
        if (textLength($title) < 6) {
            sendJson(400, '请用不少于6个字说明具体问题', [], 400);
        }
        if ($templateKey !== $type . '_default' || !hasCompletedFeedbackTemplate($type, $content)) {
            sendJson(400, '请按填写模板补充完整信息后再提交', [], 400);
        }

        $visibility = $type === 'issue' ? 'private' : 'public';
        $status = 'open';

        $stmt = $conn->prepare(
            'INSERT INTO feedback_threads
             (type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, NOW(), NOW(), NOW())'
        );
        if (!$stmt) {
            sendJson(500, '创建反馈失败', [], 500);
        }

        $stmt->bind_param(
            'sssssss',
            $type,
            $context['user_id'],
            $title,
            $content,
            $templateKey,
            $status,
            $visibility
        );
        $stmt->execute();
        $threadId = (int) $conn->insert_id;
        $stmt->close();

        sendFeedbackAdminNotification(
            $conn,
            'thread_created',
            $threadId,
            0,
            $context['user_id'],
            $title,
            $content
        );

        sendJson(200, '反馈提交成功', [
            'thread_id' => $threadId
        ]);
    }

    if ($action === 'reply_create') {
        requireAuth($context);

        $threadId = readInt($input['thread_id'] ?? 0, 0);
        $content = trimText($input['content'] ?? '', 3000);
        $pinReply = !empty($input['pin']) && $context['is_admin'];

        if ($threadId <= 0 || $content === '') {
            sendJson(400, '缺少必要参数', [], 400);
        }

        $thread = getThreadById($conn, $threadId);
        if (!$thread) {
            sendJson(404, '反馈不存在', [], 404);
        }

        if (!canReplyThread($thread, $context)) {
            sendJson(403, '无权回复该反馈', [], 403);
        }

        $role = $context['is_admin'] ? 'admin' : 'user';
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare(
                'INSERT INTO feedback_replies (thread_id, user_id, role, content, is_pinned, created_at)
                 VALUES (?, ?, ?, ?, 0, NOW())'
            );
            $stmt->bind_param('isss', $threadId, $context['user_id'], $role, $content);
            $stmt->execute();
            $replyId = (int) $conn->insert_id;
            $stmt->close();

            if ($pinReply) {
                setPinnedReply($conn, $threadId, $replyId);
            }

            $nextStatus = $thread['status'];
            if ($thread['type'] === 'issue' && $context['is_admin']) {
                $nextStatus = 'replied';
            }

            $updateThreadStmt = $conn->prepare(
                'UPDATE feedback_threads
                 SET reply_count = reply_count + 1, status = ?, updated_at = NOW(), last_reply_at = NOW()
                 WHERE id = ?'
            );
            $updateThreadStmt->bind_param('si', $nextStatus, $threadId);
            $updateThreadStmt->execute();
            $updateThreadStmt->close();

            refreshThreadCounters($conn, $threadId);
            $conn->commit();

            if ($context['is_admin']) {
                sendFeedbackUserNotification(
                    $conn,
                    $threadId,
                    $replyId,
                    $context['user_id'],
                    $thread['user_id'],
                    $thread['title'],
                    $content
                );
            } else {
                sendFeedbackAdminNotification(
                    $conn,
                    'user_replied',
                    $threadId,
                    $replyId,
                    $context['user_id'],
                    $thread['title'],
                    $content
                );
            }

            sendJson(200, '回复成功', [
                'reply_id' => $replyId,
                'thread_id' => $threadId
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'reply_delete') {
        requireAdmin($context);

        $replyId = readInt($input['reply_id'] ?? 0, 0);
        if ($replyId <= 0) {
            sendJson(400, '缺少 reply_id', [], 400);
        }

        $stmt = $conn->prepare(
            'SELECT r.id, r.thread_id, r.user_id, r.role, r.is_pinned, t.type, t.status
             FROM feedback_replies r
             INNER JOIN feedback_threads t ON t.id = r.thread_id
             WHERE r.id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $replyId);
        $stmt->execute();
        $reply = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reply) {
            sendJson(404, '回复不存在', [], 404);
        }
        if ($reply['role'] !== 'admin' || $reply['user_id'] !== $context['user_id']) {
            sendJson(403, '只能删除自己发送的管理员回复', [], 403);
        }

        $threadId = (int) $reply['thread_id'];
        $conn->begin_transaction();
        try {
            if ((int) $reply['is_pinned'] === 1) {
                setPinnedReply($conn, $threadId, 0);
            }

            $deleteStmt = $conn->prepare('DELETE FROM feedback_replies WHERE id = ? AND user_id = ? AND role = \'admin\'');
            $deleteStmt->bind_param('is', $replyId, $context['user_id']);
            $deleteStmt->execute();
            if ($deleteStmt->affected_rows !== 1) {
                $deleteStmt->close();
                throw new RuntimeException('回复删除失败');
            }
            $deleteStmt->close();

            if ($reply['type'] === 'issue' && $reply['status'] === 'replied') {
                $adminCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM feedback_replies WHERE thread_id = ? AND role = \'admin\'');
                $adminCountStmt->bind_param('i', $threadId);
                $adminCountStmt->execute();
                $adminReplyCount = (int) ($adminCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $adminCountStmt->close();
                if ($adminReplyCount === 0) {
                    $openStmt = $conn->prepare('UPDATE feedback_threads SET status = \'open\' WHERE id = ?');
                    $openStmt->bind_param('i', $threadId);
                    $openStmt->execute();
                    $openStmt->close();
                }
            }

            $counters = refreshThreadCounters($conn, $threadId);
            $conn->commit();

            sendJson(200, '回复已删除', [
                'reply_id' => $replyId,
                'thread_id' => $threadId,
                'reply_count' => $counters['reply_count']
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'like_toggle') {
        requireAuth($context);

        $threadId = readInt($input['thread_id'] ?? 0, 0);
        if ($threadId <= 0) {
            sendJson(400, '缺少 thread_id', [], 400);
        }

        $thread = getThreadById($conn, $threadId);
        if (!$thread || $thread['type'] !== 'suggestion' || $thread['visibility'] !== 'public') {
            sendJson(404, '建议不存在', [], 404);
        }

        $conn->begin_transaction();

        try {
            $existsStmt = $conn->prepare('SELECT id FROM feedback_likes WHERE thread_id = ? AND user_id = ? LIMIT 1');
            $existsStmt->bind_param('is', $threadId, $context['user_id']);
            $existsStmt->execute();
            $existsResult = $existsStmt->get_result();
            $existsRow = $existsResult ? $existsResult->fetch_assoc() : null;
            $existsStmt->close();

            $liked = false;
            if ($existsRow) {
                $deleteStmt = $conn->prepare('DELETE FROM feedback_likes WHERE id = ?');
                $deleteStmt->bind_param('i', $existsRow['id']);
                $deleteStmt->execute();
                $deleteStmt->close();
            } else {
                $insertStmt = $conn->prepare('INSERT INTO feedback_likes (thread_id, user_id, created_at) VALUES (?, ?, NOW())');
                $insertStmt->bind_param('is', $threadId, $context['user_id']);
                $insertStmt->execute();
                $insertStmt->close();
                $liked = true;
            }

            $counters = refreshThreadCounters($conn, $threadId);
            $conn->commit();

            sendJson(200, '点赞状态已更新', [
                'thread_id' => $threadId,
                'liked' => $liked,
                'like_count' => $counters['like_count']
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'admin_update') {
        requireAdmin($context);

        $threadId = readInt($input['thread_id'] ?? 0, 0);
        if ($threadId <= 0) {
            sendJson(400, '缺少 thread_id', [], 400);
        }

        $thread = getThreadById($conn, $threadId);
        if (!$thread) {
            sendJson(404, '反馈不存在', [], 404);
        }

        $status = trim((string) ($input['status'] ?? ''));
        $hasStatus = $status !== '';
        if ($hasStatus && !in_array($status, ['open', 'replied', 'closed'], true)) {
            sendJson(400, '状态不正确', [], 400);
        }

        $hasPinnedReply = array_key_exists('pinned_reply_id', $input);
        $pinnedReplyId = $hasPinnedReply ? readInt($input['pinned_reply_id'], 0) : 0;

        if ($hasPinnedReply && $pinnedReplyId > 0) {
            $replyStmt = $conn->prepare(
                'SELECT id FROM feedback_replies
                 WHERE id = ? AND thread_id = ? AND role = \'admin\'
                 LIMIT 1'
            );
            $replyStmt->bind_param('ii', $pinnedReplyId, $threadId);
            $replyStmt->execute();
            $replyResult = $replyStmt->get_result();
            $replyFound = $replyResult && $replyResult->num_rows > 0;
            $replyStmt->close();

            if (!$replyFound) {
                sendJson(400, '置顶回复不存在或不是管理员回复', [], 400);
            }
        }

        $conn->begin_transaction();
        try {
            if ($hasPinnedReply) {
                setPinnedReply($conn, $threadId, $pinnedReplyId);
            }

            if ($hasStatus) {
                $statusStmt = $conn->prepare('UPDATE feedback_threads SET status = ?, updated_at = NOW() WHERE id = ?');
                $statusStmt->bind_param('si', $status, $threadId);
                $statusStmt->execute();
                $statusStmt->close();
            }

            refreshThreadCounters($conn, $threadId);
            $conn->commit();

            sendJson(200, '管理员操作成功', [
                'thread_id' => $threadId,
                'status' => $hasStatus ? $status : $thread['status'],
                'pinned_reply_id' => $hasPinnedReply ? ($pinnedReplyId > 0 ? $pinnedReplyId : null) : ($thread['pinned_reply_id'] ? (int) $thread['pinned_reply_id'] : null)
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    sendJson(400, '无效的 action', [], 400);
} catch (Throwable $throwable) {
    logFeedbackError($conn, 'feedbackApi: ' . $throwable->getMessage(), $context['user_id'] ?? null);
    sendJson(500, '反馈系统处理失败', ['debug' => $throwable->getMessage()], 500);
} finally {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
