<?php
declare(strict_types=1);

/**
 * 课表调研问卷：建表、题库定义、抽题、答案校验与统计。
 * 题库写在代码里（不含管理端题目编辑器），管理端只管理"期次 + 发布状态 + 查看数据"。
 */

function ensureSurveySchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS survey_rounds (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            round_key varchar(64) NOT NULL,
            title varchar(120) NOT NULL,
            intro varchar(300) NOT NULL DEFAULT '',
            status enum('draft','active','closed') NOT NULL DEFAULT 'draft',
            entry_enabled tinyint(1) NOT NULL DEFAULT 1,
            created_by varchar(20) NOT NULL,
            updated_by varchar(20) NOT NULL,
            activated_at datetime DEFAULT NULL,
            closed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_survey_round_key (round_key),
            KEY idx_survey_round_status (status, activated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS survey_responses (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            round_id bigint unsigned NOT NULL,
            respondent_key varchar(64) NOT NULL,
            user_id varchar(20) NOT NULL DEFAULT '',
            verified tinyint(1) NOT NULL DEFAULT 0,
            quiz_mode enum('quick','full') NOT NULL,
            asked_items text NOT NULL,
            submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_survey_response (round_id, respondent_key, quiz_mode),
            KEY idx_survey_response_round (round_id, quiz_mode),
            CONSTRAINT fk_survey_response_round FOREIGN KEY (round_id)
                REFERENCES survey_rounds (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS survey_answers (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            response_id bigint unsigned NOT NULL,
            round_id bigint unsigned NOT NULL,
            item_key varchar(64) NOT NULL,
            answer_json text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_survey_answer (response_id, item_key),
            KEY idx_survey_answer_item (round_id, item_key),
            CONSTRAINT fk_survey_answer_response FOREIGN KEY (response_id)
                REFERENCES survey_responses (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * 题库定义。quick=true 的题目会进入"快速问卷"随机池（每题都可单独作答）。
 * block 用于页面上分块展示；followUp 为条件追问（仅在选择指定选项后出现）。
 */
function surveyItemDefinitions(): array
{
    $awarenessOptions = [
        ['key' => 'often', 'label' => '经常用'],
        ['key' => 'known', 'label' => '知道但没用过'],
        ['key' => 'unknown', 'label' => '不知道有这功能'],
    ];

    return [
        [
            'key' => 'open_purpose',
            'block' => 'usage',
            'type' => 'multi',
            'max' => 3,
            'required' => true,
            'quick' => false,
            'title' => '你一般在什么时候打开课表？',
            'hint' => '最多选 3 项',
            'options' => [
                ['key' => 'before_class', 'label' => '上课前查教室'],
                ['key' => 'today', 'label' => '看今天有没有课'],
                ['key' => 'next', 'label' => '看下一节在哪'],
                ['key' => 'week', 'label' => '看这周还有哪些课'],
                ['key' => 'adjust', 'label' => '查假期调课安排'],
                ['key' => 'notice', 'label' => '看有没有新通知'],
                ['key' => 'peer', 'label' => '同学问我帮着查'],
                ['key' => 'other', 'label' => '其他'],
            ],
        ],
        [
            'key' => 'know_group',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '课表的「课程群」功能，你知道吗？',
            'options' => $awarenessOptions,
        ],
        [
            'key' => 'know_comment',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '课表的「课程评论与讨论」功能，你知道吗？',
            'options' => $awarenessOptions,
        ],
        [
            'key' => 'know_custom',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '课表的「自定义课程」（手动加课）功能，你知道吗？',
            'options' => $awarenessOptions,
        ],
        [
            'key' => 'know_feedback',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '课表的「问题反馈与建议广场」，你知道吗？',
            'options' => $awarenessOptions,
        ],
        [
            'key' => 'load_satisfaction',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '你对当前课表的加载速度满意吗？',
            'options' => [
                ['key' => 'great', 'label' => '很满意'],
                ['key' => 'good', 'label' => '比较满意'],
                ['key' => 'normal', 'label' => '一般'],
                ['key' => 'poor', 'label' => '不太满意'],
                ['key' => 'bad', 'label' => '很不满意'],
            ],
        ],
        [
            'key' => 'popup_accept',
            'block' => 'experience',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '目前重要通知会采用开屏弹窗提示，你能接受吗？',
            'options' => [
                ['key' => 'fine', 'label' => '完全能接受'],
                ['key' => 'okay', 'label' => '有点烦但能接受'],
                ['key' => 'bad', 'label' => '挺烦的，希望换方式'],
                ['key' => 'never', 'label' => '没遇到过弹窗'],
            ],
            'followUp' => [
                'key' => 'popup_alt',
                'type' => 'multi',
                'max' => 4,
                'required' => false,
                'title' => '你更能接受哪些提示方式？',
                'hint' => '可多选',
                'options' => [
                    ['key' => 'delay', 'label' => '加载课表成功后 30 秒再弹出'],
                    ['key' => 'banner', 'label' => '顶部横幅提示，不打断操作'],
                    ['key' => 'wecom', 'label' => '发到企业微信消息里'],
                    ['key' => 'setting', 'label' => '放在设置页通知板块单独查看'],
                ],
                'when' => ['okay', 'bad'],
            ],
        ],
        [
            'key' => 'pain_open',
            'block' => 'experience',
            'type' => 'text',
            'required' => false,
            'quick' => true,
            'maxLength' => 120,
            'title' => '最近一个月，有没有你想在课表里做、但没做成或觉得很麻烦的事？',
            'hint' => '可留空',
        ],
        [
            'key' => 'future_rank',
            'block' => 'future',
            'type' => 'rank',
            'required' => true,
            'quick' => false,
            'title' => '下面几个方向，请按你的期待程度排序',
            'hint' => '点一下标 1，依次点出 2、3；再点一次取消',
            'options' => [
                ['key' => 'theme', 'label' => '更多主题与自定义背景'],
                ['key' => 'remind', 'label' => '上课提醒（上课前通过企业微信提醒我）'],
                ['key' => 'rankboard', 'label' => '课程数量排行榜（看看谁的课多课少）'],
            ],
        ],
        [
            'key' => 'future_none',
            'block' => 'future',
            'type' => 'single',
            'required' => false,
            'quick' => false,
            'title' => '以上哪一项你完全不需要？',
            'hint' => '可跳过',
            'options' => [
                ['key' => 'theme', 'label' => '更多主题与自定义背景'],
                ['key' => 'remind', 'label' => '上课提醒'],
                ['key' => 'rankboard', 'label' => '课程数量排行榜'],
                ['key' => 'none', 'label' => '都不错，没有不需要的'],
            ],
        ],
        [
            'key' => 'future_open',
            'block' => 'future',
            'type' => 'text',
            'required' => false,
            'quick' => false,
            'maxLength' => 120,
            'title' => '围绕课表本身，你还希望增加什么？',
            'hint' => '可留空',
        ],
        [
            'key' => 'recommend_score',
            'block' => 'recommend',
            'type' => 'scale',
            'min' => 0,
            'max' => 10,
            'required' => true,
            'quick' => true,
            'title' => '你有多大可能把课表推荐给同学？',
            'hint' => '0 = 完全不会，10 = 一定会',
        ],
        [
            'key' => 'coop_willing',
            'block' => 'coop',
            'type' => 'single',
            'required' => true,
            'quick' => true,
            'title' => '愿意参与后续调研，或提前试用新功能吗？',
            'options' => [
                ['key' => 'yes', 'label' => '愿意'],
                ['key' => 'no', 'label' => '不愿意'],
            ],
        ],
    ];
}

function surveyBlockDefinitions(): array
{
    return [
        'usage' => ['title' => '先了解一下你的使用情况', 'subtitle' => '1 题'],
        'experience' => ['title' => '当前产品体验', 'subtitle' => '你的真实感受对我们最重要'],
        'future' => ['title' => '未来方向', 'subtitle' => '我们按大家的选择排优先级'],
        'recommend' => ['title' => '推荐意愿', 'subtitle' => '1 题'],
        'coop' => ['title' => '共建意愿', 'subtitle' => '1 题'],
    ];
}

function surveyItemByKey(string $key): ?array
{
    foreach (surveyItemDefinitions() as $item) {
        if ($item['key'] === $key) {
            return $item;
        }
        if (isset($item['followUp']) && $item['followUp']['key'] === $key) {
            return $item['followUp'];
        }
    }
    return null;
}

function surveyQuickPool(): array
{
    $pool = [];
    foreach (surveyItemDefinitions() as $item) {
        if (!empty($item['quick'])) {
            $pool[] = $item['key'];
        }
    }
    return $pool;
}

/**
 * 快速问卷：基础题 + 从体验池按 (用户, 期次) 稳定抽 1 题 + 推荐/共建。
 * 用 crc32 保证同一用户刷新页面抽到同一题，避免前后不一致。
 */
function surveyQuickSelection(string $userId, string $roundKey): array
{
    $pool = surveyQuickPool();
    $index = $pool ? (crc32($userId . '|' . $roundKey) % count($pool)) : -1;
    $picked = $index >= 0 ? $pool[$index] : '';

    $keys = [];
    foreach (surveyItemDefinitions() as $item) {
        if ($item['block'] === 'usage') {
            $keys[] = $item['key'];
        }
    }
    if ($picked !== '') {
        $keys[] = $picked;
    }
    foreach (['recommend_score', 'coop_willing'] as $tail) {
        $keys[] = $tail;
    }
    return $keys;
}

function surveyFullSelection(): array
{
    $keys = [];
    foreach (surveyItemDefinitions() as $item) {
        $keys[] = $item['key'];
    }
    return $keys;
}

/**
 * 按题目 key 列表组装下发给前端的题目（含条件追问）。
 */
function surveyItemsForKeys(array $keys): array
{
    $definitions = [];
    foreach (surveyItemDefinitions() as $item) {
        $definitions[$item['key']] = $item;
    }

    $items = [];
    foreach ($keys as $key) {
        if (!isset($definitions[$key])) {
            continue;
        }
        $item = $definitions[$key];
        $entry = [
            'key' => $item['key'],
            'block' => $item['block'],
            'type' => $item['type'],
            'title' => $item['title'],
            'hint' => $item['hint'] ?? '',
            'required' => !empty($item['required']),
            'options' => $item['options'] ?? [],
        ];
        foreach (['max', 'min', 'maxLength'] as $field) {
            if (isset($item[$field])) {
                $entry[$field] = $item[$field];
            }
        }
        if (isset($item['followUp'])) {
            $followUp = $item['followUp'];
            $entry['followUp'] = [
                'key' => $followUp['key'],
                'type' => $followUp['type'],
                'title' => $followUp['title'],
                'hint' => $followUp['hint'] ?? '',
                'required' => !empty($followUp['required']),
                'options' => $followUp['options'] ?? [],
                'max' => $followUp['max'] ?? 0,
                'when' => $followUp['when'] ?? [],
            ];
        }
        $items[] = $entry;
    }
    return $items;
}

function surveyOptionKeys(array $item): array
{
    $keys = [];
    foreach ($item['options'] ?? [] as $option) {
        $keys[] = $option['key'];
    }
    return $keys;
}

/**
 * 校验并归一化单题答案。返回可直接入库的答案（string | array | int），失败返回 null 并写入 $error。
 */
function surveyNormalizeAnswer(array $item, $raw, string &$error)
{
    $type = $item['type'];
    $optionKeys = surveyOptionKeys($item);

    if ($type === 'text') {
        $text = trim((string) $raw);
        $maxLength = (int) ($item['maxLength'] ?? 120);
        $text = mb_substr($text, 0, $maxLength, 'UTF-8');
        if ($text === '' && !empty($item['required'])) {
            $error = '请填写后再提交';
            return null;
        }
        return $text;
    }

    if ($type === 'single') {
        $value = trim((string) $raw);
        if ($value === '') {
            if (!empty($item['required'])) {
                $error = '请选择一项';
                return null;
            }
            return '';
        }
        if (!in_array($value, $optionKeys, true)) {
            $error = '选项不正确';
            return null;
        }
        return $value;
    }

    if ($type === 'multi') {
        $values = is_array($raw) ? $raw : ($raw === '' || $raw === null ? [] : [$raw]);
        $values = array_values(array_unique(array_map(static function ($v) {
            return trim((string) $v);
        }, $values)));
        foreach ($values as $value) {
            if (!in_array($value, $optionKeys, true)) {
                $error = '选项不正确';
                return null;
            }
        }
        $max = (int) ($item['max'] ?? 0);
        if ($max > 0 && count($values) > $max) {
            $error = '最多选择 ' . $max . ' 项';
            return null;
        }
        if (!$values && !empty($item['required'])) {
            $error = '请至少选择一项';
            return null;
        }
        return $values;
    }

    if ($type === 'scale') {
        if ($raw === '' || $raw === null) {
            $error = '请选择分值';
            return null;
        }
        $value = (int) $raw;
        $min = (int) ($item['min'] ?? 0);
        $max = (int) ($item['max'] ?? 10);
        if ($value < $min || $value > $max) {
            $error = '分值超出范围';
            return null;
        }
        return $value;
    }

    if ($type === 'rank') {
        $values = is_array($raw) ? array_values($raw) : [];
        $values = array_values(array_filter(array_map(static function ($v) {
            return trim((string) $v);
        }, $values), static function ($v) {
            return $v !== '';
        }));
        if (!$values) {
            if (!empty($item['required'])) {
                $error = '请完成排序';
                return null;
            }
            return [];
        }
        if (count($values) !== count(array_unique($values))) {
            $error = '排序结果有重复';
            return null;
        }
        foreach ($values as $value) {
            if (!in_array($value, $optionKeys, true)) {
                $error = '排序选项不正确';
                return null;
            }
        }
        return $values;
    }

    $error = '题目类型不支持';
    return null;
}

/**
 * 统计一轮问卷的汇总数据（管理端查看）。
 */
function surveyRoundResults(mysqli $conn, int $roundId): array
{
    $counts = ['quick' => 0, 'full' => 0];
    $stmt = $conn->prepare('SELECT quiz_mode, COUNT(*) AS total FROM survey_responses WHERE round_id = ? GROUP BY quiz_mode');
    $stmt->bind_param('i', $roundId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $mode = (string) $row['quiz_mode'];
        if (isset($counts[$mode])) {
            $counts[$mode] = (int) $row['total'];
        }
    }
    $stmt->close();

    $anonymous = 0;
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM survey_responses WHERE round_id = ? AND verified = 0');
    $stmt->bind_param('i', $roundId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $anonymous = (int) ($row['total'] ?? 0);
    $stmt->close();

    $rows = [];
    $stmt = $conn->prepare('SELECT item_key, answer_json FROM survey_answers WHERE round_id = ? ORDER BY id');
    $stmt->bind_param('i', $roundId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    $byItem = [];
    $followUpParent = [];
    foreach (surveyItemDefinitions() as $item) {
        $entry = [
            'key' => $item['key'],
            'title' => $item['title'],
            'type' => $item['type'],
            'block' => $item['block'],
            'answered' => 0,
            'options' => [],
            'average' => null,
            'texts' => [],
            'rankAverage' => [],
        ];
        foreach ($item['options'] ?? [] as $option) {
            $entry['options'][$option['key']] = ['label' => $option['label'], 'count' => 0];
        }
        if (isset($item['followUp'])) {
            $followUp = $item['followUp'];
            $followUpParent[$followUp['key']] = $item['key'];
            $entry['followUp'] = [
                'key' => $followUp['key'],
                'title' => $followUp['title'],
                'answered' => 0,
                'options' => [],
            ];
            foreach ($followUp['options'] ?? [] as $option) {
                $entry['followUp']['options'][$option['key']] = ['label' => $option['label'], 'count' => 0];
            }
        }
        $byItem[$item['key']] = $entry;
    }

    foreach ($rows as $row) {
        $itemKey = (string) $row['item_key'];
        $answer = json_decode((string) $row['answer_json'], true);

        // 条件追问：答案归属到父题的 followUp 里展示
        if (isset($followUpParent[$itemKey])) {
            $parentKey = $followUpParent[$itemKey];
            $target = &$byItem[$parentKey]['followUp'];
            $target['answered']++;
            $values = is_array($answer) ? $answer : [$answer];
            foreach ($values as $value) {
                $value = (string) $value;
                if (isset($target['options'][$value])) {
                    $target['options'][$value]['count']++;
                }
            }
            unset($target);
            continue;
        }

        if (!isset($byItem[$itemKey])) {
            continue;
        }
        $target = &$byItem[$itemKey];
        $target['answered']++;

        if (in_array($target['type'], ['single', 'multi'], true)) {
            $values = is_array($answer) ? $answer : [$answer];
            foreach ($values as $value) {
                $value = (string) $value;
                if (isset($target['options'][$value])) {
                    $target['options'][$value]['count']++;
                }
            }
        } elseif ($target['type'] === 'scale') {
            $target['_sum'] = ($target['_sum'] ?? 0) + (int) $answer;
        } elseif ($target['type'] === 'rank') {
            $values = is_array($answer) ? $answer : [];
            foreach ($values as $position => $value) {
                $value = (string) $value;
                if (!isset($target['rankAverage'][$value])) {
                    $target['rankAverage'][$value] = ['total' => 0, 'count' => 0];
                }
                $target['rankAverage'][$value]['total'] += ($position + 1);
                $target['rankAverage'][$value]['count']++;
            }
        } elseif ($target['type'] === 'text') {
            $text = trim((string) $answer);
            if ($text !== '' && count($target['texts']) < 60) {
                $target['texts'][] = $text;
            }
        }
        unset($target);
    }

    $items = [];
    foreach ($byItem as $entry) {
        if ($entry['type'] === 'scale' && $entry['answered'] > 0) {
            $entry['average'] = round(($entry['_sum'] ?? 0) / $entry['answered'], 2);
        }
        unset($entry['_sum']);
        if ($entry['type'] === 'rank') {
            $rankAverage = [];
            foreach ($entry['rankAverage'] as $key => $info) {
                $rankAverage[$key] = $info['count'] > 0 ? round($info['total'] / $info['count'], 2) : null;
            }
            $entry['rankAverage'] = $rankAverage;
        }
        $items[] = $entry;
    }

    return [
        'responses' => $counts,
        'totalResponses' => $counts['quick'] + $counts['full'],
        'anonymous' => $anonymous,
        'items' => $items,
    ];
}
