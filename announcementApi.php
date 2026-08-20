<?php
declare(strict_types=1);

date_default_timezone_set('PRC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth_session.php';

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');

function sendAnnouncementJson(int $httpCode, int $code, string $msg, array $data = []): void
{
    http_response_code($httpCode);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readAnnouncementInput(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    return array_merge($_GET, $input);
}

function ensureAnnouncementSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS update_announcements (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            announcement_key varchar(64) NOT NULL,
            title varchar(120) NOT NULL,
            content text NOT NULL,
            signature varchar(500) NOT NULL DEFAULT '',
            status enum('draft','published','archived') NOT NULL DEFAULT 'draft',
            push_version int unsigned NOT NULL DEFAULT 1,
            popup_enabled tinyint(1) NOT NULL DEFAULT 0,
            published_at datetime DEFAULT NULL,
            created_by varchar(20) NOT NULL,
            updated_by varchar(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_announcement_key (announcement_key),
            KEY idx_announcement_public (status, published_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $column = $conn->query("SHOW COLUMNS FROM update_announcements LIKE 'popup_enabled'")->fetch_assoc();
    if (!$column) {
        $conn->query("ALTER TABLE update_announcements ADD COLUMN popup_enabled tinyint(1) NOT NULL DEFAULT 0 AFTER push_version");
    }

    $signatureColumn = $conn->query("SHOW COLUMNS FROM update_announcements LIKE 'signature'")->fetch_assoc();
    if (!$signatureColumn) {
        $conn->query("ALTER TABLE update_announcements ADD COLUMN signature varchar(500) NOT NULL DEFAULT '' AFTER content");
    }

    $seedKey = '20260812-feature-update';
    $seedTitle = '课表更新说明';
    $seedContent = "1. 新增“问题反馈与建议”：点击右上角“设置”即可进入并提交。\n\n"
        . "2. 新增“课程讨论”：点击课程卡片，再点击详情弹窗右上角图标，即可针对该课程匿名留言。\n\n"
        . "3. 课表与班级群全面联动：教师可通过课程卡片创建班级群，学生可加入已创建的班级群。\n\n"
        . "4. 查看完整更新日志：点击右上角“设置”即可查看。";
    $seedSignature = "东方世家\n2026年8月12日";
    $legacySeedContent = $seedContent . "\n\n" . $seedSignature;
    $seedUser = '2023195077';

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO update_announcements
            (announcement_key, title, content, signature, status, push_version, popup_enabled, published_at, created_by, updated_by)
         VALUES (?, ?, ?, ?, 'published', 1, 1, NOW(), ?, ?)"
    );
    $stmt->bind_param('ssssss', $seedKey, $seedTitle, $seedContent, $seedSignature, $seedUser, $seedUser);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE update_announcements SET content = ?, signature = ?
         WHERE announcement_key = ? AND signature = '' AND content = ?"
    );
    $stmt->bind_param('ssss', $seedContent, $seedSignature, $seedKey, $legacySeedContent);
    $stmt->execute();
    $stmt->close();
}

function announcementAdminContext(mysqli $conn, array $input): array
{
    $userId = normalizeUserId($input['user_id'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? 0);
    $authSig = trim((string) ($input['auth_sig'] ?? ''));

    if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
        sendAnnouncementJson(401, 401, '身份认证已失效，请在白天重新进入课表', ['requiresAuth' => true]);
    }

    $stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $isAdmin = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$isAdmin) {
        sendAnnouncementJson(403, 403, '仅管理员可以管理更新公告');
    }

    return ['user_id' => $userId];
}

function normalizeAnnouncementText($value, int $maxLength): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    return mb_substr($text, 0, $maxLength, 'UTF-8');
}

function announcementRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'announcement_key' => (string) $row['announcement_key'],
        'title' => (string) $row['title'],
        'content' => (string) $row['content'],
        'signature' => (string) $row['signature'],
        'status' => (string) $row['status'],
        'push_version' => (int) $row['push_version'],
        'popup_enabled' => (int) $row['popup_enabled'] === 1,
        'published_at' => $row['published_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $conn->set_charset('utf8mb4');
    ensureAnnouncementSchema($conn);
    $input = readAnnouncementInput();
    $action = trim((string) ($input['action'] ?? 'current'));

    if ($action === 'current') {
        $result = $conn->query(
            "SELECT id, announcement_key, title, content, signature, status, push_version, popup_enabled, published_at, created_at, updated_at
             FROM update_announcements
             WHERE status = 'published' AND popup_enabled = 1
             ORDER BY published_at DESC, id DESC LIMIT 1"
        );
        $row = $result->fetch_assoc();
        sendAnnouncementJson(200, 200, '更新公告获取成功', [
            'announcement' => $row ? announcementRow($row) : null,
        ]);
    }

    $context = announcementAdminContext($conn, $input);
    $adminUserId = $context['user_id'];

    if ($action === 'admin_list') {
        $result = $conn->query(
            "SELECT id, announcement_key, title, content, signature, status, push_version, popup_enabled, published_at, created_at, updated_at
             FROM update_announcements ORDER BY id DESC LIMIT 200"
        );
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = announcementRow($row);
        }
        sendAnnouncementJson(200, 200, '公告管理列表获取成功', ['items' => $items]);
    }

    if ($action === 'logs') {
        $result = $conn->query(
            "SELECT id, announcement_key, title, content, signature, status, push_version, popup_enabled, published_at, created_at, updated_at
             FROM update_announcements
             WHERE status IN ('published', 'archived')
             ORDER BY published_at DESC, id DESC LIMIT 100"
        );
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = announcementRow($row);
        }
        sendAnnouncementJson(200, 200, '历史公告获取成功', ['items' => $items]);
    }

    if ($action === 'create') {
        $title = normalizeAnnouncementText($input['title'] ?? '', 120);
        $content = normalizeAnnouncementText($input['content'] ?? '', 12000);
        $signature = normalizeAnnouncementText($input['signature'] ?? '', 500);
        $publish = !empty($input['publish']);
        if ($title === '' || $content === '') {
            sendAnnouncementJson(422, 422, '公告标题和内容不能为空');
        }
        $key = 'announcement-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $status = $publish ? 'published' : 'draft';
        $publishedAt = $publish ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare(
            'INSERT INTO update_announcements
                (announcement_key, title, content, signature, status, push_version, popup_enabled, published_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $popupEnabled = $publish ? 1 : 0;
        $stmt->bind_param('sssssisss', $key, $title, $content, $signature, $status, $popupEnabled, $publishedAt, $adminUserId, $adminUserId);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        if ($publish) {
            $stmt = $conn->prepare('UPDATE update_announcements SET popup_enabled = 0 WHERE popup_enabled = 1 AND id <> ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        sendAnnouncementJson(200, 200, $publish ? '公告已创建并发布' : '公告草稿已保存', ['id' => $id]);
    }

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        sendAnnouncementJson(422, 422, '无效的公告编号');
    }

    if ($action === 'update') {
        $title = normalizeAnnouncementText($input['title'] ?? '', 120);
        $content = normalizeAnnouncementText($input['content'] ?? '', 12000);
        $signature = normalizeAnnouncementText($input['signature'] ?? '', 500);
        if ($title === '' || $content === '') {
            sendAnnouncementJson(422, 422, '公告标题和内容不能为空');
        }
        $stmt = $conn->prepare(
            "UPDATE update_announcements SET title = ?, content = ?, signature = ?, updated_by = ?
             WHERE id = ? AND status <> 'archived'"
        );
        $stmt->bind_param('ssssi', $title, $content, $signature, $adminUserId, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        sendAnnouncementJson(200, 200, '公告内容已保存', ['updated' => $affected > 0]);
    }

    if ($action === 'publish') {
        $stmt = $conn->prepare(
            "UPDATE update_announcements
             SET status = 'published', popup_enabled = 1, published_at = NOW(), updated_by = ?
             WHERE id = ? AND status <> 'archived'"
        );
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $published = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$published) {
            sendAnnouncementJson(404, 404, '公告不存在或已归档');
        }
        $stmt = $conn->prepare('UPDATE update_announcements SET popup_enabled = 0 WHERE popup_enabled = 1 AND id <> ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        sendAnnouncementJson(200, 200, '公告已发布');
    }

    if ($action === 'repush') {
        $stmt = $conn->prepare(
            "UPDATE update_announcements
             SET status = 'published', push_version = push_version + 1,
                 popup_enabled = 1, published_at = NOW(), updated_by = ?
             WHERE id = ? AND status <> 'archived'"
        );
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $repushed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$repushed) {
            sendAnnouncementJson(404, 404, '公告不存在或已归档');
        }
        $stmt = $conn->prepare('UPDATE update_announcements SET popup_enabled = 0 WHERE popup_enabled = 1 AND id <> ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        sendAnnouncementJson(200, 200, '已再次向全部用户推送');
    }

    if ($action === 'unpublish') {
        $stmt = $conn->prepare(
            "UPDATE update_announcements
             SET popup_enabled = 0, updated_by = ?
             WHERE id = ? AND status = 'published'"
        );
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $stmt->close();
        sendAnnouncementJson(200, 200, '公告已停止推送');
    }

    if ($action === 'archive') {
        $stmt = $conn->prepare(
            "UPDATE update_announcements
             SET status = 'archived', popup_enabled = 0, updated_by = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $stmt->close();
        sendAnnouncementJson(200, 200, '公告已归档');
    }

    sendAnnouncementJson(404, 404, '未知操作');
} catch (Throwable $error) {
    error_log('announcementApi error: ' . $error->getMessage());
    sendAnnouncementJson(500, 500, '更新公告服务暂时不可用');
}
