<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT ass.id, ass.max_attempts, ass.focus_policy, ass.time_limit_minutes
     FROM assignments ass
     WHERE ass.id = :assignment_id
       AND ass.status = "published"
       AND EXISTS (
          SELECT 1
          FROM assignment_classes ac
          JOIN class_students cs ON cs.class_id = ac.class_id
          WHERE ac.assignment_id = ass.id AND cs.student_id = :student_id
       )
     LIMIT 1'
);
$stmt->execute(['assignment_id' => $assignmentId, 'student_id' => (int)$user['id']]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание недоступно для этого ученика.'], 403);
}

$stmt = $pdo->prepare(
    'SELECT * FROM attempts
     WHERE assignment_id = :assignment_id AND student_id = :student_id AND status = "in_progress"
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute(['assignment_id' => $assignmentId, 'student_id' => (int)$user['id']]);
$existing = $stmt->fetch();

$sessionHash = hash('sha256', session_id());
if ($existing) {
    if (!empty($existing['attempt_session_hash']) && !hash_equals((string)$existing['attempt_session_hash'], $sessionHash)) {
        json_response([
            'ok' => false,
            'error' => 'Эта работа уже открыта на другом устройстве или в другой сессии.',
            'code' => 'ATTEMPT_ALREADY_ACTIVE',
        ], 409);
    }

    json_response([
        'ok' => true,
        'attempt' => [
            'id' => (int)$existing['id'],
            'focus_policy' => $assignment['focus_policy'],
            'time_limit_minutes' => $assignment['time_limit_minutes'],
            'resumed' => true,
        ],
    ]);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM attempts
     WHERE assignment_id = :assignment_id AND student_id = :student_id AND status <> "in_progress"'
);
$stmt->execute(['assignment_id' => $assignmentId, 'student_id' => (int)$user['id']]);
$completedCount = (int)$stmt->fetchColumn();
if ($completedCount >= (int)$assignment['max_attempts']) {
    json_response(['ok' => false, 'error' => 'Доступные попытки закончились.'], 409);
}

$stmt = $pdo->prepare(
    'INSERT INTO attempts (assignment_id, student_id, last_seen_at, attempt_session_hash)
     VALUES (:assignment_id, :student_id, CURRENT_TIMESTAMP, :session_hash)'
);
$stmt->execute([
    'assignment_id' => $assignmentId,
    'student_id' => (int)$user['id'],
    'session_hash' => $sessionHash,
]);
$attemptId = (int)$pdo->lastInsertId();

audit_event('attempt_started', 'attempt', $attemptId, ['assignment_id' => $assignmentId], null, (int)$user['id']);

json_response([
    'ok' => true,
    'attempt' => [
        'id' => $attemptId,
        'focus_policy' => $assignment['focus_policy'],
        'time_limit_minutes' => $assignment['time_limit_minutes'],
        'resumed' => false,
    ],
], 201);
