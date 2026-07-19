<?php
// c:\phpstudy_pro\WWW\syauhub\test_captcha_safe.php

class SyauSpiderSafe
{
    private $cookies = [];
    private $casBaseUrl = "https://pass.syau.edu.cn/tpass";
    private $serviceUrl = "http://10.2.0.47/login";
    private $captchaUrl = "https://pass.syau.edu.cn/tpass/code";

    // Config
    private $maxRetries = 4; // Safely limit to 4 as requested
    private $captchaImagePath = __DIR__ . '/captcha.jpg';
    private $pythonScript = __DIR__ . '/ocr.py';

    // Testing Flag: Intentionally fail first 2 attempts with wrong password
    private $forceCaptchaTest = false;

    public function __construct($forceTest = false)
    {
        // 1. 先检查环境，如果环境不行直接挂掉，不浪费尝试次数
        $this->checkEnvironment();

        $this->forceCaptchaTest = $forceTest;
        if ($forceTest) {
            echo ">> [测试模式已激活] 前两次尝试将故意使用错误密码，以触发验证码。\n";
        }
    }

    private function checkEnvironment()
    {
        echo "====== 环境自检 ======\n";
        if (!file_exists($this->pythonScript)) {
            die("[严重错误] 找不到 Python 脚本: {$this->pythonScript}\n");
        }

        // Check Python existence and library import
        // We run "python ocr.py --check"
        $cmd = "python " . escapeshellarg($this->pythonScript) . " --check 2>&1";
        $output = shell_exec($cmd);

        // We expect "OK" in output if successful (and no Error)
        // If import error occurs, it usually prints to stderr (captured by 2>&1)
        if (strpos($output, "OK") === false) {
            echo ">> [环境自检失败] Python 环境或 ddddocr 库存在问题。\n";
            echo ">> 详细错误信息:\n";
            echo "--------------------------------------------------\n";
            echo $output . "\n";
            echo "--------------------------------------------------\n";
            echo ">> 请先解决 Python 报错，再运行此脚本，以免浪费登录尝试次数。\n";
            exit(1);
        }
        echo ">> [环境自检通过] Python 脚本及 ddddocr 库加载正常。\n";
    }

