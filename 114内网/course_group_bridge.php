<?php

/**
 * 课表与群聊助手之间的内网桥接接口。
 *
 * 设计原则：
 * 1. 每次操作都验证课表签名和当前用户真实课表，浏览器传来的课程名称、群主和编码均不可信。
 * 2. 课程群编码、唯一创建、上下学期复用和企业微信成员处理继续使用 113 的现有实现。
 * 3. 仅在短期专用会话中保存续建所需的学生名单，名单不返回公网服务器或浏览器。
 */

require_once __DIR__ . '/auth_session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function bridgeSend($code, $message, $data = array(), $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode(array_merge(array(
        'code' => (int) $code,
        'msg' => (string) $message
    ), is_array($data) ? $data : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getUrl($urlName)
{
    if ($urlName === 'getUserCourseInfo') {
        return 'http://study.syau.edu.cn/kcbkcb/servlet/getUserCourseInfo?account=';
    }
    if ($urlName === 'createGroupChatBackground') {
        return 'http://210.47.163.113/CreateGroupChatBackground/php/try.php';
    }
    return '';
}

function postJsonUrl($url, $data)
{
    $body = json_encode($data);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Content-Length: ' . strlen($body)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 30
    ));
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

require_once '/usr/local/nginx/html/qywx/CreateGroupChatOnstage/php/lazy_data.php';

function bridgeIsTester($userId)
{
    return in_array((string) $userId, array('2023195077', '2023140018', '2023132007', '2023195023', '2022170118'), true);
}

function bridgeRole($userId)
{
    $identity = substr((string) $userId, 4, 1);
    if ($identity === '5' || $identity === '6') {
        return 'teacher';
    }
    return bridgeIsTester($userId) ? 'tester' : 'student';
}

/**
 * 重新读取当前用户的官方课表并定位课程。
 * 同一课程可能按不同星期、周次返回多行，这里只合并建群名称所需的时间地点，
 * 不改变课表前端的任何展示记录。
 */
function bridgeFindAuthorizedCourse($userId, $courseNumber, $courseOrder, $teacherId, $planNumber)
{
    $identity = substr((string) $userId, 4, 1);
    $userType = ($identity === '5' || $identity === '6') ? 'teacher' : 'student';
    $result = lazy_get_json(getUrl('getUserCourseInfo') . rawurlencode($userId) . '&usertype=' . $userType);
    if (!$result['ok']) {
        throw new Exception($result['error']);
    }

    $rows = isset($result['data']['courseInfo']) && is_array($result['data']['courseInfo'])
        ? $result['data']['courseInfo']
        : array();
    $matched = null;
    $times = array();
    $locations = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) (isset($row['kch']) ? $row['kch'] : '') !== (string) $courseNumber
            || (string) (isset($row['kxh']) ? $row['kxh'] : '') !== (string) $courseOrder
            || (string) (isset($row['jsh']) ? $row['jsh'] : '') !== (string) $teacherId
            || (string) (isset($row['zxjxjhh']) ? $row['zxjxjhh'] : '') !== (string) $planNumber) {
            continue;
        }

        if ($matched === null) {
            $matched = $row;
        }
        $week = str_replace(array('第', '上'), '', (string) (isset($row['zcsm']) ? $row['zcsm'] : ''));
        $startSection = (int) (isset($row['skjc']) ? $row['skjc'] : 0);
        $sectionCount = max(1, (int) (isset($row['cxjc']) ? $row['cxjc'] : 1));
        $times[] = sprintf(
            '第%s星期%s-第%d到%d节',
            $week,
            isset($row['skxq']) ? $row['skxq'] : '',
            $startSection,
            $startSection + $sectionCount - 1
        );
        $locations[] = (string) (isset($row['jxlm']) ? $row['jxlm'] : '')
            . '-' . (string) (isset($row['jash']) ? $row['jash'] : '');
    }

    if ($matched === null) {
        return null;
    }
    if (($identity === '5' || $identity === '6') && (string) $matched['jsh'] !== (string) $userId) {
        return null;
    }

    $matched['name'] = isset($matched['kcm']) ? (string) $matched['kcm'] : '课程群';
    $matched['teacher'] = isset($matched['jsm']) ? (string) $matched['jsm'] : '';
    $matched['time'] = implode('、', array_values(array_unique(array_filter($times))));
    $matched['location'] = implode('、', array_values(array_unique(array_filter($locations))));
    return $matched;
}

