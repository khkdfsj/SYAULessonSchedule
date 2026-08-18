<?php

/**
 * 今日课程查询接口
 *
 * @version 1.0.0
 * @desc 根据学号返回当天（或指定星期）的课程，含夏季/冬季作息表自动切换、假期跳周、学期间隙智能回退。开学日期固定为春季3月2日/秋季9月1日
 */

// ======================== 配置常量 ========================

define('API_VERSION', '1.0.0');
define('REMOTE_API_URL', 'https://debug.91nongye.cn/LessonSchedule/curlGetSyauInfo.php');
define('REMOTE_TIMEOUT_CONNECT', 5);
define('REMOTE_TIMEOUT_TOTAL', 10);
define('REMOTE_RETRY_MAX', 1);
define('REMOTE_RETRY_DELAY_US', 500000);
define('LOG_DIR', __DIR__ . '/logs');
define('USERID_MASK_KEEP_PREFIX', 4);
define('USERID_MASK_KEEP_SUFFIX', 3);

// 校历服务（同机部署的 syau-calendar，GET /api/calendar 返回当前学期开学日期）
define('CALENDAR_API_URL', 'http://127.0.0.1:5080/api/calendar');
define('CALENDAR_API_TIMEOUT_CONNECT', 3);
define('CALENDAR_API_TIMEOUT_TOTAL', 5);

define('MAX_TEACHING_WEEK', 25);

// 开学日期搜索窗口（北方大学规律：春季 2月下旬~3月上旬，秋季 8月下旬~9月上旬）
// 算法会在窗口内遍历每个周一，结合课程数据自动确定精确开学日，无需每学期修改
define('SPRING_SEARCH_START', '02-20');
define('SPRING_SEARCH_END', '03-10');
define('FALL_SEARCH_START', '08-20');
define('FALL_SEARCH_END', '09-10');

// 学期开学日（月/日）。春季3月2日，秋季9月1日，自动对齐到周一
define('SPRING_FALLBACK_MONTH', 3);
define('SPRING_FALLBACK_DAY', 2);
define('FALL_FALLBACK_MONTH', 9);
define('FALL_FALLBACK_DAY', 1);

// 假期跳周配置（这些教学周不计入周次计算，自动跳过）
// 格式：学期 => [周次数组]，周次为不考虑假期时的原始周数
// 例如：春季第5周是五一假期，则配置 'spring' => [5]
// 修改此处即可适配不同学校的假期安排
define('HOLIDAY_SKIP_WEEKS', [
    'spring' => [],   // 春季学期暂无默认假期周
    'fall'   => [],   // 秋季学期暂无默认假期周
]);

// 开学日检测：邻域匹配窗口大小（±N周）
// 在当前教学周前后各N周范围内统计有课的课程数，用于平滑假期等间隙周导致的评分波动
// 值为1时表示检查 [当前周-1, 当前周, 当前周+1] 三周内是否有课
define('SEMESTER_START_NEIGHBORHOOD_SIZE', 1);

define('SUMMER_SCHEDULE', [
    ['8:00', '8:45'], ['8:55', '9:40'],
    ['10:00', '10:45'], ['10:55', '11:40'],
    ['14:00', '14:45'], ['14:55', '15:40'],
    ['16:00', '16:45'], ['16:55', '17:40'],
    ['19:00', '19:45'], ['19:55', '20:40'],
    ['21:00', '21:45'], ['21:55', '22:40'],
]);

define('WINTER_SCHEDULE', [
    ['8:00', '8:45'], ['8:55', '9:40'],
    ['10:00', '10:45'], ['10:55', '11:40'],
    ['13:30', '14:15'], ['14:25', '15:10'],
    ['15:30', '16:15'], ['16:25', '17:10'],
    ['18:30', '19:15'], ['19:25', '20:10'],
    ['20:30', '21:15'], ['21:25', '22:10'],
]);

define('WEEKDAY_NAMES', ['一', '二', '三', '四', '五', '六', '日']);

function getWeekdayName(int $dayOfWeekNum): string
{
    $index = $dayOfWeekNum - 1;
    if ($index < 0 || $index >= count(WEEKDAY_NAMES)) {
        return '未知';
    }
    return WEEKDAY_NAMES[$index];
}

// ======================== 入口：方法校验 + CORS ========================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$startTime = microtime(true);
$requestId  = generateRequestId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, '仅支持 POST 请求', $requestId, 0);
}

// ======================== 1. 输入解析与校验 ========================

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    jsonError(400, 'Content-Type 必须为 application/json', $requestId, 0);
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonError(400, '请求体不是合法的JSON格式', $requestId, 0);
}
if (!is_array($input)) {
    $input = [];
}

$errors = validateInput($input);
if (!empty($errors)) {
    jsonError(400, $errors[0], $requestId, 0);
}

// ======================== 2. 核心业务 ========================

