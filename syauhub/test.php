<?php

class FinalSyauSpider
{
    private $cookies = []; // 内存中存储 Cookie，替代文件
    private $casBaseUrl = "https://pass.syau.edu.cn/tpass";
    private $serviceUrl = "http://10.2.0.47/login";
    private $indexUrl = "http://10.2.0.47/index";
    // 注意：这里 Referer 非常关键，必须和浏览器完全一致
    private $scheduleReferer = "http://10.2.0.47/student/courseSelect/thisSemesterCurriculum/index";
    private $courseApiUrl = "http://10.2.0.47/student/courseSelect/thisSemesterCurriculum/ajaxStudentSchedule/callback";

    public function __construct()
    {
        // 无需初始化文件
    }

    public function run($username, $password)
    {
        echo "====== 1. 初始化 CAS 登录页 ======\n";
        $loginUrl = $this->casBaseUrl . "/login?service=" . urlencode($this->serviceUrl);
        $res1 = $this->request($loginUrl);

        $lt = $this->getHiddenInput($res1['body'], 'lt');
        $execution = $this->getHiddenInput($res1['body'], 'execution');
        if (!$lt) die("错误：无法连接 CAS 服务器或获取 lt 参数。\n");

        echo "====== 2. 获取 RSA 公钥并加密 ======\n";
        $res2 = $this->request($this->casBaseUrl . "/rsa", 'POST');
        $json = json_decode($res2['body'], true);
        $publicKey = $this->formatPublicKey($json['publicKey']);

        $ul = $this->rsaEncrypt($username, $publicKey);
        $pl = $this->rsaEncrypt($password, $publicKey);

        echo "====== 3. 提交登录 (获取 Ticket) ======\n";
        $postData = [
            'rsa' => '',
            'ul' => $ul,
            'pl' => $pl,
            'lt' => $lt,
            'execution' => $execution,
            '_eventId' => 'submit'
        ];
        // 这一步必须带 Referer
        $headers = ['Referer: ' . $loginUrl];
        $res3 = $this->request($loginUrl, 'POST', $postData, $headers);

        // 检查是否拿到了 Location 跳转 (即 Ticket)
        $ticketUrl = $this->getHeaderValue($res3['header'], 'Location');

        if (!$ticketUrl) {
            echo "登录失败！停留在登录页。\n";
            // 尝试打印错误
            if (preg_match('/<span id="errormsg"[^>]*>(.*?)<\/span>/', $res3['body'], $m)) {
                echo "错误提示: " . $m[1] . "\n";
            }
            exit;
        }

        echo ">> 拿到 Ticket 跳转地址: " . $ticketUrl . "\n";

        echo "====== 4. [关键] 手动验证 Ticket ======\n";
        // 这一步去访问 10.2.0.47，它应该设置 JSESSIONID 并跳转到 index
        // 我们不自动跳转，看看发生了什么
        $res4 = $this->request($ticketUrl, 'GET');

        echo ">> 验证响应代码: " . $res4['info']['http_code'] . "\n";

        // 打印 Set-Cookie 看看有没有 JSESSIONID
        // preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $res4['header'], $cookies);
        // echo ">> 服务器下发的 Cookie: " . implode(", ", $cookies[1]) . "\n";
        echo ">> 当前内存 Cookie: " . json_encode($this->cookies) . "\n";

        // 如果是 302，说明成功，继续跳到 index
        if ($res4['info']['http_code'] == 302) {
            $nextUrl = $this->getHeaderValue($res4['header'], 'Location');
            echo ">> 验证成功！准备跳转到: $nextUrl\n";

            // 访问 Index (激活 Session)
            $this->request($nextUrl);
            echo ">> 已访问 Index 页面，Session 激活完成。\n";

            // 开始获取课表
            $this->getSchedule();
        } elseif ($res4['info']['http_code'] == 200) {
            echo ">> [错误] 验证 Ticket 失败，服务器返回了 200 OK 而不是跳转。\n";
            echo ">> 页面内容摘要: " . substr(strip_tags($res4['body']), 0, 200) . "\n";
            // 有时候虽然是 200，但可能是 meta 跳转或者 JS 跳转，我们需要看 body
            if (strpos($res4['body'], 'window.location') !== false) {
                echo ">> 警告：检测到 JavaScript 跳转，cURL 无法自动处理。\n";
            }
        } else {
            echo ">> [错误] 未知响应: " . $res4['info']['http_code'] . "\n";
        }
    }

    public function getSchedule()
    {
        echo "\n====== 5. 获取课表数据 ======\n";
        $headers = [
            'Referer: ' . $this->scheduleReferer,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/javascript, */*; q=0.01'
        ];

        $res = $this->request($this->courseApiUrl, 'GET', [], $headers);

        echo ">> 课表接口响应代码: " . $res['info']['http_code'] . "\n";
        if (strlen($res['body']) < 50) {
            echo ">> 响应内容过短 (可能为空): [" . $res['body'] . "]\n";
        } else {
            // 尝试解析 JSON
            $data = json_decode($res['body'], true);
            if ($data) {
                echo ">> 成功获取 JSON 数据！\n";
                // 简单的展示第一节课
                if (isset($data['dateList'][0]['selectCourseList'][0])) {
                    echo ">> 第一门课: " . $data['dateList'][0]['selectCourseList'][0]['courseName'] . "\n";
                }
                echo ">> 原始数据长度: " . strlen($res['body']) . " 字节\n";
            } else {
                echo ">> 获取到了数据，但不是 JSON (可能是 HTML 报错页):\n";
                echo substr($res['body'], 0, 300) . "...\n";
            }
        }
    }

    // --- 核心工具 (禁用自动跳转，手动管理) ---
    private function request($url, $method = 'GET', $postData = [], $customHeaders = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1); // 必须开启头部信息，我们需要解析 Location 和 Set-Cookie

        // 发送 Cookie (内存中)
        if (!empty($this->cookies)) {
            $cookieStr = '';
            foreach ($this->cookies as $k => $v) {
                // 简单的 Key=Value 拼接
                $cookieStr .= $k . '=' . $v . '; ';
            }
            // 移除最后的 '; '
            if ($cookieStr) {
                curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
            }
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0); // 【重要】禁用自动跳转，手动控制

        $defaultHeaders = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $customHeaders));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // 解析 Set-Cookie 并存入内存
        if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $header, $matches)) {
            foreach ($matches[1] as $item) {
                $parts = explode('=', $item, 2);
                if (count($parts) == 2) {
                    $k = trim($parts[0]);
                    $v = trim($parts[1]);
                    $this->cookies[$k] = $v;
                }
            }
        }

        return ['header' => $header, 'body' => $body, 'info' => $info];
    }

    private function getHeaderValue($headerText, $key)
    {
        if (preg_match('/^' . $key . ':\s*(.*)$/mi', $headerText, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function getHiddenInput($html, $name)
    {
        if (preg_match('/name="' . $name . '" value="(.*?)"/', $html, $m)) return $m[1];
        return null;
    }

    private function formatPublicKey($rawKey)
    {
        if (strpos($rawKey, '-----') !== false) return $rawKey;
        return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($rawKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }

    private function rsaEncrypt($data, $publicKey)
    {
        $encrypted = '';
        openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
        return base64_encode($encrypted);
    }
}

// 运行
$spider = new FinalSyauSpider();
$spider->run("2022140101", "2035765141a");
