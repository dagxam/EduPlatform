<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$attemptId = (int)($data['attempt_id'] ?? 0);
$scoreRaw = $data['score'] ?? null;
$comment = trim((string)($data['comment'] ?? ''));

if ($attemptId < 1 || !is_numeric($scoreRaw)) {
    json_response(['ok' => false, 'error' => 'Укажите корректные баллы.'], 422);
}
if (function_exists('mb_strlen') ? mb_strlen($comment) > 2000 : strlen($comment) > 2000) {
    json_response(['ok' => false, 'error' => 'Комментарий слишком длинный. Максимум 2000 символов.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$attempt = result_attempt_for_staff($pdo, $user, $schoolId, $attemptId);

$maxScore = (float)($attempt['max_score'] ?? 0);
$score = round((float)$scoreRaw, 2);
if ($score < 0 || $score > $maxScore) {
    json_response([
        'ok' => false,
        'error' => 'Баллы должны быть от 0 до ' . rtrim(rtrim(number_format($maxScore, 2, '.', ''), '0'), '.') . '.',
    ], 422);
}

$percent = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;
$grade = grade_from_percent($percent);

$before = [
    'score' => $attempt['manual_score'],
    'percent' => $attempt['manual_percent'],
    'grade' => $attempt['manual_grade'],
    'comment' => $attempt['manual_comment'],
];

$stmt = $pdo->prepare(
    'UPDATE attempts
     SET manual_score = :score,
         manual_percent = :percent,
         manual_grade = :grade,
         manual_comment = :comment,
         manual_updated_at = CURRENT_TIMESTAMP,
         manual_updated_by = :user_id
     WHERE id = :attempt_id'
);
$stmt->execute([
    'score' => $score,
    'percent' => $percent,
    'grade' => $grade,
    'comment' => $comment !== '' ? $comment : null,
    'user_id' => (int)$user['id'],
    'attempt_id' => $attemptId,
]);

audit_event('attempt_result_draft_updated', 'attempt', $attemptId, [
    'student_id' => (int)$attempt['student_id'],
    'assignment_id' => (int)$attempt['assignment_id'],
    'before' => $before,
    'after' => [
        'score' => $score,
        'percent' => $percent,
        'grade' => $grade,
        'comment' => $comment,
    ],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'draft' => [
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => $grade,
        'comment' => $comment,
    ],
    'message' => 'Изменения сохранены как черновик. Ученик пока видит прежний опубликованный результат.',
]);
