<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран материал.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT li.*, sch.name AS school_name, s.name AS subject_name
     FROM library_items li
     JOIN schools sch ON sch.id = li.source_school_id
     LEFT JOIN subjects s ON s.id = li.subject_id
     WHERE li.id = :id
       AND (li.status = "published" OR li.source_school_id = :school_id)
     LIMIT 1'
);
$stmt->execute(['id' => $itemId, 'school_id' => $schoolId]);
$item = $stmt->fetch();
if (!$item) {
    json_response(['ok' => false, 'error' => 'Материал библиотеки не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT q.id, q.type, q.interaction_type, q.text, q.points, q.position
     FROM questions q
     WHERE q.assignment_id = :assignment_id
     ORDER BY q.position, q.id
     LIMIT 100'
);
$stmt->execute(['assignment_id' => (int)$item['source_assignment_id']]);
$questions = $stmt->fetchAll();

$optStmt = $pdo->prepare(
    'SELECT text, position FROM question_options
     WHERE question_id = :question_id
     ORDER BY position, id'
);
foreach ($questions as &$question) {
    $optStmt->execute(['question_id' => (int)$question['id']]);
    $question['options'] = $optStmt->fetchAll();
}
unset($question);

json_response([
    'ok' => true,
    'item' => [
        'id' => (int)$item['id'],
        'title' => (string)$item['title_snapshot'],
        'description' => $item['description_snapshot'],
        'school_name' => (string)$item['school_name'],
        'subject_name' => $item['subject_name'],
        'questions_count' => (int)$item['questions_count_snapshot'],
        'status' => (string)$item['status'],
    ],
    'questions' => $questions,
]);