try {
    $remoteData   = callRemoteAPI($input['UserID']);
    $currentWeek  = resolveCurrentWeek($input['currentWeek'] ?? null, $remoteData['courseInfo']);
    $targetDay    = resolveTargetDayOfWeek($input['targetDayOfWeek'] ?? null);
    $targetDate   = resolveTargetDate($targetDay, $currentWeek);
    $schedule     = getCurrentSchedule($targetDate);
    $scheduleType = getScheduleType($targetDate);
    $courses      = filterCourses($remoteData, $targetDay['num'], $currentWeek['week']);
    $courses      = enrichCourses($courses, $schedule);
    $data         = buildResponseData($input, $targetDay, $currentWeek, $scheduleType, $remoteData, $courses, $targetDate);

    $elapsedMs = (int)((microtime(true) - $startTime) * 1000);
    logRequest($input, $requestId, 200, $elapsedMs, $remoteData['_timing'] ?? null, count($courses));
    $isToday = ($currentWeek['source'] === 'auto_calculated') && ($targetDay['source'] === 'auto');
    jsonSuccess($data, count($courses), $requestId, $elapsedMs, $isToday);

} catch (RuntimeException $e) {
    $elapsedMs = (int)((microtime(true) - $startTime) * 1000);
    $code = $e->getCode() ?: 500;
    logRequest($input, $requestId, $code, $elapsedMs, null, 0, $e->getMessage());
    jsonError($code, $e->getMessage(), $requestId, $elapsedMs);
} catch (Throwable $e) {
    $elapsedMs = (int)((microtime(true) - $startTime) * 1000);
    logRequest($input, $requestId, 500, $elapsedMs, null, 0, $e->getMessage());
    jsonError(500, '服务器内部错误', $requestId, $elapsedMs);
}

// ======================== 工具函数 ========================

function generateRequestId(): string
{
    $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    try {
        $random = substr(bin2hex(random_bytes(3)), 0, 6);
    } catch (Throwable $e) {
        $random = substr(md5(uniqid((string) mt_rand(), true)), 0, 6);
    }
    return $now->format('Ymd\THis') . '-' . $random;
}