function bridgeCallBackground($payload)
{
    $body = postJsonUrl(getUrl('createGroupChatBackground'), $payload);
    $result = json_decode($body, true);
    if (!is_array($result) || !isset($result['code'])) {
        return array('code' => 502, 'msg' => '建群后台返回格式异常，请稍后重试');
    }
    return $result;
}

function bridgeSanitizeResult($result)
{
    unset($result['userlist']);
    unset($result['officialRoster']);
    unset($result['_officialRoster']);
    unset($result['owner']);
    unset($result['chatid']);
    return $result;
}

function bridgeCreateCourseGroup($course, $propertiesResult)
{
    $groupName = $course['name'] . '-' . $course['teacher'] . $course['time'];
    return bridgeCallBackground(array(
        'kind' => 'createClassGroupChat',
        'groupName' => $groupName,
        'owner' => $course['jsh'],
        'ChatProperties' => $propertiesResult['properties'],
        'schoolYear' => $propertiesResult['schoolYear'],
        'semester' => $propertiesResult['semester'],
        'courseNumber' => $propertiesResult['courseNumber'],
        'courseOrder' => $propertiesResult['courseOrder'],
        'teacherId' => $propertiesResult['teacherId']
    ));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bridgeSend(405, '仅支持 POST 请求', array(), 405);
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    bridgeSend(400, '请求参数格式错误', array(), 400);
}

$userId = normalizeUserId(isset($input['user_id']) ? $input['user_id'] : '');
$authExp = isset($input['auth_exp']) ? $input['auth_exp'] : '';
$authSig = isset($input['auth_sig']) ? trim((string) $input['auth_sig']) : '';
if (!isAuthSignatureValid($userId, $authExp, $authSig)) {
    bridgeSend(401, '身份认证已失效，请重新认证', array(), 401);
}

// 使用签名派生专用短期会话，使 bm 转发请求时无需透传 114 的浏览器 Cookie。
session_name('LESSON_COURSE_GROUP_BRIDGE');
session_id(substr(hash('sha256', $userId . '|' . $authSig), 0, 40));
session_start();
if (!isset($_SESSION['retry']) || !is_array($_SESSION['retry'])) {
    $_SESSION['retry'] = array();
}
if (!isset($_SESSION['continuation']) || !is_array($_SESSION['continuation'])) {
    $_SESSION['continuation'] = array();
}

$action = isset($input['action']) ? trim((string) $input['action']) : '';
$courseNumber = isset($input['courseNumber']) ? trim((string) $input['courseNumber']) : '';
$courseOrder = isset($input['courseOrder']) ? trim((string) $input['courseOrder']) : '';
$teacherId = isset($input['teacherId']) ? trim((string) $input['teacherId']) : '';
$planNumber = isset($input['planNumber']) ? trim((string) $input['planNumber']) : '';

if (!preg_match('/^[A-Za-z0-9]+$/', $courseNumber)
    || !preg_match('/^\d{1,3}$/', $courseOrder)
    || !preg_match('/^\d{5,20}$/', $teacherId)
    || !preg_match('/^\d{4}-\d{4}-[12](?:-|$)/', $planNumber)) {
    bridgeSend(422, '课程号、课序号、教师或学期信息不完整，请刷新课表后重试');
}

try {
    $course = bridgeFindAuthorizedCourse($userId, $courseNumber, $courseOrder, $teacherId, $planNumber);
} catch (Exception $e) {
    bridgeSend(502, '重新验证课表失败：' . $e->getMessage());
}
if (!$course) {
    bridgeSend(403, '该课程不属于当前登录用户，无法执行课程群操作', array(), 403);
}

$propertiesResult = lazy_build_course_group_properties($course);
if (!$propertiesResult['ok']) {
    bridgeSend(422, $propertiesResult['msg']);
}
$properties = $propertiesResult['properties'];
$role = bridgeRole($userId);

