<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'Off');
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');

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

function isAdminUser($conn, $userId)
{
    if (!$conn instanceof mysqli) {
        return false;
    }

    $normalizedUserId = normalizeUserId($userId);
    if ($normalizedUserId === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $normalizedUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isAdmin = $result && $result->num_rows > 0;
    $stmt->close();

    return $isAdmin;
}

function buildSessionContext($conn, $input)
{
    $userId = normalizeUserId($input['user_id'] ?? $input['UserID'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? '');
    $authSig = trim((string) ($input['auth_sig'] ?? ''));
    $authenticated = isAuthSignatureValid($userId, $authExp, $authSig);

    return [
        'user_id' => $userId,
        'auth_exp' => $authExp,
        'auth_sig' => $authSig,
        'authenticated' => $authenticated,
        'is_admin' => ($authenticated && $conn instanceof mysqli) ? isAdminUser($conn, $userId) : false
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
        return '管理员';
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
        'SELECT id, thread_id, user_id, role, content, is_pinned, created_at
         FROM feedback_replies
         WHERE id = ?
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

function fetchThreadList($conn, $scope, $context, $page, $pageSize)
{
    $offset = max(0, ($page - 1) * $pageSize);

    if ($scope === 'my_issues') {
        requireAuth($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\' AND user_id = ?
             ORDER BY updated_at DESC, created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sii', $context['user_id'], $pageSize, $offset);
    } elseif ($scope === 'admin_pending_issues') {
        requireAdmin($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\' AND status = \'open\'
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $pageSize, $offset);
    } elseif ($scope === 'admin_all_issues') {
        requireAdmin($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'issue\'
             ORDER BY updated_at DESC, created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $pageSize, $offset);
    } elseif ($scope === 'admin_suggestions') {
        requireAdmin($context);
        $stmt = $conn->prepare(
            'SELECT id, type, user_id, title, content, template_key, status, visibility, reply_count, like_count, pinned_reply_id, created_at, updated_at, last_reply_at
             FROM feedback_threads
             WHERE type = \'suggestion\' AND visibility = \'public\'
             ORDER BY last_reply_at DESC, created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $pageSize, $offset);
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
        'SELECT id, thread_id, user_id, role, content, is_pinned, created_at
         FROM feedback_replies
         WHERE thread_id = ?
         ORDER BY is_pinned DESC, created_at ASC, id ASC'
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
            'display_name' => getAuthorLabel($row, $context, $thread['visibility'])
        ];
    }
    $stmt->close();

    return $replies;
}

function refreshThreadCounters($conn, $threadId)
{
    $replyStmt = $conn->prepare('SELECT COUNT(*) AS total FROM feedback_replies WHERE thread_id = ?');
    $replyStmt->bind_param('i', $threadId);
    $replyStmt->execute();
    $replyCount = (int) $replyStmt->get_result()->fetch_assoc()['total'];
    $replyStmt->close();

    $likeStmt = $conn->prepare('SELECT COUNT(*) AS total FROM feedback_likes WHERE thread_id = ?');
    $likeStmt->bind_param('i', $threadId);
    $likeStmt->execute();
    $likeCount = (int) $likeStmt->get_result()->fetch_assoc()['total'];
    $likeStmt->close();

    $updateStmt = $conn->prepare(
        'UPDATE feedback_threads
         SET reply_count = ?, like_count = ?, updated_at = NOW(), last_reply_at = COALESCE(last_reply_at, NOW())
         WHERE id = ?'
    );
    $updateStmt->bind_param('iii', $replyCount, $likeCount, $threadId);
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
    'is_admin' => false
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
            'user_id' => $context['user_id'],
            'masked_user_id' => buildMaskedUserLabel($context['user_id']),
            'auth_exp' => $context['auth_exp']
        ]);
    }

    $conn = dbConnect();
    $context = buildSessionContext($conn, $input);

    if ($action === 'thread_list') {
        $scope = trim((string) ($input['scope'] ?? 'suggestions'));
        $page = max(1, readInt($input['page'] ?? 1, 1));
        $pageSize = min(50, max(1, readInt($input['page_size'] ?? 20, 20)));
        $threads = fetchThreadList($conn, $scope, $context, $page, $pageSize);

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

            sendJson(200, '回复成功', [
                'reply_id' => $replyId,
                'thread_id' => $threadId
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
