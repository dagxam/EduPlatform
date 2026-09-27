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
$eventType = trim((string)($data['event_type'] ?? ''));
if ($attemptId < 1 || !in_array($eventType, ['hidden', 'visible'], true)) {
    json_response(['ok' => false, 'error' => 'Некорректное событие безопасности.'], 422);
}

$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);
if ($attempt['status'] !== 'in_progress') {
    json_response([
        'ok' => true,
        'terminated' => true,
        'status' => (string)$attempt['status'],
        'reason' => $attempt['termination_reason'] ?? null,
        'result' => [
            'score' => (float)($attempt['score'] ?? 0),
            'max_score' => (float)($attempt['max_score'] ?? 0),
            'percent' => (float)($attempt['percent'] ?? 0),
            'grade' => (string)($attempt['grade'] ?? ''),
            'status' => (string)$attempt['status'],
            'termination_reason' => $attempt['termination_reason'] ?? null,
        ],
    ]);
}

$stmt = $pdo->prepare(
    'INSERT INTO attempt_security_events (attempt_id, event_type, details)
     VALUES (:attempt_id, :event_type, :details)'
);
$stmt->execute([
    'attempt_id' => $attemptId,
    'event_type' => $eventType,
    'details' => $eventType === 'hidden' ? 'document.visibilityState=hidden' : 'document.visibilityState=visible',
]);

if ($eventType === 'hidden') {
    $pdo->prepare(
        'UPDATE attempts
         SET focus_violations = focus_violations + 1, last_seen_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute(['id' => $attemptId]);

    audit_event('attempt_page_hidden', 'attempt', $attemptId, [], null, (int)$user['id']);
}

// Finalization is handled by close.php with a snapshot of the visible form.
// This endpoint only records visibility/security events, so it cannot race
// ahead and finish the attempt before the latest selected answer is saved.
json_response(['ok' => true, 'terminated' => false]);
