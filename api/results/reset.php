<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$attemptId = (int)($data['attempt_id'] ?? 0);
if ($attemptId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан результат.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$attempt = result_attempt_for_staff($pdo, $user, $schoolId, $attemptId);

$before = [
    'student_id' => (int)$attempt['student_id'],
    'assignment_id' => (int)$attempt['assignment_id'],
    'score' => (float)($attempt['score'] ?? 0),
    'max_score' => (float)($attempt['max_score'] ?? 0),
    'percent' => (float)($attempt['percent'] ?? 0),
    'grade' => (string)($attempt['grade'] ?? ''),
    'revision' => (int)($attempt['result_revision'] ?? 0),
];

$stmt = $pdo->prepare('DELETE FROM attempts WHERE id = :attempt_id');
$stmt->execute(['attempt_id' => $attemptId]);
if ($stmt->rowCount() !== 1) {
    json_response(['ok' => false, 'error' => 'Не удалось сбросить результат.'], 409);
}

audit_event('attempt_result_reset', 'assignment', (int)$attempt['assignment_id'], [
    'student_id' => (int)$attempt['student_id'],
    'previous_score' => $before['score'],
    'previous_max_score' => $before['max_score'],
    'previous_percent' => $before['percent'],
    'previous_grade' => $before['grade'],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'message' => 'Результат сброшен. Ученик может выполнить задание заново.',
]);
