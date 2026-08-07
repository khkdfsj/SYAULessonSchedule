<?php
date_default_timezone_set('PRC');
ini_set('display_errors', 1);            //错误信息
ini_set('display_startup_errors', 1);    //php启动错误信息
error_reporting(-1);                    //打印出所有的 错误信息
header("Content-Type: application/json;charset=utf-8");

//接受参数
$receivedValue = json_decode(file_get_contents('php://input'), true);
if ($receivedValue === null) {
    echo json_encode(['code' => 404, 'msg' => '请求参数格式错误'], 256);
    exit;
}
if (empty($receivedValue["UserID"])) {
    echo json_encode(['code' => 404, 'msg' => '缺少必要参数'], 256);
    exit;
}
$UserID = $receivedValue["UserID"];

//判断身份
$ProofOfIdentity = substr($UserID, 4, 1);
switch ($ProofOfIdentity) {
    case 1:
        $Identity = '本科生';
        $GetScheduleDataLink = 'http://study.syau.edu.cn/kcbkcb/servlet/getUserCourseInfo?account=' . $UserID . '&usertype=student';
        $ScheduleData = json_decode(curl_get($GetScheduleDataLink), true);
        UndergraduateTeacherDataProcessing($ScheduleData, $UserID);
        break;
    case 5:
    case 6:
        $Identity = '教职工';
        $GetScheduleDataLink = 'http://study.syau.edu.cn/kcbkcb/servlet/getUserCourseInfo?account=' . $UserID . '&usertype=teacher';
        $ScheduleData = json_decode(curl_get($GetScheduleDataLink), true);
        UndergraduateTeacherDataProcessing($ScheduleData, $UserID);
        break;
    case 2:
        $Identity = '研究生';
        $GetScheduleDataLink = 'http://210.47.163.111/LessonSchedule/ObtainGraduateScheduleData.php?UserID=' . $UserID;
        $ScheduleData = json_decode(curl_get($GetScheduleDataLink), true);
        $ScheduleData['courseInfo'] = $ScheduleData['data'];
        ProcessingGraduateData($ScheduleData, $UserID);
        break;
    default:
        echo json_encode([
            'code' => 200,
            'msg' => '当前无课程信息，请时刻关注教务处官方信息',
            'UserID' => $UserID,
            'courseInfo' => []
        ], JSON_UNESCAPED_UNICODE);
        break;
}

