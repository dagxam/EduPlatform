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

if ($attempt['manual_score'] === null || !result_has_unpublished_draft($attempt)) {
    json_response(['ok' => false, 'error' => 'Сначала сохраните изменения результата.'], 409);
}

$revision = (int)($attempt['result_revision'] ?? 0) + 1;
$score = (float)$attempt['manual_score'];
$maxScore = (float)($attempt['max_score'] ?? 0);
$percent = (float)($attempt['manual_percent'] ?? 0);
$grade = (string)($attempt['manual_grade'] ?? '');
$comment = trim((string)($attempt['manual_comment'] ?? ''));

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE attempts
         SET published_score = :score,
             published_percent = :percent,
             published_grade = :grade,
             published_comment = :comment,
             result_published_at = CURRENT_TIMESTAMP,
             result_published_by = :user_id,
             result_revision = :revision,
             status = "submitted"
         WHERE id = :attempt_id'
    );
    $stmt->execute([
        'score' => $score,
        'percent' => $percent,
        'grade' => $grade,
        'comment' => $comment !== '' ? $comment : null,
        'user_id' => (int)$user['id'],
        'revision' => $revision,
        'attempt_id' => $attemptId,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO attempt_result_revisions
            (attempt_id, revision, score, max_score, percent, grade, comment, published_by)
         VALUES
            (:attempt_id, :revision, :score, :max_score, :percent, :grade, :comment, :published_by)'
    );
    $stmt->execute([
        'attempt_id' => $attemptId,
        'revision' => $revision,
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => $grade,
        'comment' => $comment !== '' ? $comment : null,
        'published_by' => (int)$user['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('attempt_result_published', 'attempt', $attemptId, [
    'student_id' => (int)$attempt['student_id'],
    'assignment_id' => (int)$attempt['assignment_id'],
    'revision' => $revision,
    'score' => $score,
    'max_score' => $maxScore,
    'percent' => $percent,
    'grade' => $grade,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'published' => [
        'revision' => $revision,
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => $grade,
        'comment' => $comment,
    ],
    'message' => 'Обновлённая оценка опубликована ученику.',
]);
