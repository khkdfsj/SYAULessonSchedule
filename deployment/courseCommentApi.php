<?php

date_default_timezone_set('PRC');
ini_set('display_errors', 'On');
error_reporting(E_ALL);
header('Content-Type: application/json;charset=utf-8');

require_once __DIR__ . '/auth_session.php';

define('DB_HOST', getenv('LESSON_SCHEDULE_DB_HOST') ?: '');
define('DB_PORT', 3306);
define('DB_USER', getenv('LESSON_SCHEDULE_DB_USER') ?: '');
define('DB_PASS', getenv('LESSON_SCHEDULE_DB_PASS') ?: '');
define('DB_NAME', getenv('LESSON_SCHEDULE_DB_NAME') ?: '');
define('COURSE_COMMENT_DELETE_WINDOW', 300);

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

function dbConnect()
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        sendJson(500, '数据库连接失败', [], 500);
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function logCourseCommentError($conn, $message, $userId = null)
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

function trimText($value, $maxLength = 500)
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

function normalizeCourseText($value, $maxLength = 120)
{
    $text = str_replace("\xE3\x80\x80", ' ', (string) $value);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function normalizeCourseCanonical($value, $maxLength = 120)
{
    $text = normalizeCourseText($value, $maxLength);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }

    return strtolower($text);
}

