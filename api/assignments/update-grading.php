<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/questions/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$rawPoints = is_array($data['points'] ?? null) ? $data['points'] : [];

$pdo = app_db();
$assignment = question_editor_assignment($pdo, $user, $assignmentId, true);

$stmt = $pdo->prepare(
    'SELECT id, points
     FROM questions
     WHERE assignment_id = :assignment_id
       AND is_active = 1
     ORDER BY position, id'
);
$stmt->execute(['assignment_id' => $assignmentId]);
$questionRows = $stmt->fetchAll();
$questionIds = array_map('intval', array_column($questionRows, 'id'));
$currentPoints = [];
foreach ($questionRows as $row) {
    $currentPoints[(int)$row['id']] = (float)$row['points'];
}

if (!$questionIds) {
    json_response(['ok' => false, 'error' => 'В задании пока нет вопросов.'], 422);
}

$normalized = [];
foreach ($questionIds as $questionId) {
    $key = (string)$questionId;
    if (!array_key_exists($key, $rawPoints) && !array_key_exists($questionId, $rawPoints)) {
        json_response(['ok' => false, 'error' => 'Укажите баллы для каждого вопроса.'], 422);
    }
    $value = $rawPoints[$key] ?? $rawPoints[$questionId];
    if (!is_numeric($value)) {
        json_response(['ok' => false, 'error' => 'Баллы должны быть числом.'], 422);
    }
    $points = round((float)$value, 2);
    if ($points <= 0 || $points > 1000) {
        json_response(['ok' => false, 'error' => 'Баллы за вопрос должны быть больше 0 и не более 1000.'], 422);
    }
    $normalized[$questionId] = $points;
}

$hasHistory = (int)($assignment['attempts_count'] ?? 0) > 0;
$effectivePoints = [];
$revisedQuestions = [];

$update = $pdo->prepare(
    'UPDATE questions
     SET points = :points
     WHERE id = :question_id
       AND assignment_id = :assignment_id
       AND is_active = 1'
);

$pdo->beginTransaction();
try {
    foreach ($normalized as $questionId => $points) {
        $oldPoints = (float)($currentPoints[$questionId] ?? 0);
        if ($hasHistory && abs($oldPoints - $points) > 0.000001) {
            $newQuestionId = question_editor_fork_revision($pdo, $questionId, ['points' => $points]);
            $effectivePoints[$newQuestionId] = $points;
            $revisedQuestions[$questionId] = $newQuestionId;
        } else {
            $update->execute([
                'points' => $points,
                'question_id' => $questionId,
                'assignment_id' => $assignmentId,
            ]);
            $effectivePoints[$questionId] = $points;
        }
    }
    $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $assignmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('assignment_grading_updated', 'assignment', $assignmentId, [
    'question_points' => $effectivePoints,
    'revised_questions' => $revisedQuestions,
    'max_score' => round(array_sum($effectivePoints), 2),
], (int)$assignment['school_id'], (int)$user['id']);

json_response([
    'ok' => true,
    'max_score' => round(array_sum($effectivePoints), 2),
    'message' => 'Баллы за вопросы сохранены.',
]);
