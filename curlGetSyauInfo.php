<?php
date_default_timezone_set('PRC');
ini_set("display_errors", "On");
error_reporting(E_ALL);
header("Content-Type: application/json;charset=utf-8");

require_once __DIR__ . '/schedule_adjustment_lib.php';

// 数据库配置
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');

// 应用版本号（版本管理：前端每次进入校验 appVersion，非最新强制更新）
define('APP_VERSION', '0.5.6');

// 学校课表接口夜间不可用：22:00-06:00 强制读取数据库缓存，不访问上游。
define('QUIET_START', '22:00');
define('QUIET_END', '06:00');
// 白天短时复用刚刚取得的本学期数据，避免同一用户反复进入时重复冲击学校网关。
define('DAY_CACHE_TTL_SECONDS', 180);
define('UPSTREAM_CIRCUIT_FILE', '/tmp/lesson_schedule_upstream_circuit.json');

// 沿用原项目方案：学校公网网关仅放行微信/企业微信浏览器环境，再转发到 114 课表服务。
define('API_URL', 'https://syauinfo.syau.edu.cn/LessonSchedule/LessonScheduleData.php');
define('WECHAT_BROWSER_USER_AGENT', 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/33.0.0.0 Mobile Safari/537.36 MicroMessenger/6.0.0.54_r849063.501 NetType/WIFI');

/**********************
 * 响应处理
 **********************/
function sendResponse($code, $msg, $data = [])
{
    http_response_code($code);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function logError($message, $userID = null)
{
    global $conn;

    $stmt = $conn->prepare("INSERT INTO error_logs (message, user_id) VALUES (?, ?)");
    $stmt->bind_param("ss", $message, $userID);
    $stmt->execute();

    // 同时返回错误到客户端
    sendResponse(500, '操作失败', ['debug' => $message]);
}

function recordError($message, $userID = null)
{
    global $conn;
    if (!$conn instanceof mysqli) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO error_logs (message, user_id) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("ss", $message, $userID);
    $stmt->execute();
    $stmt->close();
}

/**********************
 * 工具函数
 **********************/
function isQuietTime()
{
    $current = date('H:i');
    return $current >= QUIET_START || $current < QUIET_END;
}

function getValidInput()
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($input['UserID'])) {
        sendResponse(400, '无效的请求格式');
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]{5,20}$/', $input['UserID'])) {
        sendResponse(400, '非法的用户ID格式');
    }
    return $input;
}

function postJsonUrl($url, $data, $timeout = 8)
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ' . WECHAT_BROWSER_USER_AGENT,
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout
    ]);

    $body = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$body, $httpStatus, $error];
}

