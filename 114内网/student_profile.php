<?php

date_default_timezone_set('PRC');
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

const SZAU_SIMPLE_OCR_SCRIPT = __DIR__ . DIRECTORY_SEPARATOR . 'ocr.py';

try {
    if (PHP_SAPI !== 'cli') {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            respond(405, 'method not allowed', ['allowed_method' => 'POST']);
        }
    }

    $payload = readJsonPayload();
    $service = new SyauProfileService(SZAU_SIMPLE_OCR_SCRIPT);
    $result = $service->handle($payload, ['SyauProfileService', 'buildSimpleProfile']);
    respond($result['code'], $result['message'], $result['data']);
} catch (Throwable $exception) {
    respond(502, 'upstream service unavailable', [
        'error' => $exception->getMessage(),
    ]);
}

function readJsonPayload()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' && PHP_SAPI === 'cli') {
        $raw = stream_get_contents(STDIN);
    }

    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '') {
        respond(400, 'request body is required');
    }

    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
        respond(400, 'invalid json body');
    }

    return $payload;
}

function respond($code, $message, array $data = [])
{
    if (PHP_SAPI !== 'cli') {
        http_response_code((int) $code);
    }

    echo json_encode([
        'code' => (int) $code,
        'message' => (string) $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class SyauProfileService
{
    private $store;
    private $ocrScriptPath;

    public function __construct($ocrScriptPath, SyauCaptchaStore $store = null)
    {
        $this->ocrScriptPath = $ocrScriptPath;
        $this->store = $store ?: new SyauCaptchaStore();
    }

    public function handle(array $payload, callable $profileTransformer = null)
    {
        $this->store->cleanupExpired();

        $rawCaptchaToken = isset($payload['captcha_token']) ? $payload['captcha_token'] : null;
        $rawCaptchaCode = isset($payload['captcha_code']) ? $payload['captcha_code'] : null;
        $account = $this->normalizeAccount(isset($payload['account']) ? $payload['account'] : '');
        $password = $this->normalizePassword(isset($payload['password']) ? $payload['password'] : '');
        $enableOcr = isset($payload['enable_ocr']) && $payload['enable_ocr'] === true;
        $captchaToken = $this->normalizeCaptchaToken($rawCaptchaToken);
        $captchaCode = $this->normalizeCaptchaCode($rawCaptchaCode);

        if ($account === '') {
            return $this->makeResponse(400, 'account is required');
        }

        if ($password === '') {
            return $this->makeResponse(400, 'password is required');
        }

        if ($rawCaptchaToken !== null && trim((string) $rawCaptchaToken) !== '' && $captchaToken === '') {
            return $this->makeResponse(400, 'invalid captcha_token format');
        }

        if ($rawCaptchaCode !== null && trim((string) $rawCaptchaCode) !== '' && $captchaCode === '') {
            return $this->makeResponse(400, 'invalid captcha_code format');
        }

        $attemptState = $this->store->getAccountAttemptState($account);
        if (!empty($attemptState['is_blocked'])) {
            return $this->makeResponse(429, $this->buildAttemptProtectionMessage(), $this->buildAttemptData($attemptState));
        }

        if ($captchaToken !== '') {
            return $this->handleCaptchaFlow($account, $password, $captchaToken, $captchaCode, $enableOcr, $profileTransformer);
        }

        return $this->handleInitialLogin($account, $password, $profileTransformer);
    }

    public static function buildSimpleProfile(array $profile)
    {
        return [
            'student_number' => isset($profile['academic_info']['student_number']) ? $profile['academic_info']['student_number'] : '',
            'full_name' => isset($profile['academic_info']['full_name']) ? $profile['academic_info']['full_name'] : '',
            'grade' => isset($profile['academic_info']['grade']) ? $profile['academic_info']['grade'] : '',
            'department' => isset($profile['academic_info']['department']) ? $profile['academic_info']['department'] : '',
            'major' => isset($profile['academic_info']['major']) ? $profile['academic_info']['major'] : '',
            'admission_grade' => isset($profile['academic_info']['admission_grade']) ? $profile['academic_info']['admission_grade'] : '',
            'schooling_type' => isset($profile['academic_info']['schooling_type']) ? $profile['academic_info']['schooling_type'] : '',
            'gender' => isset($profile['admission_info']['gender']) ? $profile['admission_info']['gender'] : '',
            'ethnicity' => isset($profile['admission_info']['ethnicity']) ? $profile['admission_info']['ethnicity'] : '',
            'id_number' => isset($profile['academic_info']['id_number']) ? $profile['academic_info']['id_number'] : '',
        ];
    }

    private function handleInitialLogin($account, $password, callable $profileTransformer = null)
    {
        $client = new SyauCasClient();
        $state = $client->bootstrapLogin();
        $submitResult = $client->submitLogin($account, $password, $state['lt'], $state['execution']);

        if ($submitResult['success']) {
            $this->store->clearFailedAttempts($account);
            $profile = $this->fetchProfile($client, $submitResult['ticket_url'], $profileTransformer);
            return $this->makeResponse(200, 'ok', [
                'account' => $account,
                'profile' => $profile,
            ]);
        }

        $message = $submitResult['message'] !== '' ? $submitResult['message'] : 'authentication failed';
        $remainingAttempts = isset($submitResult['remaining_attempts']) ? $submitResult['remaining_attempts'] : null;
        $attemptState = $this->store->recordFailedAttempt($account, $message);
        $nextState = $client->exportState(
            $submitResult['lt'] !== '' ? $submitResult['lt'] : $state['lt'],
            $submitResult['execution'] !== '' ? $submitResult['execution'] : $state['execution']
        );

        if ($submitResult['requires_captcha']) {
            return $this->buildCaptchaChallengeResponse($client, $account, $nextState, $message, false, [
                'remaining_attempts' => $remainingAttempts,
                'local_failure_count' => $attemptState['count'],
                'local_remaining_attempts' => $attemptState['remaining_attempts'],
                'local_protection_active' => $attemptState['is_blocked'],
                'local_retry_after' => $attemptState['retry_after'],
                'local_protection_message' => $attemptState['is_blocked'] ? $this->buildAttemptProtectionMessage() : null,
            ]);
        }

        $data = $this->buildAttemptData($attemptState);
        if ($remainingAttempts !== null) {
            $data['remaining_attempts'] = $remainingAttempts;
        }

        if ($this->isAccountLockedMessage($message)) {
            $data['account_locked'] = true;
            return $this->makeResponse(423, $message, $data);
        }

        return $this->makeResponse(401, $message, $data);
    }

    private function handleCaptchaFlow($account, $password, $captchaToken, $captchaCode, $enableOcr, callable $profileTransformer = null)
    {
        $challenge = $this->store->readChallenge($captchaToken);
        if (!is_array($challenge)) {
            return $this->makeResponse(400, 'invalid or expired captcha_token');
        }

        if (!hash_equals((string) $challenge['account'], $account)) {
            $this->store->deleteChallenge($captchaToken);
            return $this->makeResponse(400, 'captcha_token does not match account');
        }

        if ($captchaCode === '' && !$enableOcr) {
            return $this->makeResponse(400, 'captcha_code is required when enable_ocr is false');
        }

        $client = new SyauCasClient(isset($challenge['state']) ? $challenge['state'] : []);
        $ocrAttempted = false;

        if ($captchaCode === '' && $enableOcr) {
            $ocrAttempted = true;
            $captchaImage = $client->fetchCaptchaImage();
            $captchaCode = $this->runCaptchaOcr($captchaImage['bytes'], $captchaImage['mime_type']);
            if ($captchaCode === '') {
                $state = isset($challenge['state']) && is_array($challenge['state']) ? $challenge['state'] : [];
                $state = $client->exportState(
                    isset($state['lt']) ? $state['lt'] : '',
                    isset($state['execution']) ? $state['execution'] : ''
                );
                $this->store->deleteChallenge($captchaToken);
                return $this->buildCaptchaChallengeResponse(
                    $client,
                    $account,
                    $state,
                    'ocr failed to recognize captcha',
                    true
                );
            }
        }

        $state = isset($challenge['state']) && is_array($challenge['state']) ? $challenge['state'] : [];
        $lt = isset($state['lt']) ? (string) $state['lt'] : '';
        $execution = isset($state['execution']) ? (string) $state['execution'] : '';
        if ($lt === '' || $execution === '') {
            $this->store->deleteChallenge($captchaToken);
            return $this->makeResponse(400, 'captcha_token is missing session state');
        }

        $submitResult = $client->submitLogin($account, $password, $lt, $execution, $captchaCode);
        $this->store->deleteChallenge($captchaToken);

        if ($submitResult['success']) {
            $this->store->clearFailedAttempts($account);
            $profile = $this->fetchProfile($client, $submitResult['ticket_url'], $profileTransformer);
            return $this->makeResponse(200, 'ok', [
                'account' => $account,
                'profile' => $profile,
            ]);
        }

        $message = $submitResult['message'] !== '' ? $submitResult['message'] : 'authentication failed';
        $remainingAttempts = isset($submitResult['remaining_attempts']) ? $submitResult['remaining_attempts'] : null;
        $attemptState = $this->store->recordFailedAttempt($account, $message);
        $nextState = $client->exportState(
            $submitResult['lt'] !== '' ? $submitResult['lt'] : $lt,
            $submitResult['execution'] !== '' ? $submitResult['execution'] : $execution
        );

        if ($submitResult['requires_captcha']) {
            return $this->buildCaptchaChallengeResponse($client, $account, $nextState, $message, $ocrAttempted, [
                'remaining_attempts' => $remainingAttempts,
                'local_failure_count' => $attemptState['count'],
                'local_remaining_attempts' => $attemptState['remaining_attempts'],
                'local_protection_active' => $attemptState['is_blocked'],
                'local_retry_after' => $attemptState['retry_after'],
                'local_protection_message' => $attemptState['is_blocked'] ? $this->buildAttemptProtectionMessage() : null,
            ]);
        }

        $data = $this->buildAttemptData($attemptState);
        if ($remainingAttempts !== null) {
            $data['remaining_attempts'] = $remainingAttempts;
        }

        if ($this->isAccountLockedMessage($message)) {
            $data['account_locked'] = true;
            return $this->makeResponse(423, $message, $data);
        }

        return $this->makeResponse(401, $message, $data);
    }

    private function fetchProfile(SyauCasClient $client, $ticketUrl, callable $profileTransformer = null)
    {
        $profilePage = $client->activateSessionAndFetchProfile($ticketUrl);
        $parser = new SyauProfileParser();
        $profile = $parser->parse($profilePage['html']);

        if (!$this->hasAnyProfileValue($profile)) {
            throw new RuntimeException('failed to parse profile page');
        }

        if ($profileTransformer !== null) {
            $profile = call_user_func($profileTransformer, $profile);
        }

        return $profile;
    }

    private function buildCaptchaChallengeResponse(
        SyauCasClient $client,
        $account,
        array $state,
        $message,
        $ocrAttempted,
        array $extraData = []
    ) {
        $captchaImage = $client->fetchCaptchaImage();
        $challengeState = $client->exportState(
            isset($state['lt']) ? $state['lt'] : '',
            isset($state['execution']) ? $state['execution'] : ''
        );
        $challenge = $this->store->createChallenge($account, $challengeState, $this->store->getDefaultTtl());

        $data = [
            'requires_captcha' => true,
            'captcha_token' => $challenge['token'],
            'captcha_image_base64' => base64_encode($captchaImage['bytes']),
            'captcha_image_mime_type' => $captchaImage['mime_type'],
            'captcha_expires_in' => $challenge['expires_in'],
            'ocr_attempted' => (bool) $ocrAttempted,
        ];

        foreach ($extraData as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $this->makeResponse(409, $message !== '' ? $message : 'captcha required', $data);
    }

    private function runCaptchaOcr($imageBytes, $mimeType)
    {
        if (!is_file($this->ocrScriptPath)) {
            throw new RuntimeException('ocr script not found');
        }

        $path = $this->store->createCaptchaTempFile($imageBytes, $mimeType);
        try {
            $command = 'python ' . escapeshellarg($this->ocrScriptPath) . ' ' . escapeshellarg($path) . ' 2>&1';
            $output = shell_exec($command);
            $result = trim((string) $output);
            $result = preg_replace('/\s+/u', '', $result);
            if ($result === '' || stripos($result, 'ERROR') === 0) {
                return '';
            }

            return $result;
        } finally {
            $this->store->deleteFile($path);
        }
    }

    private function hasAnyProfileValue(array $profile)
    {
        foreach ($profile as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach ($section as $value) {
                if ((string) $value !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAccountLockedMessage($message)
    {
        if (!is_string($message) || $message === '') {
            return false;
        }

        if (mb_strpos($message, '将被锁定') !== false && mb_strpos($message, '剩余次数') !== false) {
            return false;
        }

        return mb_strpos($message, '已锁定') !== false
            || mb_strpos($message, '已被锁定') !== false
            || (mb_strpos($message, '锁定') !== false && mb_strpos($message, '剩余次数') === false);
    }

    private function normalizeAccount($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $account = trim((string) $value);
        if ($account === '' || strlen($account) > 64 || preg_match('/\s/u', $account)) {
            return '';
        }

        return $account;
    }

    private function normalizePassword($value)
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function normalizeCaptchaToken($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $token = trim($value);
        return preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '';
    }

    private function normalizeCaptchaCode($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $code = preg_replace('/\s+/u', '', trim((string) $value));
        if ($code === '' || strlen($code) > 16) {
            return '';
        }

        return $code;
    }

    private function makeResponse($code, $message, array $data = [])
    {
        return [
            'code' => (int) $code,
            'message' => (string) $message,
            'data' => $data,
        ];
    }

    private function buildAttemptProtectionMessage()
    {
        return '为保护账号安全，本接口已检测到同一账号连续失败4次，已暂停继续向学校认证系统发起登录请求，请10分钟后再试。';
    }

    private function buildAttemptData(array $attemptState)
    {
        $data = [
            'local_failure_count' => isset($attemptState['count']) ? (int) $attemptState['count'] : 0,
            'local_remaining_attempts' => isset($attemptState['remaining_attempts']) ? (int) $attemptState['remaining_attempts'] : $this->store->getAttemptLimit(),
            'local_protection_active' => !empty($attemptState['is_blocked']),
            'local_retry_after' => isset($attemptState['retry_after']) ? (int) $attemptState['retry_after'] : 0,
        ];

        if (!empty($attemptState['is_blocked'])) {
            $data['local_protection_message'] = $this->buildAttemptProtectionMessage();
        }

        return $data;
    }
}

class SyauCasClient
{
    private $cookies = [];
    private $casBaseUrl = 'https://pass.syau.edu.cn/tpass';
    private $serviceUrl = 'http://10.2.0.47/login';
    private $profileUrl = 'http://10.2.0.47/student/rollManagement/rollInfo/index';
    private $indexUrl = 'http://10.2.0.47/index';
    private $defaultTimeout = 20;

    public function __construct(array $state = [])
    {
        if (isset($state['cookies']) && is_array($state['cookies'])) {
            $this->cookies = $state['cookies'];
        }
    }

    public function bootstrapLogin()
    {
        $loginUrl = $this->getLoginUrl();
        $response = $this->request($loginUrl, 'GET');
        $this->assertResponseOk($response, 'failed to load login page');

        $lt = $this->extractHiddenInput($response['body'], 'lt');
        $execution = $this->extractHiddenInput($response['body'], 'execution');
        if ($lt === '' || $execution === '') {
            throw new RuntimeException('failed to parse login page');
        }

        return [
            'login_url' => $loginUrl,
            'lt' => $lt,
            'execution' => $execution,
            'cookies' => $this->cookies,
        ];
    }

    public function submitLogin($account, $password, $lt, $execution, $captchaCode = '')
    {
        $publicKey = $this->fetchPublicKey();
        $payload = [
            'rsa' => '',
            'ul' => $this->rsaEncrypt($account, $publicKey),
            'pl' => $this->rsaEncrypt($password, $publicKey),
            'lt' => (string) $lt,
            'execution' => (string) $execution,
            '_eventId' => 'submit',
        ];

        if ($captchaCode !== '') {
            $payload['code'] = $captchaCode;
        }

        $loginUrl = $this->getLoginUrl();
        $response = $this->request($loginUrl, 'POST', $payload, [
            'Referer: ' . $loginUrl,
            'Origin: https://pass.syau.edu.cn',
        ]);
        $this->assertResponseOk($response, 'failed to submit login form');

        $ticketUrl = $this->getHeaderValue($response['header'], 'Location');
        if ($ticketUrl !== '') {
            $ticketUrl = $this->resolveUrl($loginUrl, $ticketUrl);
        }

        $message = $this->extractErrorMessage($response['body']);
        $ltNext = $this->extractHiddenInput($response['body'], 'lt');
        $executionNext = $this->extractHiddenInput($response['body'], 'execution');

        return [
            'success' => $ticketUrl !== '',
            'ticket_url' => $ticketUrl,
            'message' => $message,
            'requires_captcha' => $this->detectCaptchaRequired($response['body'], $message),
            'remaining_attempts' => $this->extractRemainingAttempts($message),
            'lt' => $ltNext,
            'execution' => $executionNext,
            'http_code' => isset($response['info']['http_code']) ? (int) $response['info']['http_code'] : 0,
            'body' => $response['body'],
            'cookies' => $this->cookies,
            'login_url' => $loginUrl,
        ];
    }

    public function activateSessionAndFetchProfile($ticketUrl)
    {
        $this->followRedirectChain($ticketUrl, 8);

        $profileResponse = $this->request($this->profileUrl, 'GET', [], [
            'Referer: ' . $this->indexUrl,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        $this->assertResponseOk($profileResponse, 'failed to fetch profile page');

        if ($this->isLikelyLoginPage($profileResponse['body'])) {
            throw new RuntimeException('session activation failed');
        }

        return [
            'html' => $profileResponse['body'],
            'cookies' => $this->cookies,
        ];
    }

    public function fetchCaptchaImage()
    {
        $captchaUrl = $this->casBaseUrl . '/code?t=' . rawurlencode((string) round(microtime(true) * 1000));
        $response = $this->request($captchaUrl, 'GET', [], [
            'Referer: ' . $this->getLoginUrl(),
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        ]);
        $this->assertResponseOk($response, 'failed to fetch captcha image');

        if (!isset($response['body']) || $response['body'] === '') {
            throw new RuntimeException('empty captcha image');
        }

        return [
            'bytes' => $response['body'],
            'mime_type' => $this->detectMimeType($response['body']),
            'cookies' => $this->cookies,
        ];
    }

    public function exportState($lt, $execution)
    {
        return [
            'login_url' => $this->getLoginUrl(),
            'lt' => (string) $lt,
            'execution' => (string) $execution,
            'cookies' => $this->cookies,
        ];
    }

    public function extractRemainingAttempts($message)
    {
        if (!is_string($message) || $message === '') {
            return null;
        }

        if (preg_match('/剩余次数\s*(\d+)/u', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function isLikelyLoginPage($html)
    {
        if (!is_string($html) || $html === '') {
            return false;
        }

        return strpos($html, 'id="loginForm"') !== false
            || strpos($html, '统一身份认证') !== false
            || strpos($html, '立即登录') !== false;
    }

    public function detectCaptchaRequired($html, $message = '')
    {
        if (is_string($message) && $message !== '' && mb_strpos($message, '验证码') !== false) {
            return true;
        }

        $visibleHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', (string) $html);
        $visibleHtml = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $visibleHtml);

        $patterns = [
            '/id=["\']mc["\']/i',
            '/请输入验证码/u',
            '/图形验证码/u',
            '/验证码/u',
            '/<img[^>]+src=["\'][^"\']*\/code(?:\?|["\'])/i',
            '/<input[^>]+name=["\']code["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $visibleHtml)) {
                return true;
            }
        }

        return false;
    }

    private function getLoginUrl()
    {
        return $this->casBaseUrl . '/login?service=' . urlencode($this->serviceUrl);
    }

    private function fetchPublicKey()
    {
        $response = $this->request($this->casBaseUrl . '/rsa', 'POST', [], [
            'Referer: ' . $this->getLoginUrl(),
            'Origin: https://pass.syau.edu.cn',
        ]);
        $this->assertResponseOk($response, 'failed to fetch rsa key');

        $json = json_decode($response['body'], true);
        if (!is_array($json) || empty($json['publicKey'])) {
            throw new RuntimeException('invalid rsa public key response');
        }

        $rawKey = (string) $json['publicKey'];
        if (strpos($rawKey, '-----BEGIN PUBLIC KEY-----') !== false) {
            return $rawKey;
        }

        return "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($rawKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
    }

    private function rsaEncrypt($value, $publicKey)
    {
        $encrypted = '';
        $ok = openssl_public_encrypt((string) $value, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
        if (!$ok) {
            throw new RuntimeException('rsa encryption failed');
        }

        return base64_encode($encrypted);
    }

    private function followRedirectChain($url, $maxSteps)
    {
        $currentUrl = $url;
        $steps = 0;

        while ($currentUrl !== '' && $steps < $maxSteps) {
            $response = $this->request($currentUrl, 'GET');
            $this->assertResponseOk($response, 'failed to follow redirect chain');

            $httpCode = isset($response['info']['http_code']) ? (int) $response['info']['http_code'] : 0;
            if (!in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $this->getHeaderValue($response['header'], 'Location');
            if ($location === '') {
                return $response;
            }

            $currentUrl = $this->resolveUrl($currentUrl, $location);
            $steps++;
        }

        return null;
    }

    private function request($url, $method = 'GET', array $postData = [], array $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->defaultTimeout);

        if (!empty($this->cookies)) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->buildCookieHeader());
        }

        $defaultHeaders = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept-Language: zh-CN,zh;q=0.9',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'error' => $error ?: ('curl error #' . $errno),
                'info' => $info,
                'header' => '',
                'body' => '',
            ];
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $this->storeCookiesFromHeader($header);

        return [
            'ok' => true,
            'error' => '',
            'info' => $info,
            'header' => $header,
            'body' => $body,
        ];
    }

    private function assertResponseOk(array $response, $message)
    {
        if (empty($response['ok'])) {
            throw new RuntimeException($message . ': ' . ($response['error'] ?: 'unknown error'));
        }
    }

    private function buildCookieHeader()
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    private function storeCookiesFromHeader($header)
    {
        if (!is_string($header) || $header === '') {
            return;
        }

        if (preg_match_all('/^Set-Cookie:\s*([^=;,\s]+)=([^;]*)/mi', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->cookies[$match[1]] = $match[2];
            }
        }
    }

    private function getHeaderValue($header, $key)
    {
        if (!is_string($header) || $header === '') {
            return '';
        }

        if (preg_match('/^' . preg_quote($key, '/') . ':\s*(.+)$/mi', $header, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function extractHiddenInput($html, $name)
    {
        if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/i', (string) $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    private function extractErrorMessage($html)
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $text = $this->normalizeMessageText(strip_tags($html));
        foreach ([
            '/连续登录失败\d+次[^。；<>\r\n]*剩余次数\s*\d+/u',
            '/账号(?:已被)?锁定[^。；<>\r\n]*/u',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[0]);
            }
        }

        if (preg_match('/<span[^>]*id=["\']errormsg["\'][^>]*>(.*?)<\/span>/is', $html, $matches)) {
            $message = $this->normalizeMessageText(strip_tags($matches[1]));
            if ($message !== '') {
                return $message;
            }
        }

        if (preg_match('/<div[^>]*class=["\'][^"\']*\berror\b[^"\']*["\'][^>]*>.*?<span[^>]*class=["\'][^"\']*\bnotice\b[^"\']*["\'][^>]*>(.*?)<\/span>/is', $html, $matches)) {
            $message = $this->normalizeMessageText(strip_tags($matches[1]));
            if ($message !== '') {
                return $message;
            }
        }

        foreach ([
            '/请输入验证码/u',
            '/请输入正确信息/u',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[0]);
            }
        }

        return '';
    }

    private function normalizeMessageText($message)
    {
        $message = html_entity_decode((string) $message, ENT_QUOTES, 'UTF-8');
        $message = preg_replace('/\s+/u', ' ', $message);
        return trim($message);
    }

    private function resolveUrl($baseUrl, $location)
    {
        if ($location === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $location;
        }

        $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . $baseParts['port'];
        }

        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }

        $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);

        return $origin . $dir . $location;
    }

    private function detectMimeType($binary)
    {
        if (!is_string($binary) || $binary === '') {
            return 'application/octet-stream';
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_buffer($finfo, $binary);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'image/jpeg';
    }
}

class SyauCaptchaStore
{
    const DEFAULT_TTL = 300;
    const MAX_CONSECUTIVE_FAILURES = 4;
    const ATTEMPT_LOCK_TTL = 600;
    const ATTEMPT_RESET_TTL = 1800;

    private $baseDir;

    public function __construct($baseDir = null)
    {
        $root = $baseDir ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'student_profile_example';
        $this->baseDir = rtrim($root, DIRECTORY_SEPARATOR);
        $this->ensureDirectory($this->baseDir);
    }

    public function getDefaultTtl()
    {
        return self::DEFAULT_TTL;
    }

    public function getAttemptLimit()
    {
        return self::MAX_CONSECUTIVE_FAILURES;
    }

    public function cleanupExpired()
    {
        $this->ensureDirectory($this->baseDir);
        $now = time();

        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $expiresAt = is_array($data) && isset($data['expires_at']) ? (int) $data['expires_at'] : 0;
            if ($expiresAt <= 0 || $expiresAt < $now) {
                @unlink($path);
            }
        }

        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . 'captcha-*') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < ($now - self::DEFAULT_TTL)) {
                @unlink($path);
            }
        }

        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . 'attempt-*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $lockedUntil = is_array($data) && isset($data['locked_until']) ? (int) $data['locked_until'] : 0;
            $lastFailedAt = is_array($data) && isset($data['last_failed_at']) ? (int) $data['last_failed_at'] : 0;
            if (($lockedUntil > 0 && $lockedUntil <= $now) || $lastFailedAt < ($now - self::ATTEMPT_RESET_TTL)) {
                @unlink($path);
            }
        }
    }

    public function createChallenge($account, array $state, $ttl = self::DEFAULT_TTL)
    {
        $token = bin2hex(random_bytes(16));
        $expiresIn = max(60, (int) $ttl);
        $payload = [
            'account' => (string) $account,
            'created_at' => time(),
            'expires_at' => time() + $expiresIn,
            'state' => $this->filterState($state),
        ];

        $this->writeJson($this->getChallengePath($token), $payload);

        return [
            'token' => $token,
            'expires_in' => $expiresIn,
        ];
    }

    public function readChallenge($token)
    {
        if (!$this->isValidToken($token)) {
            return null;
        }

        $path = $this->getChallengePath($token);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['expires_at'], $data['state'], $data['account'])) {
            @unlink($path);
            return null;
        }

        if ((int) $data['expires_at'] < time()) {
            @unlink($path);
            return null;
        }

        $data['token'] = $token;
        return $data;
    }

    public function deleteChallenge($token)
    {
        if (!$this->isValidToken($token)) {
            return false;
        }

        $path = $this->getChallengePath($token);
        return !is_file($path) ? true : @unlink($path);
    }

    public function getAccountAttemptState($account)
    {
        $state = $this->readAttemptState($account);
        $now = time();

        if (!is_array($state)) {
            return $this->buildDefaultAttemptState();
        }

        $lockedUntil = isset($state['locked_until']) ? (int) $state['locked_until'] : 0;
        $lastFailedAt = isset($state['last_failed_at']) ? (int) $state['last_failed_at'] : 0;

        if (($lockedUntil > 0 && $lockedUntil <= $now) || $lastFailedAt < ($now - self::ATTEMPT_RESET_TTL)) {
            $this->clearFailedAttempts($account);
            return $this->buildDefaultAttemptState();
        }

        $count = isset($state['count']) ? (int) $state['count'] : 0;
        $isBlocked = $lockedUntil > $now;

        return [
            'count' => $count,
            'remaining_attempts' => max(0, self::MAX_CONSECUTIVE_FAILURES - $count),
            'is_blocked' => $isBlocked,
            'retry_after' => $isBlocked ? ($lockedUntil - $now) : 0,
            'locked_until' => $lockedUntil,
            'last_message' => isset($state['last_message']) ? (string) $state['last_message'] : '',
        ];
    }

    public function recordFailedAttempt($account, $message = '')
    {
        $account = trim((string) $account);
        if ($account === '') {
            return $this->buildDefaultAttemptState();
        }

        $state = $this->readAttemptState($account);
        $now = time();
        $count = 0;

        if (is_array($state)) {
            $lockedUntil = isset($state['locked_until']) ? (int) $state['locked_until'] : 0;
            $lastFailedAt = isset($state['last_failed_at']) ? (int) $state['last_failed_at'] : 0;
            if (!(($lockedUntil > 0 && $lockedUntil <= $now) || $lastFailedAt < ($now - self::ATTEMPT_RESET_TTL))) {
                $count = isset($state['count']) ? (int) $state['count'] : 0;
            }
        }

        $count++;
        $payload = [
            'count' => $count,
            'last_failed_at' => $now,
            'locked_until' => $count >= self::MAX_CONSECUTIVE_FAILURES ? ($now + self::ATTEMPT_LOCK_TTL) : 0,
            'last_message' => (string) $message,
        ];

        $this->writeJson($this->getAttemptPath($account), $payload);

        return $this->getAccountAttemptState($account);
    }

    public function clearFailedAttempts($account)
    {
        $account = trim((string) $account);
        if ($account === '') {
            return false;
        }

        $path = $this->getAttemptPath($account);
        return !is_file($path) ? true : @unlink($path);
    }

    public function createCaptchaTempFile($imageBytes, $mimeType = 'image/jpeg')
    {
        $extension = $this->extensionFromMimeType($mimeType);
        $filename = 'captcha-' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $this->baseDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $imageBytes, LOCK_EX);
        return $path;
    }

    public function deleteFile($path)
    {
        if (!is_string($path) || $path === '') {
            return false;
        }

        $realPath = realpath($path);
        $realBase = realpath($this->baseDir);
        if ($realPath === false || $realBase === false) {
            return false;
        }

        if (strpos($realPath, $realBase) !== 0) {
            return false;
        }

        return !is_file($realPath) ? true : @unlink($realPath);
    }

    private function writeJson($path, array $payload)
    {
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function ensureDirectory($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function getChallengePath($token)
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $token . '.json';
    }

    private function getAttemptPath($account)
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . 'attempt-' . hash('sha256', $account) . '.json';
    }

    private function isValidToken($token)
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token);
    }

    private function readAttemptState($account)
    {
        $account = trim((string) $account);
        if ($account === '') {
            return null;
        }

        $path = $this->getAttemptPath($account);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['count'], $data['last_failed_at'], $data['locked_until'])) {
            @unlink($path);
            return null;
        }

        return $data;
    }

    private function buildDefaultAttemptState()
    {
        return [
            'count' => 0,
            'remaining_attempts' => self::MAX_CONSECUTIVE_FAILURES,
            'is_blocked' => false,
            'retry_after' => 0,
            'locked_until' => 0,
            'last_message' => '',
        ];
    }

    private function filterState(array $state)
    {
        return [
            'cookies' => isset($state['cookies']) && is_array($state['cookies']) ? $state['cookies'] : [],
            'lt' => isset($state['lt']) ? (string) $state['lt'] : '',
            'execution' => isset($state['execution']) ? (string) $state['execution'] : '',
            'login_url' => isset($state['login_url']) ? (string) $state['login_url'] : '',
        ];
    }

    private function extensionFromMimeType($mimeType)
    {
        switch (strtolower((string) $mimeType)) {
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            case 'image/bmp':
                return 'bmp';
            case 'image/jpeg':
            case 'image/jpg':
            default:
                return 'jpg';
        }
    }
}

