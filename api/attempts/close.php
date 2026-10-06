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
$finishReason = trim((string)($data['finish_reason'] ?? 'page_closed'));
if (!in_array($finishReason, ['page_hidden', 'page_closed', 'browser_closed', 'window_blur'], true)) {
    $finishReason = 'page_closed';
}
if ($attemptId < 1) {
    json_response(['ok' => false, 'error' => 'Не указана попытка.'], 422);
}

$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);

if ((string)$attempt['status'] !== 'in_progress') {
    json_response([
        'ok' => true,
        'already_submitted' => true,
        'status' => (string)$attempt['status'],
    ]);
}

$expiredResult = finalize_expired_attempt($pdo, $attempt);
if ($expiredResult !== null) {
    audit_event('attempt_auto_submitted', 'attempt', $attemptId, [
        'reason' => 'time_limit',
    ], null, (int)$user['id']);
    json_response(['ok' => true, 'result' => $expiredResult, 'reason' => 'time_limit']);
}

$answersSnapshot = is_array($data['answers'] ?? null) ? $data['answers'] : [];
$savedSnapshotAnswers = save_attempt_answers_snapshot($pdo, $attemptId, $answersSnapshot);

$result = finalize_attempt($pdo, $attemptId, $finishReason);
audit_event('attempt_submitted', 'attempt', $attemptId, [
    'reason' => $finishReason,
    'submitted_answers' => $savedSnapshotAnswers,
], null, (int)$user['id']);

json_response([
    'ok' => true,
    'result' => $result,
    'reason' => $finishReason,
]);
