<?php
date_default_timezone_set('PRC');
ini_set("display_errors", "On");
error_reporting(E_ALL);
header("Content-Type: application/json;charset=utf-8");

// 数据库配置
define('DB_HOST', '47.122.95.108');
define('DB_PORT', 3306);
define('DB_USER', 'LessonTable');
define('DB_PASS', 'syau8848@');
define('DB_NAME', 'LessonTable');

// 系统配置
define('QUIET_START', '22:00');
define('QUIET_END', '06:00');
define('API_URL', 'https://syauinfo.syau.edu.cn/LessonSchedule/LessonScheduleData.php');
define('WECHAT_BROWSER_USER_AGENT', 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/33.0.0.0 Mobile Safari/537.36 MicroMessenger/6.0.0.54_r849063.501 NetType/WIFI');

/**********************
 * 响应处理
 **********************/
function sendResponse($code, $msg, $data = [])
{
    http_response_code($code);
    echo json_encode([
        'code' => 200,
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
    $current = date("H:i");
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

function postJsonUrl($url, $data, $timeout = 30)
{
    $data = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: ' . WECHAT_BROWSER_USER_AGENT,
        'Content-Length: ' . strlen($data)
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // 设置超时参数
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // 总执行时间
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 连接超时时间

    // 执行cURL请求并检查是否有错误发生
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [false, 504]; // 返回504表示网关超时
    }

    // 获取HTTP响应状态码
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 关闭cURL会话
    curl_close($ch);

    return [$response, $httpStatus];
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

function deleteUser($conn, $userID)
{
    $stmt = $conn->prepare("DELETE FROM user WHERE UserID = ?");
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

function updateScheduleCache($conn, $userID, $data)
{
    $jsonData = json_encode($data);
    $stmt = $conn->prepare("REPLACE INTO ScheduleData (UserID, ScheduleData, UpdateTime) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $userID, $jsonData);
    return $stmt->execute();
}

/**********************
 * 核心业务处理函数
 **********************/
function handleExistingUser($conn, $userID)
{
    if (isQuietTime()) {
        $cache = getScheduleCache($conn, $userID);
        if ($cache) {
            sendResponse(200, '操作成功', [
                'UserID' => $userID,
                'courseInfo' => $cache,
                'source' => '非api在线时间，数据库缓存'
            ]);
        } else {
            sendResponse(404, '无可用缓存数据');
        }
    } else {
        // 设置5秒超时
        $timeout = 5;
        $apiResponse = postJsonUrl(API_URL, ['UserID' => $userID], $timeout);
        
        // 判断是否超时或请求失败
        if ($apiResponse[1] != 200 || $apiResponse === false) {
            $fallback = getScheduleCache($conn, $userID) ?? [];
            sendResponse(200, '操作成功', [
                'UserID' => $userID,
                'courseInfo' => $fallback,
                'source' => 'api请求超时或失败，使用数据库缓存'
            ]);
            return;
        }
        
        $apiData = json_decode($apiResponse[0], true);
        if ($apiData && ($apiData['code'] ?? 500) === 200) {
            updateScheduleCache($conn, $userID, $apiData['courseInfo']);
            sendResponse(200, '操作成功', [
                'UserID' => $userID,
                'courseInfo' => $apiData['courseInfo'],
                'source' => '在线数据'
            ]);
        } else {
            $fallback = getScheduleCache($conn, $userID) ?? [];
            sendResponse(200, '操作成功', [
                'UserID' => $userID,
                'courseInfo' => $fallback,
                'source' => 'api数据获取失败，使用数据库缓存'
            ]);
        }
    }
}



function handleNewUser($conn, $userID)
{
    if (!registerUser($conn, $userID)) {
        sendResponse(500, '用户注册失败');
    }

    if (isQuietTime()) {
        deleteUser($conn, $userID); // 回滚注册
        sendResponse(403, '静默时段禁止新用户注册');
    }

    $apiResponse = postJsonUrl(API_URL, ['UserID' => $userID]);
    if ($apiResponse[1] != 200) {
        logError("API请求失败，状态码：{$apiResponse[1]}", $userID);
    }

    $apiData = json_decode($apiResponse[0], true); // 解析返回的JSON
    if ($apiData && ($apiData['code'] ?? 500) === 200 && updateScheduleCache($conn, $userID, $apiData['courseInfo'])) {
        sendResponse(200, '操作成功', [
            'UserID' => $userID,
            'courseInfo' => $apiData['courseInfo'],
            'source' => '新用户数据'
        ]);
    } else {
        deleteUser($conn, $userID); // 回滚注册
        sendResponse(500, '初始数据获取失败');
    }
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