function buildCourseIdentity($courseName, $teacherName)
{
    $displayCourseName = normalizeCourseText($courseName, 120);
    $displayTeacherName = normalizeCourseText($teacherName, 80);
    if ($displayCourseName === '' || $displayTeacherName === '') {
        return null;
    }

    return [
        'course_name' => $displayCourseName,
        'teacher_name' => $displayTeacherName,
        'course_key' => hash('sha256', normalizeCourseCanonical($displayCourseName, 120) . '|' . normalizeCourseCanonical($displayTeacherName, 80))
    ];
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
        'is_admin' => $authenticated ? isAdminUser($conn, $userId) : false
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

function isAdminUser($conn, $userId)
{
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

function getThreadByCourseKey($conn, $courseKey)
{
    $stmt = $conn->prepare(
        'SELECT id, course_key, course_name, teacher_name, comment_count, like_count, created_at, updated_at, last_comment_at
         FROM course_comment_threads WHERE course_key = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $courseKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function getThreadById($conn, $threadId)
{
    $stmt = $conn->prepare(
        'SELECT id, course_key, course_name, teacher_name, comment_count, like_count, created_at, updated_at, last_comment_at
         FROM course_comment_threads WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function formatThreadRow($row)
{
    return [
        'id' => isset($row['id']) && $row['id'] !== null ? (int) $row['id'] : null,
        'course_key' => $row['course_key'],
        'course_name' => $row['course_name'],
        'teacher_name' => $row['teacher_name'],
        'comment_count' => (int) ($row['comment_count'] ?? 0),
        'like_count' => (int) ($row['like_count'] ?? 0),
        'created_at' => $row['created_at'] ?? '',
        'updated_at' => $row['updated_at'] ?? '',
        'last_comment_at' => $row['last_comment_at'] ?? ''
    ];
}

function getScheduleCacheRow($conn, $userId)
{
    $stmt = $conn->prepare('SELECT ScheduleData FROM ScheduleData WHERE UserID = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function hasCourseMembership($conn, $userId, array $courseIdentity)
{
    $normalizedUserId = normalizeUserId($userId);
    if ($normalizedUserId === '') {
        return false;
    }

    $row = getScheduleCacheRow($conn, $normalizedUserId);
    if (!$row || !isset($row['ScheduleData'])) {
        return false;
    }

    $courses = json_decode($row['ScheduleData'], true);
    if (!is_array($courses)) {
        return false;
    }

    foreach ($courses as $course) {
        if (!is_array($course)) {
            continue;
        }

        $candidate = buildCourseIdentity($course['name'] ?? '', $course['teacher'] ?? '');
        if ($candidate && hash_equals($candidate['course_key'], $courseIdentity['course_key'])) {
            return true;
        }
    }

    return false;
}

function ensureCourseAccess($conn, $context, array $courseIdentity)
{
    requireAuth($context);
    if ($context['is_admin']) {
        return;
    }

    if (!hasCourseMembership($conn, $context['user_id'], $courseIdentity)) {
        sendJson(403, '当前账号课表中未找到该课程，无法查看或操作评论', [], 403);
    }
}

function ensureCommentThread($conn, array $courseIdentity)
{
    $stmt = $conn->prepare(
        'INSERT INTO course_comment_threads
         (course_key, course_name, teacher_name, comment_count, like_count, created_at, updated_at, last_comment_at)
         VALUES (?, ?, ?, 0, 0, NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), teacher_name = VALUES(teacher_name), updated_at = NOW()'
    );
    if (!$stmt) {
        sendJson(500, '创建课程评论区失败', [], 500);
    }

    $stmt->bind_param('sss', $courseIdentity['course_key'], $courseIdentity['course_name'], $courseIdentity['teacher_name']);
    $stmt->execute();
    $stmt->close();

    $thread = getThreadByCourseKey($conn, $courseIdentity['course_key']);
    if (!$thread) {
        sendJson(500, '创建课程评论区失败', [], 500);
    }

    return $thread;
}

function getLikedByMe($conn, $commentId, $userId)
{
    $normalizedUserId = normalizeUserId($userId);
    if ($normalizedUserId === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM course_comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $commentId, $normalizedUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $liked = $result && $result->num_rows > 0;
    $stmt->close();

    return $liked;
}

function getCommentById($conn, $commentId)
{
    $stmt = $conn->prepare(
        'SELECT c.id, c.thread_id, c.user_id, c.content, c.status, c.like_count, c.created_at, c.deleted_at, c.deleted_by_user_id, c.deleted_by_role,
                t.course_key, t.course_name, t.teacher_name
         FROM course_comments c
         INNER JOIN course_comment_threads t ON t.id = c.thread_id
         WHERE c.id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $commentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function refreshCommentLikeCount($conn, $commentId)
{
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM course_comment_likes WHERE comment_id = ?');
    $countStmt->bind_param('i', $commentId);
    $countStmt->execute();
    $count = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $updateStmt = $conn->prepare('UPDATE course_comments SET like_count = ? WHERE id = ?');
    $updateStmt->bind_param('ii', $count, $commentId);
    $updateStmt->execute();
    $updateStmt->close();

    return $count;
}

function refreshThreadStats($conn, $threadId)
{
    $statsStmt = $conn->prepare(
        'SELECT COUNT(*) AS comment_count, COALESCE(SUM(like_count), 0) AS like_count, MAX(created_at) AS last_comment_at
         FROM course_comments WHERE thread_id = ? AND status = \'active\''
    );
    $statsStmt->bind_param('i', $threadId);
    $statsStmt->execute();
    $statsRow = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    $commentCount = (int) ($statsRow['comment_count'] ?? 0);
    $likeCount = (int) ($statsRow['like_count'] ?? 0);
    $lastCommentAt = $statsRow['last_comment_at'] ?: date('Y-m-d H:i:s');

    $updateStmt = $conn->prepare(
        'UPDATE course_comment_threads
         SET comment_count = ?, like_count = ?, last_comment_at = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $updateStmt->bind_param('iisi', $commentCount, $likeCount, $lastCommentAt, $threadId);
    $updateStmt->execute();
    $updateStmt->close();

    return [
        'comment_count' => $commentCount,
        'like_count' => $likeCount,
        'last_comment_at' => $lastCommentAt
    ];
}

function formatCommentRow($conn, $row, $context)
{
    $isMine = ($context['user_id'] ?? '') !== '' && $row['user_id'] === ($context['user_id'] ?? '');
    $createdAt = strtotime((string) ($row['created_at'] ?? ''));
    $deleteDeadline = $createdAt ? date('Y-m-d H:i:s', $createdAt + COURSE_COMMENT_DELETE_WINDOW) : '';
    $canDelete = $isMine && $row['status'] === 'active' && $createdAt > 0 && time() <= ($createdAt + COURSE_COMMENT_DELETE_WINDOW);

    $formatted = [
        'id' => (int) $row['id'],
        'thread_id' => (int) $row['thread_id'],
        'content' => $row['content'],
        'created_at' => $row['created_at'],
        'display_name' => $isMine ? '我' : '匿名同学',
        'like_count' => (int) $row['like_count'],
        'liked_by_me' => ($context['authenticated'] ?? false) ? getLikedByMe($conn, (int) $row['id'], $context['user_id']) : false,
        'is_mine' => $isMine,
        'can_delete' => $canDelete,
        'delete_deadline_at' => $deleteDeadline
    ];

    if ($context['is_admin']) {
        $formatted['author_user_id'] = $row['user_id'];
    }

    return $formatted;
}

function fetchCommentList($conn, $threadId, $context, $page, $pageSize)
{
    $offset = max(0, ($page - 1) * $pageSize);
    $stmt = $conn->prepare(
        'SELECT id, thread_id, user_id, content, status, like_count, created_at
         FROM course_comments
         WHERE thread_id = ? AND status = \'active\'
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        sendJson(500, '读取评论失败', [], 500);
    }

    $stmt->bind_param('iii', $threadId, $pageSize, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = [];
    while ($row = $result->fetch_assoc()) {
        $list[] = formatCommentRow($conn, $row, $context);
    }
    $stmt->close();

    return $list;
}

function countActiveComments($conn, $threadId)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM course_comments WHERE thread_id = ? AND status = \'active\'');
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    return $total;
}

$conn = dbConnect();

try {
    $input = readJsonInput();
    $action = trim((string) ($input['action'] ?? $_GET['action'] ?? ''));
    $context = buildSessionContext($conn, $input);

    if ($action === 'comment_list') {
        $courseIdentity = buildCourseIdentity($input['course_name'] ?? '', $input['teacher_name'] ?? '');
        if (!$courseIdentity) {
            sendJson(400, '缺少课程名或教师名', [], 400);
        }

        ensureCourseAccess($conn, $context, $courseIdentity);

        $page = max(1, readInt($input['page'] ?? 1, 1));
        $pageSize = min(50, max(1, readInt($input['page_size'] ?? 20, 20)));
        $thread = getThreadByCourseKey($conn, $courseIdentity['course_key']);

        if (!$thread) {
            sendJson(200, '课程评论获取成功', [
                'thread' => formatThreadRow([
                    'id' => null,
                    'course_key' => $courseIdentity['course_key'],
                    'course_name' => $courseIdentity['course_name'],
                    'teacher_name' => $courseIdentity['teacher_name'],
                    'comment_count' => 0,
                    'like_count' => 0,
                    'created_at' => '',
                    'updated_at' => '',
                    'last_comment_at' => ''
                ]),
                'list' => [],
                'page' => $page,
                'page_size' => $pageSize,
                'total' => 0,
                'permissions' => [
                    'can_comment' => true,
                    'can_admin_delete' => $context['is_admin']
                ]
            ]);
        }

        $total = countActiveComments($conn, (int) $thread['id']);
        $list = fetchCommentList($conn, (int) $thread['id'], $context, $page, $pageSize);
        sendJson(200, '课程评论获取成功', [
            'thread' => formatThreadRow($thread),
            'list' => $list,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'permissions' => [
                'can_comment' => true,
                'can_admin_delete' => $context['is_admin']
            ]
        ]);
    }

    if ($action === 'comment_create') {
        $courseIdentity = buildCourseIdentity($input['course_name'] ?? '', $input['teacher_name'] ?? '');
        $content = trimText($input['content'] ?? '', 500);
        if (!$courseIdentity || $content === '') {
            sendJson(400, '缺少必要参数', [], 400);
        }

        ensureCourseAccess($conn, $context, $courseIdentity);
        $conn->begin_transaction();

        try {
            $thread = ensureCommentThread($conn, $courseIdentity);
            $stmt = $conn->prepare(
                'INSERT INTO course_comments
                 (thread_id, user_id, content, status, like_count, created_at, deleted_at, deleted_by_user_id, deleted_by_role)
                 VALUES (?, ?, ?, \'active\', 0, NOW(), NULL, NULL, NULL)'
            );
            $stmt->bind_param('iss', $thread['id'], $context['user_id'], $content);
            $stmt->execute();
            $commentId = (int) $conn->insert_id;
            $stmt->close();

            $threadStats = refreshThreadStats($conn, (int) $thread['id']);
            $comment = getCommentById($conn, $commentId);
            $conn->commit();

            sendJson(200, '评论发布成功', [
                'thread' => formatThreadRow(array_merge($thread, $threadStats)),
                'comment' => formatCommentRow($conn, $comment, $context)
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'comment_delete') {
        requireAuth($context);
        $commentId = readInt($input['comment_id'] ?? 0, 0);
        if ($commentId <= 0) {
            sendJson(400, '缺少 comment_id', [], 400);
        }

        $comment = getCommentById($conn, $commentId);
        if (!$comment || $comment['status'] !== 'active') {
            sendJson(404, '评论不存在', [], 404);
        }

        if ($comment['user_id'] !== $context['user_id']) {
            sendJson(403, '只能撤回自己的评论', [], 403);
        }

        $createdAt = strtotime((string) $comment['created_at']);
        if (!$createdAt || time() > ($createdAt + COURSE_COMMENT_DELETE_WINDOW)) {
            sendJson(403, '评论发布超过5分钟，无法撤回', [], 403);
        }

        $conn->begin_transaction();
        try {
            $deleteLikesStmt = $conn->prepare('DELETE FROM course_comment_likes WHERE comment_id = ?');
            $deleteLikesStmt->bind_param('i', $commentId);
            $deleteLikesStmt->execute();
            $deleteLikesStmt->close();

            $updateStmt = $conn->prepare(
                'UPDATE course_comments
                 SET status = \'deleted_by_user\', like_count = 0, deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = \'user\'
                 WHERE id = ?'
            );
            $updateStmt->bind_param('si', $context['user_id'], $commentId);
            $updateStmt->execute();
            $updateStmt->close();

            $threadStats = refreshThreadStats($conn, (int) $comment['thread_id']);
            $thread = getThreadById($conn, (int) $comment['thread_id']);
            $conn->commit();

            sendJson(200, '评论已撤回', [
                'comment_id' => $commentId,
                'thread_id' => (int) $comment['thread_id'],
                'thread' => formatThreadRow(array_merge($thread ?: [], $threadStats))
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'like_toggle') {
        requireAuth($context);
        $commentId = readInt($input['comment_id'] ?? 0, 0);
        if ($commentId <= 0) {
            sendJson(400, '缺少 comment_id', [], 400);
        }

        $comment = getCommentById($conn, $commentId);
        if (!$comment || $comment['status'] !== 'active') {
            sendJson(404, '评论不存在', [], 404);
        }

        if (!$context['is_admin']) {
            ensureCourseAccess($conn, $context, [
                'course_key' => $comment['course_key'],
                'course_name' => $comment['course_name'],
                'teacher_name' => $comment['teacher_name']
            ]);
        }

        $conn->begin_transaction();
        try {
            $existsStmt = $conn->prepare('SELECT id FROM course_comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1');
            $existsStmt->bind_param('is', $commentId, $context['user_id']);
            $existsStmt->execute();
            $existsResult = $existsStmt->get_result();
            $existsRow = $existsResult ? $existsResult->fetch_assoc() : null;
            $existsStmt->close();

            $liked = false;
            if ($existsRow) {
                $deleteStmt = $conn->prepare('DELETE FROM course_comment_likes WHERE id = ?');
                $deleteStmt->bind_param('i', $existsRow['id']);
                $deleteStmt->execute();
                $deleteStmt->close();
            } else {
                $insertStmt = $conn->prepare('INSERT INTO course_comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())');
                $insertStmt->bind_param('is', $commentId, $context['user_id']);
                $insertStmt->execute();
                $insertStmt->close();
                $liked = true;
            }

            $likeCount = refreshCommentLikeCount($conn, $commentId);
            $threadStats = refreshThreadStats($conn, (int) $comment['thread_id']);
            $conn->commit();

            sendJson(200, '点赞状态已更新', [
                'comment_id' => $commentId,
                'liked' => $liked,
                'like_count' => $likeCount,
                'thread_like_count' => $threadStats['like_count']
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    if ($action === 'admin_thread_list') {
        requireAdmin($context);

        $page = max(1, readInt($input['page'] ?? 1, 1));
        $pageSize = min(50, max(1, readInt($input['page_size'] ?? 20, 20)));
        $offset = max(0, ($page - 1) * $pageSize);
        $keyword = normalizeCourseText($input['keyword'] ?? '', 120);
        $searchKeyword = $keyword === '' ? '' : '%' . $keyword . '%';

        $countStmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM course_comment_threads
             WHERE comment_count > 0 AND (? = \'\' OR course_name LIKE ? OR teacher_name LIKE ?)'
        );
        if (!$countStmt) {
            sendJson(500, '读取评论区列表失败', [], 500);
        }
        $countStmt->bind_param('sss', $keyword, $searchKeyword, $searchKeyword);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        $total = (int) ($countRow['total'] ?? 0);

        $stmt = $conn->prepare(
            'SELECT id, course_key, course_name, teacher_name, comment_count, like_count, created_at, updated_at, last_comment_at
             FROM course_comment_threads
             WHERE comment_count > 0 AND (? = \'\' OR course_name LIKE ? OR teacher_name LIKE ?)
             ORDER BY last_comment_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        if (!$stmt) {
            sendJson(500, '读取评论区列表失败', [], 500);
        }
        $stmt->bind_param('sssii', $keyword, $searchKeyword, $searchKeyword, $pageSize, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $list = [];
        while ($row = $result->fetch_assoc()) {
            $list[] = formatThreadRow($row);
        }
        $stmt->close();

        sendJson(200, '课程评论管理列表获取成功', [
            'list' => $list,
            'page' => $page,
            'page_size' => $pageSize,
            'keyword' => $keyword,
            'total' => $total
        ]);
    }

    if ($action === 'admin_delete') {
        requireAdmin($context);
        $commentId = readInt($input['comment_id'] ?? 0, 0);
        if ($commentId <= 0) {
            sendJson(400, '缺少 comment_id', [], 400);
        }

        $comment = getCommentById($conn, $commentId);
        if (!$comment || $comment['status'] !== 'active') {
            sendJson(404, '评论不存在', [], 404);
        }

        $conn->begin_transaction();
        try {
            $deleteLikesStmt = $conn->prepare('DELETE FROM course_comment_likes WHERE comment_id = ?');
            $deleteLikesStmt->bind_param('i', $commentId);
            $deleteLikesStmt->execute();
            $deleteLikesStmt->close();

            $updateStmt = $conn->prepare(
                'UPDATE course_comments
                 SET status = \'deleted_by_admin\', like_count = 0, deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = \'admin\'
                 WHERE id = ?'
            );
            $updateStmt->bind_param('si', $context['user_id'], $commentId);
            $updateStmt->execute();
            $updateStmt->close();

            $threadStats = refreshThreadStats($conn, (int) $comment['thread_id']);
            $thread = getThreadById($conn, (int) $comment['thread_id']);
            $conn->commit();

            sendJson(200, '评论已删除', [
                'comment_id' => $commentId,
                'thread_id' => (int) $comment['thread_id'],
                'thread' => formatThreadRow(array_merge($thread ?: [], $threadStats))
            ]);
        } catch (Throwable $throwable) {
            $conn->rollback();
            throw $throwable;
        }
    }

    sendJson(400, '无效的 action', [], 400);
} catch (Throwable $throwable) {
    logCourseCommentError($conn, 'courseCommentApi: ' . $throwable->getMessage(), $context['user_id'] ?? null);
    sendJson(500, '课程评论系统处理失败', [], 500);
} finally {
    $conn->close();
}
