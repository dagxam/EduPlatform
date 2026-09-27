<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$attemptId = (int)($data['attempt_id'] ?? 0);
$questionId = (int)($data['question_id'] ?? 0);
if ($attemptId < 1 || $questionId < 1) {
    json_response(['ok' => false, 'error' => 'Некорректная попытка или вопрос.'], 422);
}

$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);
if ($attempt['status'] !== 'in_progress') {
    json_response(['ok' => false, 'error' => 'Эта работа уже завершена.'], 409);
}

$expiredResult = finalize_expired_attempt($pdo, $attempt);
if ($expiredResult !== null) {
    audit_event('attempt_auto_submitted', 'attempt', $attemptId, ['reason' => 'time_limit'], null, (int)$user['id']);
    json_response([
        'ok' => false,
        'error' => 'Время выполнения закончилось. Работа завершена автоматически.',
        'terminated' => true,
        'reason' => 'time_limit',
        'result' => $expiredResult,
    ], 409);
}

$graded = save_attempt_answer($pdo, $attemptId, $questionId, $data);
json_response([
    'ok' => true,
    'saved' => true,
    'needs_review' => (bool)$graded['needs_review'],
]);
