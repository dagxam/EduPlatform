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
$questionId = (int)($data['question_id'] ?? 0);
$payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

if ($attemptId < 1 || $questionId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан результат или вопрос.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$attempt = result_attempt_for_staff($pdo, $user, $schoolId, $attemptId);

$stmt = $pdo->prepare(
    'SELECT q.id
     FROM questions q
     WHERE q.id = :question_id
       AND q.assignment_id = :assignment_id
     LIMIT 1'
);
$stmt->execute([
    'question_id' => $questionId,
    'assignment_id' => (int)$attempt['assignment_id'],
]);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Вопрос не относится к работе этого ученика.'], 422);
}

$graded = grade_question_answer($pdo, $questionId, $payload);

$upsert = $pdo->prepare(
    'INSERT INTO answers
        (attempt_id, question_id, answer_text, score, is_correct, needs_review, updated_at)
     VALUES
        (:attempt_id, :question_id, :answer_text, :score, :is_correct, :needs_review, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['attempt_id', 'question_id'],
        ['answer_text', 'score', 'is_correct', 'needs_review', 'updated_at']
    )
);

$pdo->beginTransaction();
try {
    $upsert->execute([
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'answer_text' => $graded['answer_text'],
        'score' => $graded['score'],
        'is_correct' => $graded['is_correct'],
        'needs_review' => $graded['needs_review'],
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$reason = trim((string)($attempt['termination_reason'] ?? ''));
$result = finalize_attempt($pdo, $attemptId, $reason !== '' ? $reason : null);

audit_event('attempt_answer_corrected', 'attempt', $attemptId, [
    'student_id' => (int)$attempt['student_id'],
    'assignment_id' => (int)$attempt['assignment_id'],
    'question_id' => $questionId,
    'answer_text' => $graded['answer_text'],
    'score' => (float)$graded['score'],
    'is_correct' => (int)$graded['is_correct'],
    'needs_review' => (int)$graded['needs_review'],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'answer' => [
        'question_id' => $questionId,
        'score' => (float)$graded['score'],
        'is_correct' => (int)$graded['is_correct'] === 1,
        'needs_review' => (int)$graded['needs_review'] === 1,
    ],
    'automatic_result' => $result,
    'message' => 'Ответ ученика изменён и результат пересчитан.',
]);
