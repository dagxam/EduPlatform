<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);
if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$schoolId = require_active_school($user, false);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT id, teacher_id, school_id, subject_id, title, status
     FROM assignments
     WHERE id = :id AND school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'id' => $assignmentId,
    'school_id' => $schoolId,
]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено в выбранной школе.'], 404);
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

$stmt = $pdo->prepare(
    'SELECT q.id, q.type, q.interaction_type, q.text, q.points, q.position,
            q.correct_text, q.settings_json
     FROM questions q
     WHERE q.assignment_id = :assignment_id
     ORDER BY q.position, q.id'
);
$stmt->execute(['assignment_id' => $assignmentId]);
$questions = $stmt->fetchAll();

$optionStmt = $pdo->prepare(
    'SELECT id, text, is_correct, position
     FROM question_options
     WHERE question_id = :question_id
     ORDER BY position, id'
);
$assetStmt = $pdo->prepare(
    'SELECT id, original_name, mime_type, position
     FROM question_assets
     WHERE question_id = :question_id
     ORDER BY position, id'
);

foreach ($questions as &$question) {
    $optionStmt->execute(['question_id' => (int)$question['id']]);
    $question['options'] = $optionStmt->fetchAll();

    $assetStmt->execute(['question_id' => (int)$question['id']]);
    $assets = $assetStmt->fetchAll();
    foreach ($assets as &$asset) {
        $asset['url'] = './api/questions/asset.php?id=' . (int)$asset['id'];
    }
    unset($asset);
    $question['assets'] = $assets;

    $settings = json_decode((string)($question['settings_json'] ?? ''), true);
    $question['settings'] = is_array($settings) ? $settings : [];
    unset($question['settings_json']);

    // Ответы нужны только преподавателю/администратору в режиме проверки черновика.
    $question['correct_text'] = $question['correct_text'] !== null ? (string)$question['correct_text'] : null;
}
unset($question);

json_response([
    'ok' => true,
    'assignment' => [
        'id' => (int)$assignment['id'],
        'title' => (string)$assignment['title'],
        'status' => (string)$assignment['status'],
    ],
    'questions' => $questions,
]);