function updateUpstreamCircuit($callback)
{
    $handle = @fopen(UPSTREAM_CIRCUIT_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return null;
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = json_decode((string) $raw, true);
    if (!is_array($state)) {
        $state = ['failures' => 0, 'open_until' => 0, 'probe_until' => 0];
    }

    $result = $callback($state);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function acquireUpstreamPermit()
{
    $result = updateUpstreamCircuit(function (&$state) {
        $now = time();
        $openUntil = (int) ($state['open_until'] ?? 0);
        $probeUntil = (int) ($state['probe_until'] ?? 0);
        $failures = (int) ($state['failures'] ?? 0);

        if ($openUntil > $now) {
            return [false, '学校网关短时保护中'];
        }
        if ($failures > 0 && $probeUntil > $now) {
            return [false, '学校网关恢复探测中'];
        }
        if ($failures > 0) {
            // 熔断到期后只放一个探测请求，避免所有 PHP 进程同时重试。
            $state['probe_until'] = $now + 12;
        }
        return [true, ''];
    });

    return is_array($result) ? $result : [true, ''];
}

function markUpstreamFailure()
{
    updateUpstreamCircuit(function (&$state) {
        $failures = min(5, max(0, (int) ($state['failures'] ?? 0)) + 1);
        $delay = min(300, 30 * (2 ** ($failures - 1)));
        $state = [
            'failures' => $failures,
            'open_until' => time() + $delay,
            'probe_until' => 0
        ];
        return true;
    });
}

function markUpstreamHealthy()
{
    updateUpstreamCircuit(function (&$state) {
        $state = ['failures' => 0, 'open_until' => 0, 'probe_until' => 0];
        return true;
    });
}

function fetchOnlineSchedule($userID)
{
    list($permitted, $circuitReason) = acquireUpstreamPermit();
    if (!$permitted) {
        return [false, [], $circuitReason];
    }

    list($body, $httpStatus, $error) = postJsonUrl(API_URL, ['UserID' => $userID]);
    if ($body === false || $httpStatus !== 200) {
        markUpstreamFailure();
        return [false, [], $error ?: ('HTTP ' . $httpStatus)];
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
        // 兼容上游 PHP 将 Notice 错误地写在 JSON 前面的历史行为。
        // 只截取明确的课表 JSON 起点，避免单个可选字段缺失导致整份新课表回退旧缓存。
        $jsonStart = strpos((string) $body, '{"code"');
        if ($jsonStart !== false) {
            $response = json_decode(substr($body, $jsonStart), true);
        }
    }
    $responseCode = is_array($response) && isset($response['code']) ? (int) $response['code'] : null;
    if ($responseCode !== null && !in_array($responseCode, [0, 200], true)) {
        if ($responseCode >= 500) {
            markUpstreamFailure();
        }
        return [false, [], isset($response['msg']) ? (string) $response['msg'] : 'schedule service error'];
    }

    // 教务接口对“当前无课程”的用户不一定返回 code，但会明确返回空 courseInfo。
    if (!is_array($response) || !isset($response['courseInfo']) || !is_array($response['courseInfo'])) {
        markUpstreamFailure();
        return [false, [], 'invalid schedule response'];
    }

    markUpstreamHealthy();
    return [true, array_values($response['courseInfo']), ''];
}

/**********************
 * 数据操作函数
 **********************/
function userExists($conn, $userID)
{
    $stmt = $conn->prepare("SELECT 1 FROM user WHERE UserID = ?");
    $stmt->bind_param("s", $userID);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function registerUser($conn, $userID)
{
    $stmt = $conn->prepare("INSERT INTO user (UserID) VALUES (?)");
    $stmt->bind_param("s", $userID);
    return $stmt->execute();
}

function getScheduleCacheRecord($conn, $userID)
{
    $sql = "SELECT ScheduleData, UpdateTime FROM ScheduleData WHERE UserID = ?
            ORDER BY UpdateTime DESC LIMIT 1";
    $stmt = $conn->prepare($sql);

    // 新增错误检查
    if ($stmt === false) {
        logError("SQL Prepare Error: " . $conn->error);
        return null;
    }
    $stmt->bind_param("s", $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        return null;
    }

    $row = $result->fetch_assoc();
    $courses = json_decode($row['ScheduleData'], true);
    return [
        'courses' => is_array($courses) ? array_values($courses) : [],
        'updated_at' => (string) $row['UpdateTime']
    ];
}

function getScheduleCache($conn, $userID)
{
    $record = getScheduleCacheRecord($conn, $userID);
    return $record === null ? null : $record['courses'];
}

function getExpectedPlanPrefix($startDate)
{
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', (string) $startDate, $matches)) {
        return '';
    }

    $year = (int) $matches[1];
    $month = (int) $matches[2];
    return $month >= 8
        ? $year . '-' . ($year + 1) . '-1'
        : ($year - 1) . '-' . $year . '-2';
}

function isCurrentSemesterCache($record, $userID)
{
    if (!is_array($record) || !isset($record['courses'], $record['updated_at'])) {
        return false;
    }

    $calendar = fetchCalendarInfo();
    if ($calendar === null) {
        // 校历服务临时不可用时不扩大故障范围，仍允许使用已有缓存。
        return true;
    }

    // 教务处通常会在开学前提前发布新课表，缓存有效期从开学日前 30 天开始计算。
    $semesterStart = strtotime($calendar['start_date'] . ' 00:00:00 -30 days');
    $cacheTime = strtotime($record['updated_at']);
    if ($semesterStart !== false && ($cacheTime === false || $cacheTime < $semesterStart)) {
        return false;
    }

    $identityDigit = substr((string) $userID, 4, 1);
    if (!in_array($identityDigit, ['1', '2', '5', '6'], true)) {
        return true;
    }

    $expectedPrefix = getExpectedPlanPrefix($calendar['start_date']);
    $foundPlan = false;
    foreach ($record['courses'] as $course) {
        $planNumber = trim((string) ($course['planNumber'] ?? ''));
        if ($planNumber === '') {
            continue;
        }
        $foundPlan = true;
        if ($expectedPrefix !== '' && strpos($planNumber, $expectedPrefix) === 0) {
            return true;
        }
    }

    // 新生成的空课表或暂不带教学计划号的数据，以本学期内的更新时间为准。
    return !$foundPlan;
}

function isFreshDayCache($record, $userID)
{
    if (!isCurrentSemesterCache($record, $userID)) {
        return false;
    }
    $cacheTime = strtotime((string) $record['updated_at']);
    return $cacheTime !== false && (time() - $cacheTime) >= 0
        && (time() - $cacheTime) <= DAY_CACHE_TTL_SECONDS;
}

function updateScheduleCache($conn, $userID, $courseInfo)
{
    $jsonData = json_encode(array_values($courseInfo), JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare(
        'REPLACE INTO ScheduleData (UserID, ScheduleData, UpdateTime) VALUES (?, ?, NOW())'
    );
    $stmt->bind_param('ss', $userID, $jsonData);
    return $stmt->execute();
}

/**********************
 * 核心业务处理函数
 **********************/
function sendCourseResponse($conn, $userID, $courseInfo, $source)
{
    $originalCourses = is_array($courseInfo) ? array_values($courseInfo) : [];
    $courses = $originalCourses;

    // 原始课表继续按现有缓存策略保存；调课副本仅在响应阶段按已发布规则动态生成。
    // 规则停用后无需清理用户缓存，教师身份也不会进入调课匹配。
    $calendar = fetchCalendarInfo();
    if ($calendar !== null) {
        $courses = applyPublishedScheduleAdjustments(
            $conn,
            (string) $userID,
            $courses,
            (string) $calendar['mark'],
            (string) $calendar['start_date']
        );
    }
    $message = count($courses) > 0
        ? '操作成功'
        : '当前无课程信息，请时刻关注教务处官方信息';

    $data = [
        'UserID' => $userID,
        'courseInfo' => $courses,
        'originalCourseInfo' => $originalCourses,
        'source' => $source,
        'appVersion' => APP_VERSION
    ];

    // 方案B：从同机校历服务获取本学期开学日期并下发（前端服务端优先，强制采用）
    if ($calendar !== null) {
        $data['semesterStartDate'] = $calendar['start_date'];
        $data['semesterMark'] = $calendar['mark'];
    }

    sendResponse(200, $message, $data);
}

/**
 * 从同机校历服务（syau-calendar, 127.0.0.1:5080）获取当前学期开学日期与学期标记。
 * @return array|null ['start_date' => 'Y-m-d', 'mark' => '2026-fall']
 */
function fetchCalendarInfo(): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://127.0.0.1:5080/api/calendar',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno !== 0 || $response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['start_date'])) {
        return null;
    }

    $startDate = (string) $data['start_date'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startDate, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }

    $month = (int) $m[2];
    $year = (int) $m[1];
    $mark = ($month >= 8) ? $year . '-fall' : $year . '-spring';
    return ['start_date' => $startDate, 'mark' => $mark];
}

function handleExistingUser($conn, $userID)
{
    if (isQuietTime()) {
        $cacheRecord = getScheduleCacheRecord($conn, $userID);
        if (isCurrentSemesterCache($cacheRecord, $userID)) {
            sendCourseResponse($conn, $userID, $cacheRecord['courses'], '非api在线时间，数据库缓存');
        }

        sendResponse(503, '当前为夜间缓存时段，暂无本学期可用课表缓存，请在白天重新进入', [
            'UserID' => $userID,
            'courseInfo' => [],
            'source' => '非api在线时间，无本学期可用缓存',
            'staleCacheRejected' => $cacheRecord !== null,
            'appVersion' => APP_VERSION
        ]);
    }

    $cacheRecord = getScheduleCacheRecord($conn, $userID);
    if (isFreshDayCache($cacheRecord, $userID)) {
        sendCourseResponse($conn, $userID, $cacheRecord['courses'], '学校数据短时缓存');
    }

    list($ok, $courseInfo, $failureReason) = fetchOnlineSchedule($userID);
    if ($ok) {
        updateScheduleCache($conn, $userID, $courseInfo);
        sendCourseResponse($conn, $userID, $courseInfo, '学校实时数据');
    }

    if (strpos($failureReason, '学校网关') !== 0) {
        recordError('学校实时课表请求失败：' . $failureReason, $userID);
    }
    $cacheRecord = getScheduleCacheRecord($conn, $userID);
    if (isCurrentSemesterCache($cacheRecord, $userID)) {
        sendCourseResponse($conn, $userID, $cacheRecord['courses'], '实时请求失败，使用本学期数据库缓存');
    }

    sendResponse(503, $cacheRecord === null ? '课表服务暂不可用，请稍后重试' : '旧学期课表已停止显示，请稍后重试获取本学期课表', [
        'UserID' => $userID,
        'courseInfo' => [],
        'source' => '学校实时接口不可用',
        'staleCacheRejected' => $cacheRecord !== null,
        'appVersion' => APP_VERSION
    ]);
}

function handleNewUser($conn, $userID)
{
    if (isQuietTime()) {
        sendResponse(503, '当前为夜间缓存时段，新用户请在白天首次进入并建立课表缓存', [
            'UserID' => $userID,
            'courseInfo' => [],
            'source' => '非api在线时间，无可用缓存'
        ]);
    }

    if (!registerUser($conn, $userID)) {
        sendResponse(500, '用户注册失败');
    }

    list($ok, $courseInfo, $failureReason) = fetchOnlineSchedule($userID);
    if ($ok) {
        updateScheduleCache($conn, $userID, $courseInfo);
        sendCourseResponse($conn, $userID, $courseInfo, '学校实时数据');
    }

    if (strpos($failureReason, '学校网关') !== 0) {
        recordError('学校实时课表请求失败：' . $failureReason, $userID);
    }
    sendResponse(503, '课表服务暂不可用，请稍后重试', [
        'UserID' => $userID,
        'courseInfo' => [],
        'source' => '学校实时接口不可用',
        'appVersion' => APP_VERSION
    ]);
}

/**********************
 * 主程序
 **********************/
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    logError("DB Connection Failed: " . $conn->connect_error);
    sendResponse(500, '数据库连接失败');
}
$conn->set_charset("utf8mb4");

try {
    $input = getValidInput();
    $userID = $input['UserID'];

    if (userExists($conn, $userID)) {
        handleExistingUser($conn, $userID);
    } else {
        handleNewUser($conn, $userID);
    }
} catch (Exception $e) {
    logError("System Error: " . $e->getMessage());
    sendResponse(500, '系统处理异常');
} finally {
    $conn->close();
}
