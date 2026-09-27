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
$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);

if ($attempt['status'] !== 'in_progress') {
    json_response([
        'ok' => true,
        'already_submitted' => true,
        'result' => [
            'score' => (float)$attempt['score'],
            'max_score' => (float)$attempt['max_score'],
            'percent' => (float)$attempt['percent'],
            'grade' => $attempt['grade'],
            'status' => $attempt['status'],
            'termination_reason' => $attempt['termination_reason'],
        ],
    ]);
}

$expiredResult = finalize_expired_attempt($pdo, $attempt);
if ($expiredResult !== null) {
    audit_event('attempt_auto_submitted', 'attempt', $attemptId, ['reason' => 'time_limit'], null, (int)$user['id']);
    json_response(['ok' => true, 'result' => $expiredResult, 'reason' => 'time_limit']);
}

// Repair missing answer keys in older imported assignments before the final pass.
$stmt = $pdo->prepare('SELECT school_id FROM assignments WHERE id = :assignment_id LIMIT 1');
$stmt->execute(['assignment_id' => (int)$attempt['assignment_id']]);
$assignmentSchoolId = $stmt->fetchColumn();
repair_imported_answer_keys_and_scores(
    $pdo,
    (int)$user['id'],
    $assignmentSchoolId !== false ? (int)$assignmentSchoolId : null
);

// Persist one final snapshot of every answer from the visible form.
// This prevents a fast submit from racing with individual autosave requests.
$answersSnapshot = is_array($data['answers'] ?? null) ? $data['answers'] : [];
foreach ($answersSnapshot as $answer) {
    if (!is_array($answer)) continue;
    $questionId = (int)($answer['question_id'] ?? 0);
    $payload = is_array($answer['payload'] ?? null) ? $answer['payload'] : [];
    if ($questionId < 1) continue;
    save_attempt_answer($pdo, $attemptId, $questionId, $payload);
}

$result = finalize_attempt($pdo, $attemptId, 'student_submit');
audit_event('attempt_submitted', 'attempt', $attemptId, [], null, (int)$user['id']);

json_response(['ok' => true, 'result' => $result]);
