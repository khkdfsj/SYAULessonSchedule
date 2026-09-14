<?php
declare(strict_types=1);

function ensureScheduleAdjustmentSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS schedule_adjustment_plans (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            plan_key varchar(96) NOT NULL,
            name varchar(120) NOT NULL,
            semester_mark varchar(32) NOT NULL,
            notice_title varchar(200) NOT NULL DEFAULT '',
            notice_url varchar(500) NOT NULL DEFAULT '',
            status enum('draft','published','archived') NOT NULL DEFAULT 'draft',
            published_at datetime DEFAULT NULL,
            created_by varchar(20) NOT NULL,
            updated_by varchar(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_adjustment_plan_key (plan_key),
            KEY idx_adjustment_plan_status (semester_mark, status, published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS schedule_adjustment_rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint unsigned NOT NULL,
            education_type enum('undergraduate','graduate') NOT NULL,
            entry_year smallint unsigned NOT NULL DEFAULT 0,
            source_week tinyint unsigned NOT NULL,
            source_weekday tinyint unsigned NOT NULL,
            target_week tinyint unsigned NOT NULL,
            target_weekday tinyint unsigned NOT NULL,
            sort_order smallint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_adjustment_rule_plan (plan_id, sort_order, id),
            CONSTRAINT fk_adjustment_rule_plan FOREIGN KEY (plan_id)
                REFERENCES schedule_adjustment_plans (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    seedOfficialScheduleAdjustmentPlan($conn);
}

function seedOfficialScheduleAdjustmentPlan(mysqli $conn): void
{
    $planKey = 'official-20260910-mid-autumn-national-day';
    $stmt = $conn->prepare('SELECT id FROM schedule_adjustment_plans WHERE plan_key = ? LIMIT 1');
    $stmt->bind_param('s', $planKey);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) {
        return;
    }

    $name = '2026年中秋国庆假期前后课程调整';
    $semesterMark = '2026-fall';
    $noticeTitle = '关于中秋国庆假期前后课程安排的通知';
    $noticeUrl = 'https://jwc.syau.edu.cn/info/1248/10613.htm';
    $adminUser = '2023195077';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO schedule_adjustment_plans
                (plan_key, name, semester_mark, notice_title, notice_url, status, published_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, 'published', NOW(), ?, ?)"
        );
        $stmt->bind_param('sssssss', $planKey, $name, $semesterMark, $noticeTitle, $noticeUrl, $adminUser, $adminUser);
        $stmt->execute();
        $planId = (int) $stmt->insert_id;
        $stmt->close();

        // 9月21日至22日仅为2026级本科新生；10月8日至10日面向全体学生。
        $rules = [
            ['undergraduate', 2026, 9, 1, 5, 1],
            ['undergraduate', 2026, 9, 2, 5, 2],
            ['undergraduate', 0, 9, 4, 7, 4],
            ['graduate', 0, 9, 4, 7, 4],
            ['undergraduate', 0, 9, 5, 7, 5],
            ['graduate', 0, 9, 5, 7, 5],
            ['undergraduate', 0, 9, 3, 7, 6],
            ['graduate', 0, 9, 3, 7, 6],
        ];
        $stmt = $conn->prepare(
            'INSERT INTO schedule_adjustment_rules
                (plan_id, education_type, entry_year, source_week, source_weekday, target_week, target_weekday, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rules as $index => $rule) {
            [$educationType, $entryYear, $sourceWeek, $sourceWeekday, $targetWeek, $targetWeekday] = $rule;
            $sortOrder = $index + 1;
            $stmt->bind_param('isiiiiii', $planId, $educationType, $entryYear, $sourceWeek, $sourceWeekday, $targetWeek, $targetWeekday, $sortOrder);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function scheduleAdjustmentStudentType(string $userId): string
{
    $identityDigit = substr($userId, 4, 1);
    if ($identityDigit === '1') {
        return 'undergraduate';
    }
    if ($identityDigit === '2') {
        return 'graduate';
    }
    return '';
}

function scheduleAdjustmentDate(string $semesterStartDate, int $week, int $weekday): string
{
    if ($semesterStartDate === '' || $week < 1 || $weekday < 1 || $weekday > 7) {
        return '';
    }
    try {
        $date = new DateTimeImmutable($semesterStartDate);
        return $date->modify('+' . (((($week - 1) * 7) + $weekday - 1)) . ' days')->format('Y-m-d');
    } catch (Throwable $error) {
        return '';
    }
}

function applyPublishedScheduleAdjustments(
    mysqli $conn,
    string $userId,
    array $courses,
    string $semesterMark,
    string $semesterStartDate
): array {
    $educationType = scheduleAdjustmentStudentType($userId);
    if ($educationType === '' || $semesterMark === '') {
        return array_values($courses);
    }

    $entryYear = (int) substr($userId, 0, 4);
    $rules = [];
    try {
        $stmt = $conn->prepare(
            "SELECT r.id, r.plan_id, r.education_type, r.entry_year,
                    r.source_week, r.source_weekday, r.target_week, r.target_weekday,
                    p.name AS plan_name, p.notice_title, p.notice_url
             FROM schedule_adjustment_rules r
             INNER JOIN schedule_adjustment_plans p ON p.id = r.plan_id
             WHERE p.status = 'published' AND p.semester_mark = ?
               AND r.education_type = ? AND (r.entry_year = 0 OR r.entry_year = ?)
             ORDER BY p.id, r.sort_order, r.id"
        );
        $stmt->bind_param('ssi', $semesterMark, $educationType, $entryYear);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rules[] = $row;
        }
        $stmt->close();
    } catch (Throwable $error) {
        // 调课表尚未初始化或临时不可用时，课表主业务必须继续返回原始数据。
        error_log('schedule adjustment lookup failed: ' . $error->getMessage());
        return array_values($courses);
    }
    if (!$rules) {
        return array_values($courses);
    }

    $adjusted = array_values($courses);
    $seen = [];
    foreach ($rules as $rule) {
        $sourceWeek = (int) $rule['source_week'];
        $sourceWeekday = (int) $rule['source_weekday'];
        $targetWeek = (int) $rule['target_week'];
        $targetWeekday = (int) $rule['target_weekday'];
        foreach ($courses as $courseIndex => $course) {
            if (!is_array($course) || (int) ($course['week'] ?? 0) !== $sourceWeekday) {
                continue;
            }
            $weeks = isset($course['weeks']) && is_array($course['weeks']) ? array_map('intval', $course['weeks']) : [];
            if (!in_array($sourceWeek, $weeks, true)) {
                continue;
            }
            $courseIdentity = implode('|', [
                (string) ($course['num'] ?? ''),
                (string) ($course['courseOrder'] ?? ''),
                (string) ($course['teacherUserID'] ?? ''),
                (string) ($course['section'] ?? ''),
                (string) $courseIndex,
            ]);
            $dedupeKey = $rule['id'] . '|' . $courseIdentity;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            // 同一门课本来就在目标周同一天出现时，用带“调课”标记的副本替代该周原记录，
            // 避免两张完全相同的课程卡片重叠；其余不同课程仍保留并由前端标记冲突。
            if ($sourceWeekday === $targetWeekday && in_array($targetWeek, $weeks, true) && isset($adjusted[$courseIndex])) {
                $remainingWeeks = array_values(array_filter(
                    $weeks,
                    static function ($week) use ($targetWeek) {
                        return (int) $week !== $targetWeek;
                    }
                ));
                if ($remainingWeeks) {
                    $adjusted[$courseIndex]['weeks'] = $remainingWeeks;
                } else {
                    unset($adjusted[$courseIndex]);
                }
            }

            $copy = $course;
            $copy['id'] = 'adjustment-' . $rule['id'] . '-' . ($course['id'] ?? ($courseIndex + 1));
            $copy['week'] = (string) $targetWeekday;
            $copy['weeks'] = [$targetWeek];
            $copy['weekText'] = '第' . $targetWeek . '周（调课）';
            $copy['isScheduleAdjustment'] = true;
            $copy['scheduleAdjustment'] = [
                'ruleId' => (int) $rule['id'],
                'planId' => (int) $rule['plan_id'],
                'planName' => (string) $rule['plan_name'],
                'noticeTitle' => (string) $rule['notice_title'],
                'noticeUrl' => (string) $rule['notice_url'],
                'sourceWeek' => $sourceWeek,
                'sourceWeekday' => $sourceWeekday,
                'targetWeek' => $targetWeek,
                'targetWeekday' => $targetWeekday,
                'sourceDate' => scheduleAdjustmentDate($semesterStartDate, $sourceWeek, $sourceWeekday),
                'targetDate' => scheduleAdjustmentDate($semesterStartDate, $targetWeek, $targetWeekday),
            ];
            $adjusted[] = $copy;
        }
    }
    return array_values($adjusted);
}