if ($action === 'status') {
    $result = bridgeCallBackground(array(
        'kind' => 'courseGroupState',
        'ChatProperties' => $properties,
        'UserID' => $userId,
        'checkMembership' => $role === 'student'
    ));
    $result['role'] = $role;
    $result['ChatProperties'] = $properties;
    echo json_encode(bridgeSanitizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'join') {
    if ($role !== 'student') {
        bridgeSend(403, '授课教师无需执行学生入群操作', array(), 403);
    }
    $result = bridgeCallBackground(array(
        'kind' => 'joinCourseGroup',
        'ChatProperties' => $properties,
        'UserID' => $userId
    ));
    $result['role'] = $role;
    $result['ChatProperties'] = $properties;
    echo json_encode(bridgeSanitizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($role !== 'teacher' && $role !== 'tester') {
    bridgeSend(403, '只有授课教师可以创建课程群', array(), 403);
}

if ($action === 'create') {
    $result = bridgeCreateCourseGroup($course, $propertiesResult);
    if ((int) $result['code'] === 60111
        && isset($result['userlist'], $result['officialRoster'], $result['noUserId'])
        && is_array($result['userlist']) && is_array($result['officialRoster'])) {
        $_SESSION['retry'][$properties] = array(
            'expiresAt' => time() + 900,
            'groupName' => $result['groupName'],
            'owner' => $result['owner'],
            'ChatProperties' => $properties,
            'userlist' => array_values($result['userlist']),
            'officialRoster' => array_values($result['officialRoster']),
            'noUserId' => (string) $result['noUserId']
        );
    } elseif ((int) $result['code'] === 20010 && isset($result['_officialRoster']) && is_array($result['_officialRoster'])) {
        $_SESSION['continuation'][$properties] = array(
            'expiresAt' => time() + 900,
            'groupName' => $result['groupName'],
            'owner' => $course['jsh'],
            'ChatProperties' => $properties,
            'officialRoster' => array_values($result['_officialRoster'])
        );
    }
    $result['role'] = $role;
    $result['ChatProperties'] = $properties;
    echo json_encode(bridgeSanitizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'skipMissing') {
    $state = isset($_SESSION['retry'][$properties]) ? $_SESSION['retry'][$properties] : null;
    if (!$state || (int) $state['expiresAt'] < time()) {
        unset($_SESSION['retry'][$properties]);
        bridgeSend(409, '续建状态已失效，请重新点击创建课程群');
    }
    $userlist = array_values(array_filter($state['userlist'], function ($memberId) use ($state) {
        return (string) $memberId !== (string) $state['noUserId'];
    }));
    $result = bridgeCallBackground(array(
        'kind' => 'handleNoUserIdCreateClassGroupChat',
        'groupName' => $state['groupName'],
        'owner' => $state['owner'],
        'ChatProperties' => $properties,
        'userlist' => $userlist,
        'officialRoster' => $state['officialRoster']
    ));
    if ((int) $result['code'] === 60111
        && isset($result['userlist'], $result['officialRoster'], $result['noUserId'])
        && is_array($result['userlist']) && is_array($result['officialRoster'])) {
        $_SESSION['retry'][$properties] = array(
            'expiresAt' => time() + 900,
            'groupName' => $result['groupName'],
            'owner' => $result['owner'],
            'ChatProperties' => $properties,
            'userlist' => array_values($result['userlist']),
            'officialRoster' => array_values($result['officialRoster']),
            'noUserId' => (string) $result['noUserId']
        );
    } else {
        unset($_SESSION['retry'][$properties]);
    }
    $result['role'] = $role;
    $result['ChatProperties'] = $properties;
    echo json_encode(bridgeSanitizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'resolve') {
    $state = isset($_SESSION['continuation'][$properties]) ? $_SESSION['continuation'][$properties] : null;
    $decision = isset($input['decision']) ? (string) $input['decision'] : '';
    $removeOld = !empty($input['removeOld']);
    if (!$state || (int) $state['expiresAt'] < time()) {
        unset($_SESSION['continuation'][$properties]);
        bridgeSend(409, '课程群复用选择已失效，请重新点击创建');
    }
    if (!in_array($decision, array('reuse', 'new'), true)) {
        bridgeSend(422, '请选择复用旧群或新建群');
    }
    $result = bridgeCallBackground(array(
        'kind' => 'resolveCourseGroupContinuation',
        'owner' => $state['owner'],
        'groupName' => $state['groupName'],
        'ChatProperties' => $properties,
        'officialRoster' => $state['officialRoster'],
        'decision' => $decision,
        'removeOld' => $removeOld
    ));
    unset($_SESSION['continuation'][$properties]);
    if ((int) $result['code'] === 60111
        && isset($result['userlist'], $result['officialRoster'], $result['noUserId'])
        && is_array($result['userlist']) && is_array($result['officialRoster'])) {
        $_SESSION['retry'][$properties] = array(
            'expiresAt' => time() + 900,
            'groupName' => $result['groupName'],
            'owner' => $result['owner'],
            'ChatProperties' => $properties,
            'userlist' => array_values($result['userlist']),
            'officialRoster' => array_values($result['officialRoster']),
            'noUserId' => (string) $result['noUserId']
        );
    }
    $result['role'] = $role;
    $result['ChatProperties'] = $properties;
    echo json_encode(bridgeSanitizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

bridgeSend(400, '课程群操作类型无效', array(), 400);

