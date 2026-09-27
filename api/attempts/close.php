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
if (!in_array($finishReason, ['page_hidden', 'page_closed', 'browser_closed'], true)) {
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

// Before grading the final snapshot, repair legacy imported answer keys.
$stmt = $pdo->prepare('SELECT school_id FROM assignments WHERE id = :assignment_id LIMIT 1');
$stmt->execute(['assignment_id' => (int)$attempt['assignment_id']]);
$assignmentSchoolId = $stmt->fetchColumn();
repair_imported_answer_keys_and_scores(
    $pdo,
    (int)$user['id'],
    $assignmentSchoolId !== false ? (int)$assignmentSchoolId : null
);

$answersSnapshot = is_array($data['answers'] ?? null) ? $data['answers'] : [];
foreach ($answersSnapshot as $answer) {
    if (!is_array($answer)) continue;
    $questionId = (int)($answer['question_id'] ?? 0);
    $payload = is_array($answer['payload'] ?? null) ? $answer['payload'] : [];
    if ($questionId < 1) continue;
    save_attempt_answer($pdo, $attemptId, $questionId, $payload);
}

$result = finalize_attempt($pdo, $attemptId, $finishReason);
audit_event('attempt_submitted', 'attempt', $attemptId, [
    'reason' => $finishReason,
    'submitted_answers' => count($answersSnapshot),
], null, (int)$user['id']);

json_response([
    'ok' => true,
    'result' => $result,
    'reason' => $finishReason,
]);
