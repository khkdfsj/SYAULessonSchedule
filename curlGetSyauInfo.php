<?php
date_default_timezone_set('PRC');
ini_set("display_errors", "On");
error_reporting(E_ALL);
header("Content-Type: application/json;charset=utf-8");

// 数据库配置
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');

// 应用版本号（版本管理：前端每次进入校验 appVersion，非最新强制更新）
define('APP_VERSION', '0.5.1');

// 学校课表接口夜间不可用：22:00-06:00 强制读取数据库缓存，不访问上游。
define('QUIET_START', '22:00');
define('QUIET_END', '06:00');

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

function fetchOnlineSchedule($userID)
{
    list($body, $httpStatus, $error) = postJsonUrl(API_URL, ['UserID' => $userID]);
    if ($body === false || $httpStatus !== 200) {
        return [false, [], $error ?: ('HTTP ' . $httpStatus)];
    }

    $response = json_decode($body, true);
    $responseCode = is_array($response) && isset($response['code']) ? (int) $response['code'] : null;
    if ($responseCode !== null && !in_array($responseCode, [0, 200], true)) {
        return [false, [], isset($response['msg']) ? (string) $response['msg'] : 'schedule service error'];
    }

    // 教务接口对“当前无课程”的用户不一定返回 code，但会明确返回空 courseInfo。
    if (!is_array($response) || !isset($response['courseInfo']) || !is_array($response['courseInfo'])) {
        return [false, [], 'invalid schedule response'];
    }

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

function getScheduleCache($conn, $userID)
{

    $sql = "SELECT ScheduleData FROM ScheduleData  WHERE UserID = ? 
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
    return $result->num_rows > 0 ? json_decode($result->fetch_assoc()['ScheduleData'], true) : null;
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
function sendCourseResponse($userID, $courseInfo, $source)
{
    $courses = is_array($courseInfo) ? array_values($courseInfo) : [];
    $message = count($courses) > 0
        ? '操作成功'
        : '当前无课程信息，请时刻关注教务处官方信息';

    $data = [
        'UserID' => $userID,
        'courseInfo' => $courses,
        'source' => $source,
        'appVersion' => APP_VERSION
    ];

    // 方案B：从同机校历服务获取本学期开学日期并下发（前端服务端优先，强制采用）
    $calendar = fetchCalendarInfo();
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
        $cache = getScheduleCache($conn, $userID);
        if ($cache !== null) {
            sendCourseResponse($userID, $cache, '非api在线时间，数据库缓存');
        }

        sendResponse(503, '当前为夜间缓存时段，暂无可用课表缓存，请在白天重新进入', [
            'UserID' => $userID,
            'courseInfo' => [],
            'source' => '非api在线时间，无可用缓存'
        ]);
    }

    list($ok, $courseInfo) = fetchOnlineSchedule($userID);
    if ($ok) {
        updateScheduleCache($conn, $userID, $courseInfo);
        sendCourseResponse($userID, $courseInfo, '学校实时数据');
    }

    $cache = getScheduleCache($conn, $userID);
    if ($cache !== null) {
        sendCourseResponse($userID, $cache, '实时请求失败，使用数据库缓存');
    }

    sendResponse(503, '课表服务暂不可用，请稍后重试', [
        'UserID' => $userID,
        'courseInfo' => [],
        'source' => '学校实时接口不可用'
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

    list($ok, $courseInfo) = fetchOnlineSchedule($userID);
    if ($ok) {
        updateScheduleCache($conn, $userID, $courseInfo);
        sendCourseResponse($userID, $courseInfo, '学校实时数据');
    }

    sendResponse(503, '课表服务暂不可用，请稍后重试', [
        'UserID' => $userID,
        'courseInfo' => [],
        'source' => '学校实时接口不可用'
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