    public function run($username, $password)
    {
        $attempt = 0;
        $success = false;

        echo "====== 开始登录流程 (最大尝试次数: {$this->maxRetries}) ======\n";

        // Initial Page Load
        $currentUrl = $this->casBaseUrl . "/login?service=" . urlencode($this->serviceUrl);
        $res = $this->request($currentUrl);

        while ($attempt < $this->maxRetries && !$success) {
            $attempt++;
            echo "\n--------------------------------------------------\n";
            echo ">> 第 {$attempt} 次尝试登录...\n";

            // Parse hidden fields from CURRENT page response
            $lt = $this->getHiddenInput($res['body'], 'lt');
            $execution = $this->getHiddenInput($res['body'], 'execution');

            if (!$lt) {
                echo "错误：无法获取 lt 参数，可能页面加载失败或已通过验证。\n";
                break;
            }

            // Check if captcha is required
            $needCaptcha = false;
            if (strpos($res['body'], 'id="mc"') !== false && strpos($res['body'], 'style="display:block;"') !== false) {
                $needCaptcha = true;
                echo ">> [系统提示] 检测到验证码输入框。\n";
            }
            if (strpos($res['body'], '请输入验证码') !== false) { // Double check text
                $needCaptcha = true;
            }

            if ($this->forceCaptchaTest && $attempt >= 3 && !$needCaptcha) {
                echo ">> [测试模式] 警告：预期第3次应有验证码，但页面未显示。\n";
            }

            // Determine Password to use
            $currentPassword = $password;
            if ($this->forceCaptchaTest && $attempt < 3) {
                $currentPassword = "wrong_password_" . time(); // Intentionally wrong
                echo ">> [测试模式] 使用错误密码进行尝试 (试图触发失败计数)...\n";
            }

            $captchaCode = "";
            if ($needCaptcha) {
                echo ">> 正在获取验证码图片...\n";
                $this->downloadCaptcha();

                echo ">> 调用 Python OCR 识别...\n";
                $captchaCode = $this->solveCaptcha();
                echo ">> 识别结果: [" . $captchaCode . "]\n";

                if (empty($captchaCode)) {
                    echo ">> 识别失败，跳过。\n";
                }
            }

            // Encrypt Data
            $resRSA = $this->request($this->casBaseUrl . "/rsa", 'POST');
            $json = json_decode($resRSA['body'], true);
            if (!$json || !isset($json['publicKey'])) {
                echo ">> 获取公钥失败，跳过本次。\n";
                continue;
            }
            $publicKey = $this->formatPublicKey($json['publicKey']);

            $ul = $this->rsaEncrypt($username, $publicKey);
            $pl = $this->rsaEncrypt($currentPassword, $publicKey);

            // POST Data
            $postData = [
                'rsa' => '',
                'ul' => $ul,
                'pl' => $pl,
                'lt' => $lt,
                'execution' => $execution,
                '_eventId' => 'submit'
            ];

            if ($needCaptcha && $captchaCode) {
                $postData['code'] = $captchaCode;
            }

            // Submit
            $headers = ['Referer: ' . $currentUrl];
            $submitUrl = $this->casBaseUrl . "/login?service=" . urlencode($this->serviceUrl);
            $res = $this->request($submitUrl, 'POST', $postData, $headers);

            // Check success
            $ticketUrl = $this->getHeaderValue($res['header'], 'Location');

            if ($ticketUrl) {
                echo ">> 登录成功！Ticket: $ticketUrl\n";
                $success = true;
                $this->request($ticketUrl); // Activate
                break;
            } else {
                echo ">> 本次尝试失败。\n";

                // Parse Error Message
                if (preg_match('/<span id="errormsg"[^>]*>(.*?)<\/span>/', $res['body'], $m)) {
                    $msg = $m[1];
                    echo ">> 错误提示: " . $msg . "\n"; // e.g. 连续登录失败8次...

                    if (preg_match('/剩余次数(\d+)/', $msg, $nMatch)) {
                        $remaining = intval($nMatch[1]);
                        echo ">> [警报] 剩余重试次数: " . $remaining . "\n";

                        if ($remaining <= 3) {
                            echo ">> [安全中止] 剩余次数过少 (" . $remaining . ")，脚本自动停止以保护账号。\n";
                            break;
                        }
                    }
                }

                sleep(2); // Wait a bit
            }
        }
    }

    private function downloadCaptcha()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->captchaUrl . "?t=" . time());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIE, $this->buildCookieCheck());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $imgData = curl_exec($ch);
        curl_close($ch);

        if ($imgData) {
            file_put_contents($this->captchaImagePath, $imgData);
            echo ">> 验证码图片已保存。\n";
        }
    }

    private function solveCaptcha()
    {
        if (!file_exists($this->captchaImagePath)) return "";
        $cmd = "python " . escapeshellarg($this->pythonScript) . " " . escapeshellarg($this->captchaImagePath);
        $output = shell_exec($cmd);
        return trim($output);
    }

    // --- Helper Methods ---

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

    private function getHiddenInput($html, $name)
    {
        if (preg_match('/name="' . $name . '" value="(.*?)"/', $html, $m)) return $m[1];
        return null;
    }

    private function getHeaderValue($headerText, $key)
    {
        if (preg_match('/^' . $key . ':\s*(.*)$/mi', $headerText, $m)) return trim($m[1]);
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

// Remove old captcha
if (file_exists(__DIR__ . '/captcha.jpg')) unlink(__DIR__ . '/captcha.jpg');

// Arguments
$username = isset($argv[1]) ? $argv[1] : "2022140101";
$password = isset($argv[2]) ? $argv[2] : "2035765141a";
$forceTest = isset($argv[3]) && $argv[3] === 'true' ? true : false;

$spider = new SyauSpiderSafe($forceTest);
$spider->run($username, $password);
