<?php
// c:\phpstudy_pro\WWW\syauhub\test_captcha.php

class SyauSpiderWithCaptcha
{
    private $cookies = []; // 内存中存储 Cookie
    private $casBaseUrl = "https://pass.syau.edu.cn/tpass";
    private $serviceUrl = "http://10.2.0.47/login";

    // Captcha endpoint provided by user
    private $captchaUrl = "https://pass.syau.edu.cn/tpass/code";

    // Config
    private $maxRetries = 3;  // Maximum login attempts to avoid ban
    private $captchaImagePath = __DIR__ . '/captcha.jpg';
    private $pythonScript = __DIR__ . '/ocr.py';

    public function run($username, $password)
    {
        $attempt = 0;
        $success = false;

        echo "====== 开始登录流程 (最大尝试次数: {$this->maxRetries}) ======\n";

        // Initial check: load login page to get execution/lt and check if captcha is required initially
        $loginUrl = $this->casBaseUrl . "/login?service=" . urlencode($this->serviceUrl);
        $res1 = $this->request($loginUrl);

        $lt = $this->getHiddenInput($res1['body'], 'lt');
        $execution = $this->getHiddenInput($res1['body'], 'execution');

        if (!$lt) die("错误：无法连接 CAS 服务器或获取 lt 参数 (Attempt $attempt)。\n");

        // Loop for attempts
        while ($attempt < $this->maxRetries && !$success) {
            $attempt++;
            echo "\n--------------------------------------------------\n";
            echo ">> 第 {$attempt} 次尝试登录...\n";

            // Check if captcha is required based on the response body of the PREVIOUS request
            // User provided HTML: <div class="code_row" style="display:block;" id="mc">
            // If display:block or just present without display:none, we need captcha.
            // Also sometimes "请输入验证码" text is a good indicator.
            $needCaptcha = false;

            // Simple check: does "id=\"mc\"" exist and not have "display:none"?
            // Or look for "请输入验证码"
            if (strpos($res1['body'], 'id="mc"') !== false && strpos($res1['body'], 'style="display:block;"') !== false) {
                $needCaptcha = true;
                echo ">> 检测到需要验证码！\n";
            } else {
                echo ">> 暂时不需要验证码。\n";
            }

            // Force captcha on 3rd attempt if not detected, just in case logic is server-side 
            // (User said "3rd time appears", so maybe after 2 failures)
            if ($attempt >= 3) {
                $needCaptcha = true;
                echo ">> 已达到第3次尝试，强制启用验证码流程。\n";
            }

            $captchaCode = "";
            if ($needCaptcha) {
                echo ">> 正在获取验证码图片...\n";
                // Download captcha
                $this->downloadCaptcha();

                // Solve captcha
                echo ">> 调用 Python OCR 识别验证码...\n";
                $captchaCode = $this->solveCaptcha();
                echo ">> 识别结果: [" . $captchaCode . "]\n";

                if (empty($captchaCode)) {
                    echo ">> 验证码识别失败，跳过本次尝试。\n";
                    continue;
                }
            }

            // Prepare Login Data
            // We need to re-fetch RSA key every time? Usually yes.
            echo ">> 获取 RSA 公钥并加密...\n";
            $res2 = $this->request($this->casBaseUrl . "/rsa", 'POST');
            $json = json_decode($res2['body'], true);
            if (!$json || !isset($json['publicKey'])) {
                echo ">> 获取公钥失败，重试...\n";
                continue;
            }
            $publicKey = $this->formatPublicKey($json['publicKey']);

            $ul = $this->rsaEncrypt($username, $publicKey);
            $pl = $this->rsaEncrypt($password, $publicKey);

            // POST Data
            $postData = [
                'rsa' => '',
                'ul' => $ul,
                'pl' => $pl,
                'lt' => $lt,
                'execution' => $execution,
                '_eventId' => 'submit'
            ];

            if ($needCaptcha) {
                $postData['code'] = $captchaCode;
            }

            // Submit
            echo ">> 提交登录表单...\n";
            $headers = ['Referer: ' . $loginUrl];
            $res3 = $this->request($loginUrl, 'POST', $postData, $headers);

            // Check success (Location header)
            $ticketUrl = $this->getHeaderValue($res3['header'], 'Location');

            if ($ticketUrl) {
                echo ">> 登录成功！Ticket URL: $ticketUrl\n";
                $success = true;

                // Follow the ticket to activate session
                $this->request($ticketUrl);
                echo ">> Session 激活完成。\n";
                break;
            } else {
                echo ">> 登录失败。\n";
                // Analyze error
                if (preg_match('/<span id="errormsg"[^>]*>(.*?)<\/span>/', $res3['body'], $m)) {
                    echo ">> 错误提示: " . $m[1] . "\n";

                    // If error is related to captcha, we need to make sure next attempt uses it
                    // The response $res3 body will be the new page, which might have the captcha displayed
                    // Update $res1 for next loop iteration check
                    $res1 = $res3;

                    // Update lt/execution for next attempt from the response
                    $lt = $this->getHiddenInput($res3['body'], 'lt');
                    $execution = $this->getHiddenInput($res3['body'], 'execution');
                }

                // Safety break
                if ($attempt >= 8) {
                    echo ">> [警告] 已达到8次尝试限制，停止以防止封号。\n";
                    break;
                }
            }

            // Small delay
            sleep(1);
        }
    }

    private function downloadCaptcha()
    {
        // Request the captcha image
        // Must use the same cookies
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->captchaUrl . "?t=" . time()); // timestamp to avoid cache
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIE, $this->buildCookieCheck());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $imgData = curl_exec($ch);
        curl_close($ch);

        if ($imgData) {
            file_put_contents($this->captchaImagePath, $imgData);
            echo ">> 验证码图片已保存。\n";
        } else {
            echo ">> 获取验证码图片失败。\n";
        }
    }

    private function solveCaptcha()
    {
        if (!file_exists($this->captchaImagePath)) return "";

        // Call Python script
        // Note: Check if 'python' or 'python3' is in path
        $cmd = "python " . escapeshellarg($this->pythonScript) . " " . escapeshellarg($this->captchaImagePath);
        $output = shell_exec($cmd);
        return trim($output);
    }

    // --- Helper Methods (Same as before) ---

    private function request($url, $method = 'GET', $postData = [], $customHeaders = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        $cookieStr = $this->buildCookieCheck();
        if ($cookieStr) curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);

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

        // Parse cookies
        if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $header, $matches)) {
            foreach ($matches[1] as $item) {
                $parts = explode('=', $item, 2);
                if (count($parts) == 2) {
                    $this->cookies[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        return ['header' => $header, 'body' => $body, 'info' => $info];
    }

    private function buildCookieCheck()
    {
        if (empty($this->cookies)) return '';
        $str = '';
        foreach ($this->cookies as $k => $v) $str .= "$k=$v; ";
        return rtrim($str, '; ');
    }

    private function getHeaderValue($headerText, $key)
    {
        if (preg_match('/^' . $key . ':\s*(.*)$/mi', $headerText, $m)) return trim($m[1]);
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

// Ensure clean start
if (file_exists(__DIR__ . '/captcha.jpg')) unlink(__DIR__ . '/captcha.jpg');

// Run with arguments or hardcoded logic
// NOTE: Please replace with valid credentials for testing if needed, or pass via command line
// Usage: php test_captcha.php [username] [password]
$username = isset($argv[1]) ? $argv[1] : "2022140101";
$password = isset($argv[2]) ? $argv[2] : "2035765141a";

$spider = new SyauSpiderWithCaptcha();
$spider->run($username, $password);
