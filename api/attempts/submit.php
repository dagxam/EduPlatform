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

$result = finalize_attempt($pdo, $attemptId, 'student_submit');
audit_event('attempt_submitted', 'attempt', $attemptId, [], null, (int)$user['id']);

json_response(['ok' => true, 'result' => $result]);