function UndergraduateTeacherDataProcessing($ScheduleData, $UserID)
{
    if (!is_array($ScheduleData) || !isset($ScheduleData['courseInfo']) || !is_array($ScheduleData['courseInfo'])) {
        echo json_encode([
            'code' => 502,
            'msg' => '教务处课表数据暂不可用，请稍后重试',
            'UserID' => $UserID,
            'courseInfo' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (count($ScheduleData['courseInfo']) === 0) {
        echo json_encode([
            'code' => 200,
            'msg' => '当前无课程信息，请时刻关注教务处官方信息',
            'UserID' => $UserID,
            'courseInfo' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    foreach ($ScheduleData['courseInfo'] as $i => $course) {
        $weeks = [];
        $format = $course['zcsm'];
        // 处理周数格式 
        $weeks = handleWeekFormat($format, $weeks);

        $course = $ScheduleData['courseInfo'][$i];

        $ScheduleData['courseInfo'][$i] = [
            'id' => $i + 1,
            'name' => $course['kcm'],
            'num' => $course['kch'],
            // 课程群使用课程号和课序号共同确定唯一课程，不能只保留课程号。
            'courseOrder' => isset($course['kxh']) ? (string) $course['kxh'] : '',
            // 教学计划号中包含学年和学期，是生成新版课程群编码的必要字段。
            'planNumber' => isset($course['zxjxjhh']) ? (string) $course['zxjxjhh'] : '',
            'credit' => $course['xf'],
            'week' => $course['skxq'],
            'totalHours' => $course['xs'],
            'category' => $course['kcsxmc'],
            'CourseAttribute' => $course['xdfsmc'],
            'teacher' => $course['jsm'],
            'teacherUserID' => $course['jsh'],
            'section' => $course['skjc'],
            'sectionCount' => $course['cxjc'],
            'address' => $course['jxlm'] . '-' . $course['jash'],
            'weekText' => $course['zcsm'],
            'weeks' => array_unique($weeks),
        ];
        $ScheduleData = [
            'code' => 200,
            'msg' => '请求成功',
            'UserID' => $UserID,
            'courseInfo' => $ScheduleData['courseInfo']
        ];
    }
    echo json_encode($ScheduleData, 256);
}


function ProcessingGraduateData($ScheduleData, $UserID)
{
    if (!is_array($ScheduleData) || !isset($ScheduleData['courseInfo']) || !is_array($ScheduleData['courseInfo'])) {
        echo json_encode([
            'code' => 502,
            'msg' => '教务处课表数据暂不可用，请稍后重试',
            'UserID' => $UserID,
            'courseInfo' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (count($ScheduleData['courseInfo']) === 0) {
        echo json_encode([
            'code' => 200,
            'msg' => '当前无课程信息，请时刻关注教务处官方信息',
            'UserID' => $UserID,
            'courseInfo' => []
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    foreach ($ScheduleData['courseInfo'] as $i => $course) {
        $weeks = [];
        $format = $course['weekText'];
        // 处理周数格式 
        $weeks = handleWeekFormat($format, $weeks);

        $course = $ScheduleData['courseInfo'][$i];
        $sectionMap = [1 => 1, 2 => 3, 3 => 5, 4 => 7, 5 => 9, 6 => 11];
        $course['section'] = $sectionMap[$course['section']];
        $ScheduleData['courseInfo'][$i] = [
            'id' => $i + 1,
            'name' => $course['name'],
            'num' => $course['num'],
            // 研究生接口若提供课序号和教学计划号则原样保留；缺失时前端不会开放课程群按钮。
            'courseOrder' => isset($course['courseOrder']) ? (string) $course['courseOrder'] : (isset($course['kxh']) ? (string) $course['kxh'] : ''),
            'planNumber' => isset($course['planNumber']) ? (string) $course['planNumber'] : (isset($course['zxjxjhh']) ? (string) $course['zxjxjhh'] : ''),
            'credit' => $course['credit'],
            'week' => $course['week'],
            'totalHours' => $course['totalHour'],
            'category' => $course['category'],
            'CourseAttribute' => $course['CourseAttribute'],
            'teacher' => $course['teacher'],
            'teacherUserID' => $course['teacherUserID'],
            'section' => $course['section'],
            'sectionCount' => $course['sectionCount'],
            'address' => $course['address'],
            'weekText' => $course['weekText'],
            'weeks' => array_unique($weeks),
        ];
        $ScheduleData = [
            'code' => 0,
            'msg' => '请求成功',
            'UserID' => $UserID,
            'courseInfo' => $ScheduleData['courseInfo']
        ];
    }
    echo json_encode($ScheduleData, 256);
}

function handleWeekFormat($format, $weeks = [])
{
    // 处理 {11-18周上}、{1-5,8-18周}、{1,3,5,9,11,13,15周上}、{第20周} 和 {4-5,10周} 格式
    if (preg_match_all('/(\d+)-(\d+)|(\d+)/', $format, $matches)) {
        foreach ($matches[0] as $match) {
            if (strpos($match, '-') !== false) {
                list($start, $end) = explode('-', $match);
                for ($j = (int) $start; $j <= (int) $end; $j++) {
                    $weeks[] = $j;
                }
            } else {
                $weeks[] = (int) $match;
            }
        }
    } elseif (preg_match('/第(\d+)周/', $format, $matches)) {
        $weeks[] = (int) $matches[1];
    }

    return array_values(array_unique($weeks));
}


function postJsonUrl($url, $data)
{
    $data = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        )
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // 回调设定
    $output = curl_exec($ch);
    $output = json_decode($output, true);
    curl_close($ch);
    return $output;
}

function curl_get($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    $ret = curl_exec($curl);
    curl_close($curl);
    return $ret;
}
