<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$user = require_user(['student']);
$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$pdo = app_db();
$studentId = (int)$user['id'];

$stmt = $pdo->prepare(
    'SELECT ass.id,
            ass.max_attempts,
            ass.focus_policy,
            COALESCE(ast.time_limit_minutes, ac.time_limit_minutes, ass.time_limit_minutes) AS time_limit_minutes
     FROM assignments ass
     LEFT JOIN class_students cs ON cs.student_id = :student_id_class
     LEFT JOIN assignment_classes ac
       ON ac.assignment_id = ass.id
      AND ac.class_id = cs.class_id
     LEFT JOIN assignment_students ast
       ON ast.assignment_id = ass.id
      AND ast.student_id = :student_id_personal
     WHERE ass.id = :assignment_id
       AND ass.status = "published"
       AND (ac.assignment_id IS NOT NULL OR ast.assignment_id IS NOT NULL)
     LIMIT 1'
);
$stmt->execute([
    'assignment_id' => $assignmentId,
    'student_id_class' => $studentId,
    'student_id_personal' => $studentId,
]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание недоступно для этого ученика.'], 403);
}

$sessionHash = hash('sha256', session_id());

try {
    $pdo->beginTransaction();

    // Different browser sessions of the same student can hit Start at the same
    // moment. Lock the student row on MySQL so only one request may decide
    // whether to create the active attempt.
    if (db_is_mysql($pdo)) {
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :student_id FOR UPDATE');
        $lock->execute(['student_id' => $studentId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('Ученик не найден.');
        }
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM attempts
         WHERE assignment_id = :assignment_id AND student_id = :student_id AND status = "in_progress"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if (!empty($existing['attempt_session_hash']) && !hash_equals((string)$existing['attempt_session_hash'], $sessionHash)) {
            $pdo->rollBack();
            json_response([
                'ok' => false,
                'error' => 'Эта работа уже открыта на другом устройстве или в другой сессии.',
                'code' => 'ATTEMPT_ALREADY_ACTIVE',
            ], 409);
        }

        $pdo->commit();
        json_response([
            'ok' => true,
            'attempt' => [
                'id' => (int)$existing['id'],
                'focus_policy' => $assignment['focus_policy'],
                'time_limit_minutes' => $existing['time_limit_snapshot'] !== null
                    ? (int)$existing['time_limit_snapshot']
                    : ($assignment['time_limit_minutes'] !== null ? (int)$assignment['time_limit_minutes'] : null),
                'variant_label' => (string)($existing['variant_label'] ?? ''),
                'variant_index' => (int)($existing['variant_index'] ?? 0),
                'resumed' => true,
            ],
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attempts
         WHERE assignment_id = :assignment_id AND student_id = :student_id AND status <> "in_progress"'
    );
    $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
    $completedCount = (int)$stmt->fetchColumn();
    if ($completedCount >= (int)$assignment['max_attempts']) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Доступные попытки закончились.'], 409);
    }

    $variant = build_attempt_variant($pdo, $assignmentId, $studentId);

    $stmt = $pdo->prepare(
        'INSERT INTO attempts
         (assignment_id, student_id, last_seen_at, attempt_session_hash,
          variant_index, variant_label, question_order_json, option_order_json, structured_order_json,
          time_limit_snapshot)
         VALUES
         (:assignment_id, :student_id, CURRENT_TIMESTAMP, :session_hash,
          :variant_index, :variant_label, :question_order_json, :option_order_json, :structured_order_json,
          :time_limit_snapshot)'
    );
    $stmt->execute([
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
        'session_hash' => $sessionHash,
        'variant_index' => (int)$variant['variant_index'],
        'variant_label' => (string)$variant['variant_label'],
        'question_order_json' => $variant['question_order_json'],
        'option_order_json' => $variant['option_order_json'],
        'structured_order_json' => $variant['structured_order_json'],
        'time_limit_snapshot' => $assignment['time_limit_minutes'] !== null
            ? (int)$assignment['time_limit_minutes']
            : null,
    ]);
    $attemptId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

audit_event('attempt_started', 'attempt', $attemptId, [
    'assignment_id' => $assignmentId,
    'variant_label' => (string)$variant['variant_label'],
], null, $studentId);

json_response([
    'ok' => true,
    'attempt' => [
        'id' => $attemptId,
        'focus_policy' => $assignment['focus_policy'],
        'time_limit_minutes' => $assignment['time_limit_minutes'] !== null ? (int)$assignment['time_limit_minutes'] : null,
        'variant_label' => (string)$variant['variant_label'],
        'variant_index' => (int)$variant['variant_index'],
        'resumed' => false,
    ],
], 201);