function jsonSuccess(array $data, int $courseCount, string $requestId, int $elapsedMs, bool $isToday = true): void
{
    http_response_code(200);
    if ($courseCount > 0) {
        $msg = "操作成功，共{$courseCount}门课";
    } else {
        $msg = $isToday ? '操作成功，今天没有课程' : '操作成功，该日没有课程安排';
    }
    echo json_encode([
        'code' => 200,
        'msg' => $msg,
        'apiVersion' => API_VERSION,
        'requestId' => $requestId,
        'elapsedMs' => $elapsedMs,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(int $httpCode, string $msg, string $requestId = '', int $elapsedMs = 0): void
{
    http_response_code($httpCode);
    echo json_encode([
        'code' => $httpCode,
        'msg' => $msg,
        'apiVersion' => API_VERSION,
        'requestId' => $requestId ?: '',
        'elapsedMs' => $elapsedMs,
        'data' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function maskUserID(string $userID): string
{
    $len = strlen($userID);
    if ($len <= USERID_MASK_KEEP_PREFIX + USERID_MASK_KEEP_SUFFIX) {
        return str_repeat('*', $len);
    }
    return substr($userID, 0, USERID_MASK_KEEP_PREFIX)
         . '****'
         . substr($userID, -USERID_MASK_KEEP_SUFFIX);
}

// ======================== 输入校验 ========================

function validateInput(array $input): array
{
    $errors = [];

    if (!isset($input['UserID']) || $input['UserID'] === '' || $input['UserID'] === null) {
        $errors[] = '缺少必填参数 UserID';
        return $errors;
    }

    $userID = $input['UserID'];
    if (!is_string($userID) && !is_numeric($userID)) {
        $errors[] = 'UserID 必须为字符串类型';
        return $errors;
    }

    $userID = (string) $userID;
    if (!ctype_digit($userID)) {
        $errors[] = 'UserID 格式不合法，必须为纯数字';
        return $errors;
    }

    if (strlen($userID) > 20) {
        $errors[] = 'UserID 长度不能超过20位';
        return $errors;
    }

    if (array_key_exists('currentWeek', $input) && $input['currentWeek'] !== null) {
        $cw = $input['currentWeek'];
        if (!is_int($cw) && (!is_string($cw) || !ctype_digit((string) $cw))) {
            $errors[] = 'currentWeek 必须为整数';
        } else {
            $cw = (int) $cw;
            if ($cw < 1 || $cw > MAX_TEACHING_WEEK) {
                $errors[] = 'currentWeek 必须为 1-' . MAX_TEACHING_WEEK . ' 的整数';
            }
        }
    }

    if (array_key_exists('targetDayOfWeek', $input) && $input['targetDayOfWeek'] !== null) {
        $td = $input['targetDayOfWeek'];
        if (!is_int($td) && (!is_string($td) || !ctype_digit((string) $td))) {
            $errors[] = 'targetDayOfWeek 必须为整数';
        } else {
            $td = (int) $td;
            if ($td < 1 || $td > 7) {
                $errors[] = 'targetDayOfWeek 必须为 1-7（1=周一，7=周日）';
            }
        }
    }

    return $errors;
}

// ======================== 作息表 ========================

function getCurrentSchedule(?DateTime $refDate = null): array
{
    $ref = $refDate ?? new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $month = (int) $ref->format('n');
    if ($month >= 5 && $month <= 9) {
        return SUMMER_SCHEDULE;
    }
    return WINTER_SCHEDULE;
}

function getScheduleType(?DateTime $refDate = null): string
{
    $ref = $refDate ?? new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $month = (int) $ref->format('n');
    return ($month >= 5 && $month <= 9) ? 'summer' : 'winter';
}

// ======================== 日期计算 ========================

function resolveTargetDayOfWeek($param): array
{
    if ($param !== null) {
        return ['num' => (int) $param, 'source' => 'manual'];
    }
    $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    return ['num' => (int) $now->format('N'), 'source' => 'auto'];
}

/**
 * 根据教学周和星期几计算目标日期
 *
 * - 手动传入 currentWeek 时：根据开学日期 + (周次-1)*7 + (星期-1) 精确计算
 * - 自动计算 currentWeek 时：使用当天日期，按 targetDayOfWeek 在本周内调整
 *
 * @param array $targetDay  resolveTargetDayOfWeek 的返回值
 * @param array $currentWeek resolveCurrentWeek 的返回值
 * @return DateTime 目标日期（0点0分）
 */
function resolveTargetDate(array $targetDay, array $currentWeek): DateTime
{
    $today = new DateTime('today', new DateTimeZone('Asia/Shanghai'));

    // 手动传入 currentWeek 时，根据开学日期精确计算目标日期
    if ($currentWeek['source'] === 'manual' && isset($currentWeek['semesterStartDate'])) {
        $semesterStart = clone $currentWeek['semesterStartDate'];
        $semesterStart->setTime(0, 0, 0);
        // 开学日（周一）+ (周次-1)*7 + (星期-1) 天
        $offset = ((int) $currentWeek['week'] - 1) * 7 + ((int) $targetDay['num'] - 1);
        $targetDate = clone $semesterStart;
        $targetDate->modify("+{$offset} days");
        return $targetDate;
    }

    // 自动计算 currentWeek 时，使用当天日期，按 targetDayOfWeek 在本周内调整
    $targetDate = clone $today;
    if ($targetDay['source'] === 'manual') {
        $todayDow = (int) $today->format('N');
        $diff = $targetDay['num'] - $todayDow;
        if ($diff !== 0) {
            $targetDate->modify($diff > 0 ? "+{$diff} days" : "{$diff} days");
        }
    }
    return $targetDate;
}

function resolveCurrentWeek($param, ?array $courseInfo = null): array
{
    $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $month = (int) $now->format('n');
    $year  = (int) $now->format('Y');

    if ($param !== null) {
        // 手动传入时仍需检测开学日期，用于计算目标日期和学期显示信息
        $semesterStart = getSemesterStart($year, $month, $courseInfo);
        $display = formatSemesterDisplay($semesterStart['semesterType'], $semesterStart['semesterYear']);
        return [
            'week' => (int) $param,
            'source' => 'manual',
            'warning' => '',
            'semesterYear' => $display['semesterYear'],
            'semesterTerm' => $display['semesterTerm'],
            'semesterStartDate' => $semesterStart['date'],
            'semesterType' => $semesterStart['semesterType'],
        ];
    }
    $result = autoCalculateWeek($courseInfo);
    return [
        'week' => $result['week'],
        'source' => 'auto_calculated',
        'warning' => $result['warning'],
        'semesterYear' => $result['semesterYear'],
        'semesterTerm' => $result['semesterTerm'],
        'semesterStartDate' => $result['semesterStartDate'],
        'semesterType' => $result['semesterType'],
    ];
}

function formatSemesterDisplay(string $semesterType, int $semesterYear): array
{
    if ($semesterType === 'fall') {
        return [
            'semesterYear' => $semesterYear . '-' . ($semesterYear + 1) . '学年',
            'semesterTerm' => '第一学期',
        ];
    }
    return [
        'semesterYear' => ($semesterYear - 1) . '-' . $semesterYear . '学年',
        'semesterTerm' => '第二学期',
    ];
}

function autoCalculateWeek(?array $courseInfo = null): array
{
    $today = new DateTime('today', new DateTimeZone('Asia/Shanghai'));
    $month = (int) $today->format('n');
    $year  = (int) $today->format('Y');

    $semesterInfo = getSemesterStart($year, $month, $courseInfo);
    $semesterDisplay = formatSemesterDisplay($semesterInfo['semesterType'], $semesterInfo['semesterYear']);

    $warnings = [];

    if ($today < $semesterInfo['date']) {
        $prevSemester = getPreviousSemesterStart($year, $semesterInfo['semesterType']);

        if ($prevSemester !== null && $today >= $prevSemester['date']) {
            $diff = (int) $today->diff($prevSemester['date'])->days;
            $rawWeek = (int) floor($diff / 7) + 1;
            $week = applyHolidaySkips($rawWeek, $prevSemester['semesterType'], $warnings);
            $prevDisplay = formatSemesterDisplay($prevSemester['semesterType'], $prevSemester['semesterYear']);

            if ($week < 1) $week = 1;
            if ($week > MAX_TEACHING_WEEK) {
                $week = MAX_TEACHING_WEEK;
                $warnings[] = '教学周已超过最大周数限制，已截断至第' . MAX_TEACHING_WEEK . '周';
            }

            $label = $prevSemester['semesterType'] === 'fall' ? '秋季' : '春季';
            $warnings[] = '当前处于' . $label . '学期末/假期中，周次为估算值';

            return [
                'week' => $week,
                'warning' => implode('；', $warnings),
                'semesterYear' => $prevDisplay['semesterYear'],
                'semesterTerm' => $prevDisplay['semesterTerm'],
                'semesterStartDate' => $prevSemester['date'],
                'semesterType' => $prevSemester['semesterType'],
            ];
        }

        return [
            'week' => 1,
            'warning' => '当前日期在学期开始前，默认返回第1周。请手动传入 currentWeek 参数',
            'semesterYear' => $semesterDisplay['semesterYear'],
            'semesterTerm' => $semesterDisplay['semesterTerm'],
            'semesterStartDate' => $semesterInfo['date'],
            'semesterType' => $semesterInfo['semesterType'],
        ];
    }

    $diff = (int) $today->diff($semesterInfo['date'])->days;
    $rawWeek = (int) floor($diff / 7) + 1;

    $week = applyHolidaySkips($rawWeek, $semesterInfo['semesterType'], $warnings);

    if ($week < 1) $week = 1;
    if ($week > MAX_TEACHING_WEEK) {
        $week = MAX_TEACHING_WEEK;
        $warnings[] = '教学周已超过最大周数限制，已截断至第' . MAX_TEACHING_WEEK . '周';
    }

    return [
        'week' => $week,
        'warning' => $warnings ? implode('；', $warnings) : '',
        'semesterYear' => $semesterDisplay['semesterYear'],
        'semesterTerm' => $semesterDisplay['semesterTerm'],
        'semesterStartDate' => $semesterInfo['date'],
        'semesterType' => $semesterInfo['semesterType'],
    ];
}

function getSemesterStart(int $year, int $month, ?array $courseInfo = null): array
{
    $isFall = ($month >= 8 || $month <= 1);
    $semesterType = $isFall ? 'fall' : 'spring';
    $refYear = ($month <= 1) ? $year - 1 : $year;
    $today = new DateTime('today', new DateTimeZone('Asia/Shanghai'));

    // 优先从校历服务获取真实开学日，仅当与本地学期判断吻合时采用
    $detectionMethod = 'configured';
    $apiStartDate = fetchCalendarStartDate();
    if ($apiStartDate !== null) {
        $apiDate = DateTime::createFromFormat('Y-m-d', $apiStartDate, new DateTimeZone('Asia/Shanghai'));
        if ($apiDate !== false) {
            $apiMonth = (int) $apiDate->format('n');
            $apiYear = (int) $apiDate->format('Y');
            $matches = $isFall
                ? ($apiMonth >= 8 && $apiMonth <= 9 && $apiYear === $refYear)
                : ($apiMonth >= 2 && $apiMonth <= 3 && $apiYear === $refYear);
            if ($matches) {
                $refDate = $apiDate;
                $detectionMethod = 'calendar_api';
            }
        }
    }

    if (!isset($refDate)) {
        $fallbackMonth = $isFall ? FALL_FALLBACK_MONTH : SPRING_FALLBACK_MONTH;
        $fallbackDay = $isFall ? FALL_FALLBACK_DAY : SPRING_FALLBACK_DAY;
        $refDate = new DateTime(sprintf('%04d-%02d-%02d', $refYear, $fallbackMonth, $fallbackDay), new DateTimeZone('Asia/Shanghai'));
    }
    $originalDow = (int) $refDate->format('N');
    $alignmentOffset = 0;
    if ($originalDow !== 1) {
        $alignmentOffset = $originalDow - 1;
        $refDate->modify('-' . $alignmentOffset . ' days');
    }
    $refDate->setTime(0, 0, 0);

    return [
        'date' => clone $refDate,
        'alignmentOffset' => $alignmentOffset,
        'semesterType' => $semesterType,
        'semesterYear' => $refYear,
        'detectionMethod' => $detectionMethod,
    ];
}

function detectSemesterStart(DateTime $today, array $courseInfo, string $semesterType, int $refYear): ?DateTime
{
    $allWeeks = [];
    foreach ($courseInfo as $course) {
        if (!is_array($course)) continue;
        $weeksRaw = $course['weeks'] ?? [];
        if (!is_array($weeksRaw)) continue;
        foreach ($weeksRaw as $w) {
            $wInt = (int) $w;
            if ($wInt > 0) {
                $allWeeks[$wInt] = true;
            }
        }
    }

    if (empty($allWeeks)) return null;

    $courseWeeks = array_keys($allWeeks);
    sort($courseWeeks);
    $maxCourseWeek = end($courseWeeks);

    if ($semesterType === 'fall') {
        $startStr = "$refYear-" . FALL_SEARCH_START;
        $endStr   = "$refYear-" . FALL_SEARCH_END;
    } else {
        $startStr = "$refYear-" . SPRING_SEARCH_START;
        $endStr   = "$refYear-" . SPRING_SEARCH_END;
    }

    $searchStart = new DateTime($startStr, new DateTimeZone('Asia/Shanghai'));
    $searchEnd   = new DateTime($endStr, new DateTimeZone('Asia/Shanghai'));

    $dow = (int) $searchStart->format('N');
    if ($dow !== 1) {
        $searchStart->modify('+' . (8 - $dow) . ' days');
    }
    $searchStart->setTime(0, 0, 0);

    $candidates = [];
    $cursor = clone $searchStart;

    while ($cursor <= $searchEnd) {
        if ($today >= $cursor) {
            $diff = (int) $today->diff($cursor)->days;
            $calculatedWeek = (int) floor($diff / 7) + 1;

            if ($calculatedWeek >= 1 && $calculatedWeek <= $maxCourseWeek + 3) {
                $candidates[] = [
                    'date' => clone $cursor,
                    'week' => $calculatedWeek,
                ];
            }
        } else {
            $candidates[] = [
                'date' => clone $cursor,
                'week' => 1,
                'beforeSemester' => true,
            ];
        }
        $cursor->modify('+7 days');
    }

    if (empty($candidates)) return null;

    $candidates = resolveCandidatesByCourseMatch($candidates, $courseInfo);

    if (count($candidates) === 1) return $candidates[0]['date'];

    // 过滤：保留评分接近最高分的候选（差值不超过3），消除明显不合理的候选
    // 避免学期末课程密度自然下降时，评分偏向更晚的开学日（更早的教学周）
    $maxScore = 0;
    foreach ($candidates as $c) {
        $s = $c['score'] ?? 0;
        if ($s > $maxScore) $maxScore = $s;
    }
    $scoreThreshold = max(1, $maxScore - 3);
    $filtered = array_filter($candidates, function ($c) use ($scoreThreshold) {
        return ($c['score'] ?? 0) >= $scoreThreshold;
    });
    if (empty($filtered)) {
        $filtered = $candidates;
    }

    // 在合理候选中，优先选择距搜索窗口中心最近的
    // 基于北方大学开学日期集中分布在窗口中部的统计规律
    $windowCenterTs = (int)(($searchStart->getTimestamp() + $searchEnd->getTimestamp()) / 2);

    usort($filtered, function ($a, $b) use ($windowCenterTs) {
        $distA = abs((int)$a['date']->getTimestamp() - $windowCenterTs);
        $distB = abs((int)$b['date']->getTimestamp() - $windowCenterTs);
        if ($distA !== $distB) {
            return $distA - $distB;
        }
        // 最终平局决胜：评分高者优先
        return ($b['score'] ?? 0) - ($a['score'] ?? 0);
    });

    return $filtered[0]['date'];
}

function resolveCandidatesByCourseMatch(array $candidates, array $courseInfo): array
{
    if (count($candidates) <= 1) {
        return $candidates;
    }

    $neighborhoodSize = SEMESTER_START_NEIGHBORHOOD_SIZE;

    return array_map(function ($c) use ($courseInfo, $neighborhoodSize) {
        // beforeSemester 候选也按 week=1 评分（学期即将开始，week 1 有课即合理）
        $exactMatchCount = 0;
        $neighborhoodMatchCount = 0;
        $week = $c['week'];

        foreach ($courseInfo as $course) {
            if (!is_array($course)) continue;
            $courseWeeks = $course['weeks'] ?? [];
            if (!is_array($courseWeeks)) continue;

            $courseWeeksInt = array_map('intval', $courseWeeks);

            // 精确匹配（当前周）
            if (in_array($week, $courseWeeksInt, true)) {
                $exactMatchCount++;
            }

            // 邻域匹配（±N周范围内有课即计分）
            $inNeighborhood = false;
            for ($w = max(1, $week - $neighborhoodSize); $w <= $week + $neighborhoodSize; $w++) {
                if (in_array($w, $courseWeeksInt, true)) {
                    $inNeighborhood = true;
                    break;
                }
            }
            if ($inNeighborhood) {
                $neighborhoodMatchCount++;
            }
        }

        // 主评分使用邻域匹配数，平滑假期等间隙周导致的波动
        $c['score'] = $neighborhoodMatchCount;
        $c['exactScore'] = $exactMatchCount;
        return $c;
    }, $candidates);
}

function getPreviousSemesterStart(int $currentYear, string $currentType): ?array
{
    $fallbackMonth = ($currentType === 'spring') ? FALL_FALLBACK_MONTH : SPRING_FALLBACK_MONTH;
    $fallbackDay = ($currentType === 'spring') ? FALL_FALLBACK_DAY : SPRING_FALLBACK_DAY;
    $refYear = ($currentType === 'spring') ? $currentYear - 1 : $currentYear;

    $refDate = new DateTime(sprintf('%04d-%02d-%02d', $refYear, $fallbackMonth, $fallbackDay), new DateTimeZone('Asia/Shanghai'));
    $originalDow = (int) $refDate->format('N');
    if ($originalDow !== 1) {
        $refDate->modify('-' . ($originalDow - 1) . ' days');
    }
    $refDate->setTime(0, 0, 0);

    return [
        'date' => clone $refDate,
        'alignmentOffset' => 0,
        'semesterType' => ($currentType === 'spring') ? 'fall' : 'spring',
        'semesterYear' => $refYear,
        'detectionMethod' => 'fallback',
    ];
}

function applyHolidaySkips(int $rawWeek, string $semesterType, array &$warnings): int
{
    $skipWeeks = HOLIDAY_SKIP_WEEKS[$semesterType] ?? [];

    if (empty($skipWeeks)) {
        return $rawWeek;
    }

    sort($skipWeeks);
    $adjustment = 0;
    foreach ($skipWeeks as $skipWeek) {
        if ($rawWeek > $skipWeek) {
            $adjustment++;
        }
    }

    if ($adjustment > 0) {
        $warnings[] = "已自动跳过{$adjustment}个假期周，原始第{$rawWeek}周";
    }

    return $rawWeek - $adjustment;
}

// ======================== 远程调用 ========================

function fetchCalendarStartDate(): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => CALENDAR_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => CALENDAR_API_TIMEOUT_CONNECT,
        CURLOPT_TIMEOUT => CALENDAR_API_TIMEOUT_TOTAL,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno !== 0 || $response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return null;
    }

    $startDate = $data['start_date'] ?? '';
    if (!is_string($startDate) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startDate, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }

    return $startDate;
}

function callRemoteAPI(string $userID): array
{
    $postData = json_encode(['UserID' => $userID], JSON_UNESCAPED_UNICODE);
    if ($postData === false) {
        throw new RuntimeException('请求数据序列化失败', 500);
    }

    $remoteStart = microtime(true);
    $lastErrorMsg = '';
    $result = curlPostWithRetry(REMOTE_API_URL, $postData, REMOTE_RETRY_MAX, $lastErrorMsg);
    $remoteElapsedMs = (int)((microtime(true) - $remoteStart) * 1000);

    if ($result === null) {
        throw new RuntimeException('课程数据服务暂不可用，请稍后重试' . ($lastErrorMsg ? "（{$lastErrorMsg}）" : ''), 503);
    }

    $data = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('上游课程数据格式异常', 502);
    }

    if (!isset($data['code']) || $data['code'] !== 200) {
        $msg = $data['msg'] ?? '未知错误';
        throw new RuntimeException("上游课程数据返回异常: {$msg}", 502);
    }

    if (!isset($data['data']['courseInfo']) || !is_array($data['data']['courseInfo'])) {
        throw new RuntimeException('上游课程数据结构异常', 502);
    }

    return [
        'courseInfo' => $data['data']['courseInfo'],
        'source' => $data['data']['source'] ?? '未知来源',
        '_timing' => ['elapsedMs' => $remoteElapsedMs],
    ];
}

function curlPostWithRetry(string $url, string $postData, int $maxRetry, string &$lastError = ''): ?string
{
    $attempt = 0;

    while ($attempt <= $maxRetry) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => REMOTE_TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT => REMOTE_TIMEOUT_TOTAL,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno === 0 && $response !== false) {
            return $response;
        }

        $lastError = $curlError ?: "HTTP {$httpCode}";
        $attempt++;

        if ($attempt <= $maxRetry) {
            usleep(REMOTE_RETRY_DELAY_US);
        }
    }

    return null;
}

// ======================== 课程处理 ========================

function filterCourses(array $remoteData, int $targetDayOfWeek, int $currentWeek): array
{
    $result = [];
    $courseInfo = $remoteData['courseInfo'] ?? [];

    foreach ($courseInfo as $course) {
        if (!is_array($course)) {
            continue;
        }
        if ((int) ($course['week'] ?? -1) !== $targetDayOfWeek) {
            continue;
        }

        $weeksRaw = $course['weeks'] ?? [];
        if (!is_array($weeksRaw)) {
            $weeksRaw = [];
        }
        $weeks = array_map('intval', $weeksRaw);

        if (!in_array($currentWeek, $weeks, true)) {
            continue;
        }

        $result[] = $course;
    }

    usort($result, function ($a, $b) {
        $sa = (int) ($a['section'] ?? 0);
        $sb = (int) ($b['section'] ?? 0);
        if ($sa !== $sb) {
            return $sa - $sb;
        }
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $result;
}

function enrichCourses(array $courses, array $schedule): array
{
    $enriched = [];

    foreach ($courses as $course) {
        $section      = (int) ($course['section'] ?? 1);
        $sectionCount = (int) ($course['sectionCount'] ?? 2);
        if ($sectionCount < 1) $sectionCount = 1;
        $timeInfo     = getTimeRange($section, $sectionCount, $schedule);

        $enriched[] = [
            'id' => isset($course['id']) ? (int) $course['id'] : 0,
            'name' => $course['name'] ?? '',
            'num' => $course['num'] ?? '',
            'credit' => isset($course['credit']) ? (string) $course['credit'] : '',
            'totalHours' => isset($course['totalHours']) ? (string) $course['totalHours'] : '',
            'category' => $course['category'] ?? '',
            'courseAttribute' => $course['CourseAttribute'] ?? '',
            'teacher' => $course['teacher'] ?? '',
            'teacherUserID' => $course['teacherUserID'] ?? '',
            'sectionStart' => $section,
            'sectionEnd' => $section + $sectionCount - 1,
            'sectionCount' => $sectionCount,
            'timeRange' => $timeInfo['range'],
            'periodLabel' => getPeriodLabel($section),
            'address' => $course['address'] ?? '',
            'weekText' => $course['weekText'] ?? '',
        ];
    }

    return $enriched;
}

function getTimeRange(int $section, int $sectionCount, array $schedule): array
{
    $total = count($schedule);
    $startIndex = $section - 1;
    $endIndex   = $section + $sectionCount - 2;

    if ($startIndex < 0) $startIndex = 0;
    if ($startIndex >= $total) $startIndex = $total - 1;
    if ($endIndex < 0) $endIndex = 0;
    if ($endIndex >= $total) $endIndex = $total - 1;
    if ($startIndex > $endIndex) $startIndex = $endIndex;

    $start = $schedule[$startIndex][0] ?? '';
    $end   = $schedule[$endIndex][1] ?? '';

    return [
        'start' => $start,
        'end'   => $end,
        'range' => "{$start}-{$end}",
    ];
}

function getPeriodLabel(int $section): string
{
    if ($section >= 1 && $section <= 4) return '上午';
    if ($section >= 5 && $section <= 8) return '下午';
    return '晚上';
}

// ======================== 数据组装 ========================

function buildResponseData(array $input, array $targetDay, array $currentWeek, string $scheduleType, array $remoteData, array $courses, DateTime $targetDate): array
{
    $totalPeriods = 0;
    foreach ($courses as $course) {
        $totalPeriods += $course['sectionCount'];
    }

    return [
        'UserID' => (string) $input['UserID'],
        'date' => $targetDate->format('Y-m-d'),
        'dayOfWeek' => '星期' . getWeekdayName($targetDay['num']),
        'dayOfWeekNum' => $targetDay['num'],
        'dayOfWeekSource' => $targetDay['source'],
        'currentWeek' => $currentWeek['week'],
        'currentWeekSource' => $currentWeek['source'],
        'currentWeekWarning' => $currentWeek['warning'],
        'scheduleType' => $scheduleType,
        'semesterYear' => $currentWeek['semesterYear'],
        'semesterTerm' => $currentWeek['semesterTerm'],
        'totalCourses' => count($courses),
        'totalPeriods' => $totalPeriods,
        'source' => $remoteData['source'] ?? '未知来源',
        'courses' => $courses,
        'textMessage' => buildTextMessage($courses, $targetDay, $currentWeek, $scheduleType, $targetDate, $totalPeriods),
        'quickText' => buildQuickText($courses, $targetDay, $currentWeek, $targetDate),
    ];
}

/**
 * 构建完整格式化文本（企业微信应用消息 text 类型，被动回复）
 *
 * 纯文本格式，最长不超过 2048 字节
 * 文档：https://developer.work.weixin.qq.com/document/path/90241#文本消息
 */
function buildTextMessage(array $courses, array $targetDay, array $currentWeek, string $scheduleType, DateTime $targetDate, int $totalPeriods): string
{
    $month = (int) $targetDate->format('n');
    $day   = (int) $targetDate->format('j');
    $weekDayName = getWeekdayName($targetDay['num']);
    $scheduleLabel = $scheduleType === 'summer' ? '夏季作息' : '冬季作息';

    // 判断目标日期是否为今天（仅当 currentWeek 和 targetDay 均为自动时才可能是今天）
    $isToday = ($currentWeek['source'] === 'auto_calculated') && ($targetDay['source'] === 'auto');

    $lines = [];
    // 头部：日期 + 教学周 + 作息
    $lines[] = "{$month}月{$day}日 星期{$weekDayName} | 第{$currentWeek['week']}教学周 | {$scheduleLabel}";
    $lines[] = str_repeat('━', 20);

    if (empty($courses)) {
        $noCourseText = $isToday
            ? '今天没有课程，好好休息吧'
            : '该日没有课程安排';
        $lines[] = $noCourseText;
        if ($currentWeek['warning']) {
            $lines[] = '';
            $lines[] = '⚠ ' . $currentWeek['warning'];
        }
        return implode("\n", $lines);
    }

    $lines[] = "共" . count($courses) . "门课，{$totalPeriods}学时";
    $lines[] = '';

    foreach ($courses as $i => $c) {
        $idx = $i + 1;
        $lines[] = "【{$idx}】{$c['name']}";
        $lines[] = "  时间  {$c['timeRange']}（{$c['periodLabel']} 第{$c['sectionStart']}-{$c['sectionEnd']}节）";
        $lines[] = "  地点  {$c['address']}";
        $lines[] = "  教师  {$c['teacher']} | {$c['category']} | {$c['credit']}学分";
        $lines[] = "  周次  {$c['weekText']}";
        $lines[] = '';
    }

    if ($currentWeek['warning']) {
        $lines[] = '⚠ ' . $currentWeek['warning'];
    }

    return trim(implode("\n", $lines));
}

/**
 * 构建精简版文本（企业微信应用消息 text 类型，被动回复）
 * 适合列表或推送场景，信息密度更高
 * 文档：https://developer.work.weixin.qq.com/document/path/90241#文本消息
 */
function buildQuickText(array $courses, array $targetDay, array $currentWeek, DateTime $targetDate): string
{
    $month = (int) $targetDate->format('n');
    $day   = (int) $targetDate->format('j');
    $weekDayName = getWeekdayName($targetDay['num']);
    $isToday = ($currentWeek['source'] === 'auto_calculated') && ($targetDay['source'] === 'auto');

    $lines = [];
    $lines[] = "{$month}月{$day}日 周{$weekDayName} | 第{$currentWeek['week']}教学周";
    $lines[] = str_repeat('━', 20);

    if (empty($courses)) {
        $noCourseText = $isToday ? '今天没有课程' : '该日没有课程';
        $lines[] = $noCourseText;
        return implode("\n", $lines);
    }

    foreach ($courses as $i => $c) {
        $idx = $i + 1;
        $lines[] = "{$idx}. {$c['name']}";
        $lines[] = "   {$c['timeRange']} {$c['address']}";
    }

    return implode("\n", $lines);
}

// ======================== 日志 ========================

function logRequest(array $input, string $requestId, int $code, int $elapsedMs, $remoteTiming, int $courseCount, string $error = null): void
{
    try {
        if (!ensureLogDir()) {
            return;
        }

        $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $logFile = LOG_DIR . '/api_' . $now->format('Ymd') . '.log';

        $userID = isset($input['UserID']) ? maskUserID((string) $input['UserID']) : 'unknown';

        $params = ['UserID' => $userID];
        if (isset($input['currentWeek'])) {
            $params['currentWeek'] = $input['currentWeek'];
        }
        if (isset($input['targetDayOfWeek'])) {
            $params['targetDayOfWeek'] = $input['targetDayOfWeek'];
        }

        $remoteElapsed = null;
        if (is_array($remoteTiming)) {
            $remoteElapsed = $remoteTiming['elapsedMs'] ?? null;
        }

        $logEntry = [
            'requestId' => $requestId,
            'timestamp' => $now->format('Y-m-d\TH:i:sP'),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'clientIP' => $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown'),
            'userID' => $userID,
            'params' => $params,
            'code' => $code,
            'elapsedMs' => $elapsedMs,
            'remoteElapsedMs' => $remoteElapsed,
            'remoteStatus' => $code === 200 ? 'success' : ($error ?: 'error'),
            'result' => $courseCount > 0 ? "{$courseCount} courses" : 'no courses',
        ];

        if ($error) {
            $logEntry['error'] = $error;
        }

        @file_put_contents(
            $logFile,
            json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $e) {
        // 日志写入失败不影响接口正常返回
    }
}

function ensureLogDir(): bool
{
    if (is_dir(LOG_DIR)) {
        return true;
    }
    @mkdir(LOG_DIR, 0755, true);
    return is_dir(LOG_DIR);
}
