<?php
declare(strict_types=1);

date_default_timezone_set('PRC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/survey_lib.php';

const SURVEY_DB_HOST = '127.0.0.1';
const SURVEY_DB_PORT = 3306;
const SURVEY_DB_USER = 'LessonTable';
const SURVEY_DB_PASS = 'syau8848@';
const SURVEY_DB_NAME = 'LessonTable';

function sendSurveyJson(int $httpCode, int $code, string $msg, array $data = []): void
{
    http_response_code($httpCode);
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readSurveyInput(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return array_merge($_GET, is_array($input) ? $input : []);
}

function surveyRequireUser(array $input): string
{
    $userId = normalizeUserId($input['user_id'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? 0);
    $authSig = trim((string) ($input['auth_sig'] ?? ''));
    if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
        sendSurveyJson(401, 401, '身份认证已失效，请在白天重新进入课表', ['requiresAuth' => true]);
    }
    return $userId;
}

function surveyRequireAdmin(mysqli $conn, array $input): string
{
    $userId = surveyRequireUser($input);
    $stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $isAdmin = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$isAdmin) {
        sendSurveyJson(403, 403, '仅管理员可以管理问卷');
    }
    return $userId;
}

function surveyActiveRound(mysqli $conn): ?array
{
    $result = $conn->query(
        "SELECT id, round_key, title, intro, status, entry_enabled, activated_at
         FROM survey_rounds WHERE status = 'active' ORDER BY activated_at DESC, id DESC LIMIT 1"
    );
    $row = $result->fetch_assoc();
    return $row ?: null;
}

function surveyRoundById(mysqli $conn, int $roundId): ?array
{
    $stmt = $conn->prepare('SELECT id, round_key, title, intro, status, entry_enabled FROM survey_rounds WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $roundId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * 识别填写人：签名有效 → 按学号；夜间等没有有效签名时 → 按客户端令牌匿名去重。
 * 问卷服务完全部署在外网（bm 本机数据库 + 本地 HMAC 校验），不依赖内网，夜间缓存时段同样可填。
 */
function surveyResolveRespondent(array $input): array
{
    $userId = normalizeUserId($input['user_id'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? 0);
    $authSig = trim((string) ($input['auth_sig'] ?? ''));
    if ($userId !== '' && isAuthSignatureValid($userId, $authExp, $authSig)) {
        return ['key' => $userId, 'user_id' => $userId, 'verified' => 1];
    }

    $token = trim((string) ($input['client_token'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{8,48}$/', $token)) {
        return ['key' => 'a:' . $token, 'user_id' => '', 'verified' => 0];
    }
    return ['key' => '', 'user_id' => '', 'verified' => 0];
}

function surveyFilledModes(mysqli $conn, int $roundId, string $respondentKey): array
{
    $filled = ['quick' => false, 'full' => false];
    if ($respondentKey === '') {
        return $filled;
    }
    $stmt = $conn->prepare('SELECT quiz_mode FROM survey_responses WHERE round_id = ? AND respondent_key = ?');
    $stmt->bind_param('is', $roundId, $respondentKey);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $mode = (string) $row['quiz_mode'];
        if (isset($filled[$mode])) {
            $filled[$mode] = true;
        }
    }
    $stmt->close();
    return $filled;
}

function surveyModeSelection(string $mode, string $userId, string $roundKey): array
{
    return $mode === 'quick' ? surveyQuickSelection($userId, $roundKey) : surveyFullSelection();
}

function surveyText($value, int $maxLength): string
{
    return mb_substr(trim((string) $value), 0, $maxLength, 'UTF-8');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(SURVEY_DB_HOST, SURVEY_DB_USER, SURVEY_DB_PASS, SURVEY_DB_NAME, SURVEY_DB_PORT);
    $conn->set_charset('utf8mb4');
    ensureSurveySchema($conn);

    $input = readSurveyInput();
    $action = trim((string) ($input['action'] ?? 'entry'));

    /** ---------------- 管理端 ---------------- */
    if (in_array($action, ['adminList', 'adminSave', 'adminStatus', 'adminResults'], true)) {
        $adminUserId = surveyRequireAdmin($conn, $input);

        if ($action === 'adminList') {
            $rounds = [];
            $result = $conn->query(
                'SELECT id, round_key, title, intro, status, entry_enabled, activated_at, closed_at, created_at, updated_at
                 FROM survey_rounds ORDER BY id DESC LIMIT 100'
            );
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int) $row['id'];
                $row['entry_enabled'] = (int) $row['entry_enabled'] === 1;
                $row['responses'] = ['quick' => 0, 'full' => 0, 'total' => 0];
                $rounds[$row['id']] = $row;
            }
            if ($rounds) {
                $result = $conn->query(
                    'SELECT round_id, quiz_mode, COUNT(*) AS total FROM survey_responses GROUP BY round_id, quiz_mode'
                );
                while ($row = $result->fetch_assoc()) {
                    $roundId = (int) $row['round_id'];
                    $mode = (string) $row['quiz_mode'];
                    if (!isset($rounds[$roundId]) || !isset($rounds[$roundId]['responses'][$mode])) {
                        continue;
                    }
                    $rounds[$roundId]['responses'][$mode] = (int) $row['total'];
                    $rounds[$roundId]['responses']['total'] += (int) $row['total'];
                }
            }
            sendSurveyJson(200, 200, '问卷期次获取成功', [
                'items' => array_values($rounds),
                'blocks' => surveyBlockDefinitions(),
                'definitions' => array_map(static function (array $item): array {
                    return ['key' => $item['key'], 'title' => $item['title'], 'block' => $item['block'], 'quick' => !empty($item['quick'])];
                }, surveyItemDefinitions()),
            ]);
        }

        if ($action === 'adminSave') {
            $id = (int) ($input['id'] ?? 0);
            $title = surveyText($input['title'] ?? '', 120);
            $intro = surveyText($input['intro'] ?? '', 300);
            $entryEnabled = !empty($input['entry_enabled']) ? 1 : 0;
            $status = trim((string) ($input['status'] ?? 'draft'));
            if ($title === '') {
                sendSurveyJson(422, 422, '请填写问卷标题');
            }
            if (!in_array($status, ['draft', 'active', 'closed'], true)) {
                sendSurveyJson(422, 422, '发布状态不正确');
            }
            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE survey_rounds SET title = ?, intro = ?, entry_enabled = ?, status = ?, updated_by = ? WHERE id = ?');
                $stmt->bind_param('ssissi', $title, $intro, $entryEnabled, $status, $adminUserId, $id);
                $stmt->execute();
                $stmt->close();
                sendSurveyJson(200, 200, '问卷期次已保存', ['id' => $id]);
            }
            $roundKey = 'survey-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $stmt = $conn->prepare(
                "INSERT INTO survey_rounds (round_key, title, intro, status, entry_enabled, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssissi', $roundKey, $title, $intro, $status, $entryEnabled, $adminUserId, $adminUserId);
            $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            sendSurveyJson(200, 200, '问卷期次已创建', ['id' => $newId]);
        }

        if ($action === 'adminStatus') {
            $id = (int) ($input['id'] ?? 0);
            $status = trim((string) ($input['status'] ?? ''));
            if ($id < 1 || !in_array($status, ['draft', 'active', 'closed'], true)) {
                sendSurveyJson(422, 422, '参数不正确');
            }
            if ($status === 'active') {
                // 同一时间只允许一期进行中，避免入口指向不明
                $stmt = $conn->prepare("UPDATE survey_rounds SET status = 'closed', closed_at = NOW(), updated_by = ? WHERE status = 'active' AND id <> ?");
                $stmt->bind_param('si', $adminUserId, $id);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("UPDATE survey_rounds SET status = 'active', activated_at = NOW(), closed_at = NULL, updated_by = ? WHERE id = ?");
            } elseif ($status === 'closed') {
                $stmt = $conn->prepare("UPDATE survey_rounds SET status = 'closed', closed_at = NOW(), updated_by = ? WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE survey_rounds SET status = 'draft', updated_by = ? WHERE id = ?");
            }
            $stmt->bind_param('si', $adminUserId, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected < 1 && surveyRoundById($conn, $id) === null) {
                sendSurveyJson(404, 404, '问卷期次不存在');
            }
            $labels = ['draft' => '已下架为草稿', 'active' => '已发布，管理员可见入口', 'closed' => '已结束'];
            sendSurveyJson(200, 200, $labels[$status]);
        }

        $roundId = (int) ($input['round_id'] ?? 0);
        if ($roundId < 1 || surveyRoundById($conn, $roundId) === null) {
            sendSurveyJson(422, 422, '问卷期次不存在');
        }
        sendSurveyJson(200, 200, '问卷数据获取成功', surveyRoundResults($conn, $roundId));
    }

    /** ---------------- 用户端 ---------------- */
    $respondent = surveyResolveRespondent($input);
    $respondentKey = (string) $respondent['key'];

    if ($action === 'entry') {
        $round = surveyActiveRound($conn);
        if ($round === null) {
            sendSurveyJson(200, 200, '当前没有进行中的问卷', ['enabled' => false, 'round' => null, 'filled' => ['quick' => false, 'full' => false]]);
        }
        $filled = surveyFilledModes($conn, (int) $round['id'], $respondentKey);
        sendSurveyJson(200, 200, '问卷状态获取成功', [
            'enabled' => (int) $round['entry_enabled'] === 1,
            'round' => [
                'id' => (int) $round['id'],
                'title' => $round['title'],
                'intro' => $round['intro'],
            ],
            'filled' => $filled,
        ]);
    }

    if ($action === 'questions') {
        $mode = trim((string) ($input['mode'] ?? 'quick'));
        if (!in_array($mode, ['quick', 'full'], true)) {
            sendSurveyJson(422, 422, '问卷类型不正确');
        }
        $round = surveyActiveRound($conn);
        if ($round === null) {
            sendSurveyJson(404, 404, '当前没有进行中的问卷');
        }
        if (surveyFilledModes($conn, (int) $round['id'], $respondentKey)[$mode]) {
            sendSurveyJson(409, 409, '这份问卷你已经提交过了，不能修改');
        }
        $keys = surveyModeSelection($mode, $respondentKey, (string) $round['round_key']);
        sendSurveyJson(200, 200, '题目获取成功', [
            'mode' => $mode,
            'round' => ['id' => (int) $round['id'], 'title' => $round['title'], 'intro' => $round['intro']],
            'blocks' => surveyBlockDefinitions(),
            'items' => surveyItemsForKeys($keys),
        ]);
    }

    if ($action === 'submit') {
        $mode = trim((string) ($input['mode'] ?? 'quick'));
        if (!in_array($mode, ['quick', 'full'], true)) {
            sendSurveyJson(422, 422, '问卷类型不正确');
        }
        $round = surveyActiveRound($conn);
        if ($round === null) {
            sendSurveyJson(404, 404, '当前没有进行中的问卷');
        }
        $roundId = (int) $round['id'];
        if ($respondentKey === '') {
            sendSurveyJson(401, 401, '无法识别填写人，请重新进入课表后再试', ['requiresAuth' => true]);
        }
        if (surveyFilledModes($conn, $roundId, $respondentKey)[$mode]) {
            sendSurveyJson(409, 409, '这份问卷你已经提交过了，不能修改');
        }

        $keys = surveyModeSelection($mode, $respondentKey, (string) $round['round_key']);
        $definitions = [];
        foreach ($keys as $key) {
            $item = surveyItemByKey($key);
            if ($item !== null) {
                $definitions[$key] = $item;
            }
        }

        $rawAnswers = $input['answers'] ?? [];
        if (!is_array($rawAnswers)) {
            sendSurveyJson(422, 422, '答案格式不正确');
        }

        $prepared = [];
        foreach ($definitions as $key => $item) {
            if (!array_key_exists($key, $rawAnswers)) {
                if (!empty($item['required'])) {
                    sendSurveyJson(422, 422, '还有必答题没有完成');
                }
                continue;
            }
            $error = '';
            $value = surveyNormalizeAnswer($item, $rawAnswers[$key], $error);
            if ($value === null) {
                sendSurveyJson(422, 422, $error !== '' ? $error : '答案不正确');
            }
            if ($value === '' || $value === []) {
                continue;
            }
            $prepared[$key] = $value;

            if (isset($item['followUp'])) {
                $followUp = $item['followUp'];
                $needFollowUp = in_array((string) $value, $followUp['when'] ?? [], true);
                if ($needFollowUp && array_key_exists($followUp['key'], $rawAnswers)) {
                    $followError = '';
                    $followValue = surveyNormalizeAnswer($followUp, $rawAnswers[$followUp['key']], $followError);
                    if ($followValue === null) {
                        sendSurveyJson(422, 422, $followError !== '' ? $followError : '答案不正确');
                    }
                    if ($followValue !== '' && $followValue !== []) {
                        $prepared[$followUp['key']] = $followValue;
                    }
                }
            }
        }

        if (!$prepared) {
            sendSurveyJson(422, 422, '请至少回答一道题');
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO survey_responses (round_id, respondent_key, user_id, verified, quiz_mode, asked_items) VALUES (?, ?, ?, ?, ?, ?)');
            $asked = json_encode(array_keys($definitions), JSON_UNESCAPED_UNICODE);
            $respondentUserId = (string) $respondent['user_id'];
            $verified = (int) $respondent['verified'];
            $stmt->bind_param('ississ', $roundId, $respondentKey, $respondentUserId, $verified, $mode, $asked);
            $stmt->execute();
            $responseId = (int) $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO survey_answers (response_id, round_id, item_key, answer_json) VALUES (?, ?, ?, ?)');
            foreach ($prepared as $key => $value) {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE);
                $stmt->bind_param('iiss', $responseId, $roundId, $key, $json);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            if ((int) $error->getCode() === 1062) {
                sendSurveyJson(409, 409, '这份问卷你已经提交过了，不能修改');
            }
            throw $error;
        }

        sendSurveyJson(200, 200, '提交成功，感谢你的反馈');
    }

    sendSurveyJson(404, 404, '未知操作');
} catch (Throwable $error) {
    error_log('surveyApi error: ' . $error->getMessage());
    sendSurveyJson(500, 500, '问卷服务暂时不可用');
}
