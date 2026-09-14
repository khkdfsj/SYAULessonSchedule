<?php
declare(strict_types=1);

date_default_timezone_set('PRC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/schedule_adjustment_lib.php';

const ADJUSTMENT_DB_HOST = '127.0.0.1';
const ADJUSTMENT_DB_PORT = 3306;
const ADJUSTMENT_DB_USER = 'LessonTable';
const ADJUSTMENT_DB_PASS = 'syau8848@';
const ADJUSTMENT_DB_NAME = 'LessonTable';

function sendAdjustmentJson(int $httpCode, int $code, string $msg, array $data = []): void
{
    http_response_code($httpCode);
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readAdjustmentInput(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return array_merge($_GET, is_array($input) ? $input : []);
}

function adjustmentAdminContext(mysqli $conn, array $input): string
{
    $userId = normalizeUserId($input['user_id'] ?? '');
    $authExp = normalizeAuthExpire($input['auth_exp'] ?? 0);
    $authSig = trim((string) ($input['auth_sig'] ?? ''));
    if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
        sendAdjustmentJson(401, 401, '身份认证已失效，请在白天重新进入课表', ['requiresAuth' => true]);
    }
    $stmt = $conn->prepare('SELECT 1 FROM feedback_admins WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $isAdmin = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$isAdmin) {
        sendAdjustmentJson(403, 403, '仅管理员可以管理调课计划');
    }
    return $userId;
}

function adjustmentText($value, int $maxLength): string
{
    return mb_substr(trim((string) $value), 0, $maxLength, 'UTF-8');
}

function adjustmentPlanRows(mysqli $conn): array
{
    $plans = [];
    $result = $conn->query(
        "SELECT id, plan_key, name, semester_mark, notice_title, notice_url, status,
                published_at, created_at, updated_at
         FROM schedule_adjustment_plans ORDER BY id DESC LIMIT 200"
    );
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['rules'] = [];
        $plans[$row['id']] = $row;
    }
    if (!$plans) {
        return [];
    }
    $result = $conn->query(
        'SELECT id, plan_id, education_type, entry_year, source_week, source_weekday,
                target_week, target_weekday, sort_order
         FROM schedule_adjustment_rules ORDER BY plan_id DESC, sort_order, id'
    );
    while ($rule = $result->fetch_assoc()) {
        $planId = (int) $rule['plan_id'];
        if (!isset($plans[$planId])) {
            continue;
        }
        foreach (['id', 'plan_id', 'entry_year', 'source_week', 'source_weekday', 'target_week', 'target_weekday', 'sort_order'] as $field) {
            $rule[$field] = (int) $rule[$field];
        }
        $plans[$planId]['rules'][] = $rule;
    }
    return array_values($plans);
}

