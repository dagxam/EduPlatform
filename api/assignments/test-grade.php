<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];

if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);

$stmt = $pdo->prepare(
    'SELECT id, teacher_id, subject_id, title
     FROM assignments
     WHERE id = :id AND school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'school_id' => $schoolId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено.'], 404);
}

if (!can_manage_school($user, $schoolId) && (int)$assignment['teacher_id'] !== (int)$user['id']) {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM teacher_subjects
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => (int)$assignment['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
    }
}

$stmt = $pdo->prepare('SELECT id, points FROM questions WHERE assignment_id = :assignment_id ORDER BY position, id');
$stmt->execute(['assignment_id' => $assignmentId]);
$questionRows = $stmt->fetchAll();
$questionIds = array_map('intval', array_column($questionRows, 'id'));
$maxScore = array_sum(array_map('floatval', array_column($questionRows, 'points')));

$answersByQuestion = [];
foreach ($answers as $answer) {
    if (!is_array($answer)) continue;
    $questionId = (int)($answer['question_id'] ?? 0);
    if ($questionId < 1 || !in_array($questionId, $questionIds, true)) continue;
    $answersByQuestion[$questionId] = is_array($answer['payload'] ?? null) ? $answer['payload'] : [];
}

$score = 0.0;
$needsReview = 0;
$results = [];
foreach ($questionIds as $questionId) {
    $graded = grade_question_answer($pdo, $questionId, $answersByQuestion[$questionId] ?? []);
    $score += (float)$graded['score'];
    $needsReview = max($needsReview, (int)$graded['needs_review']);
    $results[(string)$questionId] = [
        'score' => (float)$graded['score'],
        'is_correct' => (int)$graded['is_correct'] === 1,
        'needs_review' => (int)$graded['needs_review'] === 1,
    ];
}

$percent = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;

audit_event('assignment_test_run', 'assignment', $assignmentId, [
    'score' => $score,
    'max_score' => $maxScore,
    'percent' => $percent,
    'not_saved_as_attempt' => true,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'result' => [
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => grade_from_percent_for_school($pdo, $schoolId, $percent),
        'needs_review' => $needsReview === 1,
        'question_results' => $results,
        'test_mode' => true,
    ],
]);
