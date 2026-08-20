<?php
date_default_timezone_set('PRC');
ini_set('display_errors', 1);            //错误信息
ini_set('display_startup_errors', 1);    //php启动错误信息
error_reporting(-1);                    //打印出所有的 错误信息
header("Content-Type: text/html;charset=utf-8");
$authSessionCandidates = [
    __DIR__ . '/auth_session.php',
    dirname(__DIR__) . '/auth_session.php'
];
$authSessionLoaded = false;
foreach ($authSessionCandidates as $authSessionPath) {
    if (is_file($authSessionPath)) {
        require_once $authSessionPath;
        $authSessionLoaded = true;
        break;
    }
}
if (!$authSessionLoaded) {
    http_response_code(500);
    exit('auth_session.php not found');
}
$kind = isset($_GET['kind']) ? $_GET['kind'] : '';

function normalizeLessonScheduleReturnTarget($target)
{
    $fallback = 'https://debug.91nongye.cn/LessonSchedule/';
    if (!is_string($target) || trim($target) === '') {
        return $fallback;
    }

    $parts = parse_url(trim($target));
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'debug.91nongye.cn') {
        return $fallback;
    }

    $path = $parts['path'] ?? '';
    if ($path !== '/LessonSchedule' && strpos($path, '/LessonSchedule/') !== 0) {
        return $fallback;
    }

    // H5 子页是前端路由，不是 Nginx 上的真实文件。授权完成后统一回到应用根页。
    return $fallback;
}
// 判断是否存在code
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $code = $_GET["code"]; // 取出code

    // 获取access_token
    $access_tokenFile = "http://210.47.163.113/qywx/actn_1000060.txt";
    $access_tokenResult = file_get_contents($access_tokenFile);
    if (!$access_tokenResult) {
        echo "<script>alert('access_token获取失败');window.close();</script>";
        exit;
    }
    $access_tokenData = json_decode($access_tokenResult, true);
    $access_token = $access_tokenData['access_token'];


    // 用access_token和code去换UserID
    $WORKWeChatInformationRetrievalLink = "https://qyapi.weixin.qq.com/cgi-bin/auth/getuserinfo?access_token=" . $access_token . "&code=" . $code;
    $WORKWeChatInformation = file_get_contents($WORKWeChatInformationRetrievalLink);
    if ($WORKWeChatInformation === false) {
        echo "<script>alert('无法获取用户UserID');window.close();</script>";
        exit;
    }
    $WORKWeChatInformation = json_decode($WORKWeChatInformation, true);
    $user_ticket = $WORKWeChatInformation["user_ticket"];
    $UserID = $WORKWeChatInformation["userid"];
    //查询基本信息，获取姓名，这里不使用，下面调数据库时查询姓名
    $ReadEnterpriseWeChatMemberLinks = "https://qyapi.weixin.qq.com/cgi-bin/user/get?access_token=" . $access_token . "&userid=" . $UserID;
    $EnterpriseWeChatMemberInformation = json_decode(curl_get($ReadEnterpriseWeChatMemberLinks), true);
    if ($EnterpriseWeChatMemberInformation['errcode'] !== 0) {
        echo "<script>alert('获取用户查询基本信息失败');window.close();</script>";
        exit;
    }
    $name = $EnterpriseWeChatMemberInformation['name'];

    // 用access_token和user_ticket去换敏感用户信息
    $ObtainSensitiveInformationLink = "https://qyapi.weixin.qq.com/cgi-bin/auth/getuserdetail?access_token=" . $access_token;
    $user_ticket = [
        "user_ticket" => $user_ticket
    ];

    $SensitiveInformation = postJsonUrl($ObtainSensitiveInformationLink, $user_ticket);
    if ($SensitiveInformation["errcode"] !== 0) {
        echo "<script>alert('获取个人敏感信息失败：" . $SensitiveInformation['errmsg'] . "');window.close();</script>";
        exit;
    }
    //汇总信息 
    $IdentityInformation = [
        "UserID" => $UserID,
        "name" => $name,
        "mobile" => $SensitiveInformation["mobile"],
        "avatar" => $SensitiveInformation["avatar"]
    ];

    //进行跳转  
    $authSession = issueAuthSession($UserID);
    $target = normalizeLessonScheduleReturnTarget($kind) . '?' . http_build_query([
        'UserID' => $UserID,
        'auth_exp' => $authSession['auth_exp'],
        'auth_sig' => $authSession['auth_sig']
    ]);
    header("Location: $target");
    exit();

} else {
    //发起授权  
    $action = "index.php";
    $corpid = "wxdbd5a48e19060bdf";
    $agentid = "1000060";
    $redirect_url = "https://syauinfo.syau.edu.cn/LessonSchedule/" . $action . "?kind=" . rawurlencode(normalizeLessonScheduleReturnTarget($kind));
    ;
    $code_url = "https://open.weixin.qq.com/connect/oauth2/authorize?appid=" . $corpid . "&redirect_uri=" . urlencode($redirect_url) . "&response_type=code&scope=snsapi_privateinfo&state=STATE&agentid=" . $agentid . "#wechat_redirect";
    header("Location:" . $code_url);
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
    $kind = $_GET['kind'];
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