function normalizeAdjustmentRules($rawRules): array
{
    if (!is_array($rawRules) || count($rawRules) < 1 || count($rawRules) > 50) {
        sendAdjustmentJson(422, 422, '每个调课计划应包含1至50条规则');
    }
    $rules = [];
    foreach ($rawRules as $index => $rawRule) {
        if (!is_array($rawRule)) {
            sendAdjustmentJson(422, 422, '调课规则格式不正确');
        }
        $educationType = trim((string) ($rawRule['education_type'] ?? ''));
        $entryYear = (int) ($rawRule['entry_year'] ?? 0);
        $sourceWeek = (int) ($rawRule['source_week'] ?? 0);
        $sourceWeekday = (int) ($rawRule['source_weekday'] ?? 0);
        $targetWeek = (int) ($rawRule['target_week'] ?? 0);
        $targetWeekday = (int) ($rawRule['target_weekday'] ?? 0);
        if (!in_array($educationType, ['undergraduate', 'graduate'], true)) {
            sendAdjustmentJson(422, 422, '请选择本科生或研究生');
        }
        if ($entryYear !== 0 && ($entryYear < 2000 || $entryYear > 2100)) {
            sendAdjustmentJson(422, 422, '生效年级不正确');
        }
        if ($sourceWeek < 1 || $sourceWeek > 30 || $targetWeek < 1 || $targetWeek > 30
            || $sourceWeekday < 1 || $sourceWeekday > 7 || $targetWeekday < 1 || $targetWeekday > 7) {
            sendAdjustmentJson(422, 422, '调课周次或星期不正确');
        }
        if ($sourceWeek === $targetWeek && $sourceWeekday === $targetWeekday) {
            sendAdjustmentJson(422, 422, '来源时间和调整后时间不能相同');
        }
        $rules[] = compact('educationType', 'entryYear', 'sourceWeek', 'sourceWeekday', 'targetWeek', 'targetWeekday') + ['sortOrder' => $index + 1];
    }
    return $rules;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(ADJUSTMENT_DB_HOST, ADJUSTMENT_DB_USER, ADJUSTMENT_DB_PASS, ADJUSTMENT_DB_NAME, ADJUSTMENT_DB_PORT);
    $conn->set_charset('utf8mb4');
    $input = readAdjustmentInput();
    $adminUserId = adjustmentAdminContext($conn, $input);
    ensureScheduleAdjustmentSchema($conn);
    $action = trim((string) ($input['action'] ?? 'list'));

    if ($action === 'list') {
        sendAdjustmentJson(200, 200, '调课计划获取成功', ['items' => adjustmentPlanRows($conn)]);
    }

    if ($action === 'save') {
        $id = (int) ($input['id'] ?? 0);
        $name = adjustmentText($input['name'] ?? '', 120);
        $semesterMark = adjustmentText($input['semester_mark'] ?? '', 32);
        $noticeTitle = adjustmentText($input['notice_title'] ?? '', 200);
        $noticeUrl = adjustmentText($input['notice_url'] ?? '', 500);
        $rules = normalizeAdjustmentRules($input['rules'] ?? []);
        if ($name === '' || !preg_match('/^\d{4}-(spring|fall)$/', $semesterMark)) {
            sendAdjustmentJson(422, 422, '请填写计划名称并选择正确学期');
        }
        if ($noticeUrl !== '' && !filter_var($noticeUrl, FILTER_VALIDATE_URL)) {
            sendAdjustmentJson(422, 422, '通知链接格式不正确');
        }

        $conn->begin_transaction();
        try {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE schedule_adjustment_plans
                    SET name = ?, semester_mark = ?, notice_title = ?, notice_url = ?, updated_by = ?
                    WHERE id = ? AND status <> 'archived'");
                $stmt->bind_param('sssssi', $name, $semesterMark, $noticeTitle, $noticeUrl, $adminUserId, $id);
                $stmt->execute();
                if ($stmt->affected_rows < 1) {
                    $check = $conn->prepare("SELECT id FROM schedule_adjustment_plans WHERE id = ? AND status <> 'archived'");
                    $check->bind_param('i', $id);
                    $check->execute();
                    if ($check->get_result()->num_rows < 1) {
                        throw new RuntimeException('PLAN_NOT_FOUND');
                    }
                    $check->close();
                }
                $stmt->close();
                $stmt = $conn->prepare('DELETE FROM schedule_adjustment_rules WHERE plan_id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            } else {
                $planKey = 'adjustment-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                $stmt = $conn->prepare("INSERT INTO schedule_adjustment_plans
                    (plan_key, name, semester_mark, notice_title, notice_url, status, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)");
                $stmt->bind_param('sssssss', $planKey, $name, $semesterMark, $noticeTitle, $noticeUrl, $adminUserId, $adminUserId);
                $stmt->execute();
                $id = (int) $stmt->insert_id;
                $stmt->close();
            }

            $stmt = $conn->prepare('INSERT INTO schedule_adjustment_rules
                (plan_id, education_type, entry_year, source_week, source_weekday, target_week, target_weekday, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rules as $rule) {
                $educationType = $rule['educationType'];
                $entryYear = $rule['entryYear'];
                $sourceWeek = $rule['sourceWeek'];
                $sourceWeekday = $rule['sourceWeekday'];
                $targetWeek = $rule['targetWeek'];
                $targetWeekday = $rule['targetWeekday'];
                $sortOrder = $rule['sortOrder'];
                $stmt->bind_param('isiiiiii', $id, $educationType, $entryYear, $sourceWeek, $sourceWeekday, $targetWeek, $targetWeekday, $sortOrder);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            if ($error->getMessage() === 'PLAN_NOT_FOUND') {
                sendAdjustmentJson(404, 404, '调课计划不存在或已归档');
            }
            throw $error;
        }
        sendAdjustmentJson(200, 200, '调课计划已保存', ['id' => $id]);
    }

    $id = (int) ($input['id'] ?? 0);
    if ($id < 1) {
        sendAdjustmentJson(422, 422, '无效的调课计划编号');
    }
    if ($action === 'publish') {
        $stmt = $conn->prepare("UPDATE schedule_adjustment_plans SET status = 'published', published_at = NOW(), updated_by = ? WHERE id = ? AND status <> 'archived'");
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$ok) sendAdjustmentJson(404, 404, '调课计划不存在或已归档');
        sendAdjustmentJson(200, 200, '调课计划已发布');
    }
    if ($action === 'unpublish') {
        $stmt = $conn->prepare("UPDATE schedule_adjustment_plans SET status = 'draft', updated_by = ? WHERE id = ? AND status = 'published'");
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $stmt->close();
        sendAdjustmentJson(200, 200, '调课计划已停止');
    }
    if ($action === 'archive') {
        $stmt = $conn->prepare("UPDATE schedule_adjustment_plans SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('si', $adminUserId, $id);
        $stmt->execute();
        $stmt->close();
        sendAdjustmentJson(200, 200, '调课计划已归档');
    }
    sendAdjustmentJson(404, 404, '未知操作');
} catch (Throwable $error) {
    error_log('scheduleAdjustmentApi error: ' . $error->getMessage());
    sendAdjustmentJson(500, 500, '调课管理服务暂时不可用');
}
