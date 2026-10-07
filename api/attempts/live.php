<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);

// Periodically clean orphaned attempts for this school. This keeps the live
// monitor and subsequent student starts consistent even when Android/browser
// shutdown prevented the close beacon from reaching the server.
finalize_stale_attempts($pdo, null, null, $schoolId, 120);

$conditions = [
    'at.status = "in_progress"',
    'ass.school_id = :school_id',
];
$params = ['school_id' => $schoolId];

if (!can_manage_school($user, $schoolId) && !is_platform_admin($user)) {
    $conditions[] =
        '(ass.teacher_id = :staff_user_id
          OR EXISTS (
              SELECT 1
              FROM teacher_subjects ts
              WHERE ts.school_id = ass.school_id
                AND ts.teacher_id = :subject_staff_user_id
                AND ts.subject_id = ass.subject_id
          ))';
    $params['staff_user_id'] = (int)$user['id'];
    $params['subject_staff_user_id'] = (int)$user['id'];
}

$sql =
    'SELECT at.id,
            at.assignment_id,
            at.student_id,
            at.started_at,
            at.last_seen_at,
            at.time_limit_snapshot,
            at.question_order_json,
            ass.title AS assignment_title,
            ass.time_limit_minutes AS assignment_time_limit_minutes,
            s.name AS subject_name,
            u.first_name,
            u.last_name,
            u.middle_name,
            (
                SELECT COALESCE(c.display_name, c.name)
                FROM class_students cs
                JOIN classes c ON c.id = cs.class_id
                WHERE cs.student_id = at.student_id
                  AND c.school_id = ass.school_id
                ORDER BY c.id
                LIMIT 1
            ) AS class_name,
            (
                SELECT COUNT(*)
                FROM answers ans
                WHERE ans.attempt_id = at.id
                  AND TRIM(COALESCE(ans.answer_text, "")) <> ""
            ) AS answered_count
     FROM attempts at
     JOIN assignments ass ON ass.id = at.assignment_id
     JOIN users u ON u.id = at.student_id
     LEFT JOIN subjects s ON s.id = ass.subject_id
     WHERE ' . implode(' AND ', $conditions) . '
     ORDER BY COALESCE(at.last_seen_at, at.started_at) DESC, at.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$now = time();
$activeWindowSeconds = 90;
$items = [];

foreach ($rows as $row) {
    $lastSeenRaw = trim((string)($row['last_seen_at'] ?? ''));
    $startedRaw = trim((string)($row['started_at'] ?? ''));
    $heartbeatAt = $lastSeenRaw !== '' ? $lastSeenRaw : $startedRaw;
    $heartbeatTs = $heartbeatAt !== '' ? strtotime($heartbeatAt . ' UTC') : false;

    if ($heartbeatTs === false || ($now - $heartbeatTs) > $activeWindowSeconds) {
        continue;
    }

    $startedTs = $startedRaw !== '' ? strtotime($startedRaw . ' UTC') : false;
    $elapsedSeconds = $startedTs === false ? 0 : max(0, $now - $startedTs);
    $questionOrder = json_decode((string)($row['question_order_json'] ?? ''), true);
    $questionCount = is_array($questionOrder) ? count($questionOrder) : 0;
    $timeLimit = $row['time_limit_snapshot'] !== null
        ? (int)$row['time_limit_snapshot']
        : ($row['assignment_time_limit_minutes'] !== null
            ? (int)$row['assignment_time_limit_minutes']
            : null);

    $items[] = [
        'attempt_id' => (int)$row['id'],
        'assignment_id' => (int)$row['assignment_id'],
        'student_id' => (int)$row['student_id'],
        'student_name' => trim(
            (string)($row['last_name'] ?? '') . ' ' .
            (string)($row['first_name'] ?? '') . ' ' .
            (string)($row['middle_name'] ?? '')
        ),
        'class_name' => trim((string)($row['class_name'] ?? '')) ?: 'Без класса',
        'assignment_title' => (string)($row['assignment_title'] ?? ''),
        'subject_name' => (string)($row['subject_name'] ?? ''),
        'started_at' => $row['started_at'] ?? null,
        'last_seen_at' => $row['last_seen_at'] ?? null,
        'elapsed_seconds' => $elapsedSeconds,
        'last_seen_seconds_ago' => max(0, $now - $heartbeatTs),
        'answered_count' => (int)($row['answered_count'] ?? 0),
        'question_count' => $questionCount,
        'time_limit_minutes' => $timeLimit,
    ];
}

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'active_window_seconds' => $activeWindowSeconds,
    'count' => count($items),
    'items' => $items,
    'server_time' => gmdate('c'),
]);