class SyauProfileParser
{
    private static $sectionMap = [
        '学籍信息' => 'academic_info',
        '招生信息' => 'admission_info',
        '毕业信息' => 'graduation_info',
    ];

    private static $fieldMap = [
        'academic_info' => [
            '学号' => 'student_number',
            '姓名' => 'full_name',
            '姓名拼音' => 'pinyin_name',
            '英文姓名' => 'english_name',
            '证件号码' => 'id_number',
            '年级' => 'grade',
            '院系' => 'department',
            '专业' => 'major',
            '专业方向' => 'major_direction',
            '班级' => 'class_name',
            '校区' => 'campus',
            '辅修专业' => 'minor_major',
            '第二学位专业' => 'second_degree_major',
            '是否有学籍' => 'has_school_roll',
            '是否有国家学籍' => 'has_national_roll',
            '学生类别' => 'student_category',
            '学籍状态' => 'roll_status',
            '学科门类' => 'discipline_category',
            '特殊学生类型' => 'special_student_type',
            '收费类别' => 'tuition_category',
            '分流方向' => 'diversion_direction',
            '培养方式' => 'training_mode',
            '入学日期' => 'enrollment_date',
            '因材施教' => 'individualized_education',
            '培养层次' => 'education_level',
            '是否离校' => 'has_left_school',
            '是否应届毕业' => 'is_fresh_graduate',
            '入学年级' => 'admission_grade',
            '学制类型' => 'schooling_type',
            '学生类型' => 'student_type',
            '是否留学生' => 'is_international_student',
        ],
        'admission_info' => [
            '性别' => 'gender',
            '民族' => 'ethnicity',
            '政治面貌' => 'political_status',
            '国家/地区' => 'country_region',
            '授课语种' => 'teaching_language',
            '出生日期' => 'birth_date',
            '籍贯' => 'native_place',
            '外语语种' => 'foreign_language',
            '乘车区间' => 'train_route',
            '考生特征' => 'candidate_feature',
            '定向委培单位' => 'targeted_training_unit',
            '通讯地址' => 'mailing_address',
            '考区' => 'exam_region',
            '高考考生号' => 'gaokao_candidate_number',
            '高考总分' => 'gaokao_total_score',
            '毕业中学' => 'graduate_school',
            '入学考试语种' => 'admission_exam_language',
            '录取号' => 'admission_number',
            '录取年份' => 'admission_year',
            '学习形式' => 'study_form',
            '录取类别' => 'admission_category',
        ],
        'graduation_info' => [
            '学位' => 'degree',
            '学位证书编号' => 'degree_certificate_number',
            '毕业类型' => 'graduation_type',
            '毕业日期' => 'graduation_date',
            '预计毕业日期' => 'expected_graduation_date',
            '毕业证书编号' => 'graduation_certificate_number',
            '离校日期' => 'departure_date',
            '授予学位时间' => 'degree_award_date',
            '第二学位' => 'second_degree',
            '第二学位证书编号' => 'second_degree_certificate_number',
            '辅修证书编号' => 'minor_certificate_number',
        ],
    ];

    public function parse($html)
    {
        $profile = $this->createEmptyProfile();

        if (!is_string($html) || trim($html) === '') {
            return $profile;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $internalErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new DOMXPath($dom);
        $headingNodes = $xpath->query('//h4[contains(@class, "header")]');
        foreach ($headingNodes as $headingNode) {
            $sectionKey = $this->matchSection($headingNode->textContent);
            if ($sectionKey === null) {
                continue;
            }

            $container = $this->findNextElementSibling($headingNode);
            if (!$container instanceof DOMElement) {
                continue;
            }

            $rowNodes = $xpath->query('.//div[contains(@class, "profile-info-row")]', $container);
            foreach ($rowNodes as $rowNode) {
                $cells = [];
                foreach ($rowNode->childNodes as $childNode) {
                    if ($childNode instanceof DOMElement && strtolower($childNode->tagName) === 'div') {
                        $cells[] = $childNode;
                    }
                }

                for ($index = 0; $index < count($cells); $index += 2) {
                    $nameNode = isset($cells[$index]) ? $cells[$index] : null;
                    $valueNode = isset($cells[$index + 1]) ? $cells[$index + 1] : null;
                    if (!$nameNode instanceof DOMElement || !$valueNode instanceof DOMElement) {
                        continue;
                    }

                    $label = $this->normalizeText($nameNode->textContent);
                    if ($label === '' || !isset(self::$fieldMap[$sectionKey][$label])) {
                        continue;
                    }

                    $targetKey = self::$fieldMap[$sectionKey][$label];
                    $profile[$sectionKey][$targetKey] = $this->extractValue($valueNode);
                }
            }
        }

        return $profile;
    }

    public static function getFieldMap()
    {
        return self::$fieldMap;
    }

    private function createEmptyProfile()
    {
        $profile = [];
        foreach (self::$fieldMap as $sectionKey => $mapping) {
            $profile[$sectionKey] = [];
            foreach ($mapping as $fieldKey) {
                $profile[$sectionKey][$fieldKey] = '';
            }
        }

        return $profile;
    }

    private function matchSection($rawHeading)
    {
        $normalized = $this->normalizeText($rawHeading);
        foreach (self::$sectionMap as $label => $sectionKey) {
            if ($normalized !== '' && mb_strpos($normalized, $label) !== false) {
                return $sectionKey;
            }
        }

        return null;
    }

    private function findNextElementSibling(DOMNode $node)
    {
        $sibling = $node->nextSibling;
        while ($sibling !== null) {
            if ($sibling instanceof DOMElement) {
                return $sibling;
            }
            $sibling = $sibling->nextSibling;
        }

        return null;
    }

    private function extractValue(DOMElement $valueNode)
    {
        $onclick = $valueNode->getAttribute('onclick');
        if ($onclick && preg_match("/urp\\.showPlaintext\\('((?:\\\\'|[^'])*)'/u", $onclick, $matches)) {
            return $this->normalizeText(stripcslashes($matches[1]));
        }

        return $this->normalizeText($valueNode->textContent);
    }

    private function normalizeText($value)
    {
        $decoded = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $decoded = preg_replace('/\s+/u', ' ', $decoded);
        return trim($decoded);
    }
}
