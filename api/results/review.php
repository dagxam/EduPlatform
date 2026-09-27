<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher', 'student']);
$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан результат для разбора.'], 422);
}

$pdo = app_db();
$role = (string)($user['role'] ?? '');

if ($role === 'student') {
    $stmt = $pdo->prepare(
        'SELECT at.*, a.school_id, a.teacher_id, a.title AS assignment_title, a.subject_id,
                s.name AS subject_name,
                u.first_name AS student_first_name, u.last_name AS student_last_name,
                COALESCE(c.display_name, c.name) AS class_name
         FROM attempts at
         JOIN assignments a ON a.id = at.assignment_id
         JOIN users u ON u.id = at.student_id
         LEFT JOIN subjects s ON s.id = a.subject_id
         LEFT JOIN class_students cs ON cs.student_id = at.student_id
         LEFT JOIN classes c ON c.id = cs.class_id
         WHERE at.id = :attempt_id
           AND at.student_id = :student_id
           AND at.status <> "in_progress"
         LIMIT 1'
    );
    $stmt->execute([
        'attempt_id' => $attemptId,
        'student_id' => (int)$user['id'],
    ]);
    $attempt = $stmt->fetch();
    if (!$attempt) {
        json_response(['ok' => false, 'error' => 'Завершённая работа не найдена.'], 404);
    }
} else {
    $schoolId = require_active_school($user, false);
    $attempt = result_attempt_for_staff($pdo, $user, $schoolId, $attemptId);
}

// Refresh automatic correctness using the current authoritative answer keys.
regrade_attempt_answers($pdo, $attemptId);

$questionOrder = array_map('intval', decoded_json_array($attempt['question_order_json'] ?? null));
$optionOrder = decoded_json_array($attempt['option_order_json'] ?? null);

$stmt = $pdo->prepare(
    'SELECT q.id, q.type, q.text, q.points, q.position, q.correct_text,
            q.interaction_type, q.settings_json
     FROM questions q
     WHERE q.assignment_id = :assignment_id
     ORDER BY q.position, q.id'
);
$stmt->execute(['assignment_id' => (int)$attempt['assignment_id']]);
$questionRows = $stmt->fetchAll();

$questionsById = [];
foreach ($questionRows as $row) {
    $questionsById[(int)$row['id']] = $row;
}
if (!$questionOrder) {
    $questionOrder = array_map('intval', array_keys($questionsById));
}

$stmt = $pdo->prepare(
    'SELECT question_id, answer_text, score, is_correct, needs_review
     FROM answers
     WHERE attempt_id = :attempt_id'
);
$stmt->execute(['attempt_id' => $attemptId]);
$answers = [];
foreach ($stmt->fetchAll() as $row) {
    $answers[(int)$row['question_id']] = $row;
}

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

$typeLabels = [
    'single' => 'Один правильный ответ',
    'multiple' => 'Несколько правильных ответов',
    'true_false' => 'Верно / неверно',
    'order' => 'Порядок',
    'matching' => 'Соответствие',
    'text' => 'Короткий ответ',
    'number' => 'Числовой ответ',
    'correction' => 'Исправление',
    'essay' => 'Развёрнутый ответ',
];

$items = [];
$correctCount = 0;
$incorrectCount = 0;
$unansweredCount = 0;
$reviewCount = 0;

foreach ($questionOrder as $questionId) {
    if (!isset($questionsById[$questionId])) continue;

    $row = $questionsById[$questionId];
    $interaction = (string)($row['interaction_type'] ?: $row['type']);
    if ($interaction === 'ordering') $interaction = 'order';
    if ($interaction === 'short_answer' || $interaction === 'image_answer') $interaction = 'text';

    $answer = $answers[$questionId] ?? null;
    $rawAnswer = (string)($answer['answer_text'] ?? '');
    $needsReview = (int)($answer['needs_review'] ?? 0) === 1;
    $hasAnswer = $answer !== null && trim($rawAnswer) !== '';

    $status = 'unanswered';
    if ($needsReview) {
        $status = 'review';
        $reviewCount++;
    } elseif ($answer !== null && (int)($answer['is_correct'] ?? 0) === 1) {
        $status = 'correct';
        $correctCount++;
    } elseif ($answer !== null) {
        $status = 'incorrect';
        $incorrectCount++;
    } else {
        $unansweredCount++;
    }

    $settings = json_decode((string)($row['settings_json'] ?? ''), true);
    $settings = is_array($settings) ? $settings : [];

    $studentAnswer = null;
    $correctAnswer = null;
    $options = [];
    $matchingRows = [];

    if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
        $optionStmt->execute(['question_id' => $questionId]);
        $dbOptions = $optionStmt->fetchAll();
        $byId = [];
        foreach ($dbOptions as $option) {
            $byId[(int)$option['id']] = $option;
        }

        $savedOrder = array_map('intval', (array)($optionOrder[(string)$questionId] ?? []));
        if (!$savedOrder) $savedOrder = array_map('intval', array_keys($byId));

        $selectedIds = json_decode($rawAnswer, true);
        $selectedIds = is_array($selectedIds) ? array_map('intval', $selectedIds) : [];
        $correctIds = expected_choice_option_ids($pdo, $questionId, $interaction);
        $selectedMap = array_fill_keys($selectedIds, true);
        $correctMap = array_fill_keys($correctIds, true);

        $studentAnswer = [];
        $correctAnswer = [];

        foreach ($savedOrder as $optionId) {
            if (!isset($byId[$optionId])) continue;
            $text = (string)$byId[$optionId]['text'];
            $selected = isset($selectedMap[$optionId]);
            $correct = isset($correctMap[$optionId]);
            $options[] = [
                'id' => $optionId,
                'text' => $text,
                'selected' => $selected,
                'correct' => $correct,
            ];
            if ($selected) $studentAnswer[] = $text;
            if ($correct) $correctAnswer[] = $text;
        }
    } elseif ($interaction === 'order') {
        $itemsMap = is_array($settings['items'] ?? null) ? $settings['items'] : [];
        $studentKeys = json_decode($rawAnswer, true);
        $studentKeys = is_array($studentKeys) ? array_map('strval', $studentKeys) : [];
        $correctKeys = json_decode((string)($row['correct_text'] ?? ''), true);
        $correctKeys = is_array($correctKeys) ? array_map('strval', $correctKeys) : [];

        $studentAnswer = array_values(array_map(
            static fn(string $key): string => (string)($itemsMap[$key] ?? $key),
            $studentKeys
        ));
        $correctAnswer = array_values(array_map(
            static fn(string $key): string => (string)($itemsMap[$key] ?? $key),
            $correctKeys
        ));
    } elseif ($interaction === 'matching') {
        $left = is_array($settings['left'] ?? null) ? $settings['left'] : [];
        $right = is_array($settings['right'] ?? null) ? $settings['right'] : [];
        $studentMap = json_decode($rawAnswer, true);
        $studentMap = is_array($studentMap) ? $studentMap : [];
        $correctMap = json_decode((string)($row['correct_text'] ?? ''), true);
        $correctMap = is_array($correctMap) ? $correctMap : [];

        foreach ($left as $leftKey => $leftText) {
            $selectedKey = (string)($studentMap[(string)$leftKey] ?? '');
            $correctKey = (string)($correctMap[(string)$leftKey] ?? '');
            $matchingRows[] = [
                'left' => (string)$leftText,
                'student' => $selectedKey !== '' ? (string)($right[$selectedKey] ?? $selectedKey) : '',
                'correct' => $correctKey !== '' ? (string)($right[$correctKey] ?? $correctKey) : '',
                'is_correct' => $selectedKey !== '' && $selectedKey === $correctKey,
            ];
        }
        $studentAnswer = array_values(array_map(
            static fn(array $pair): string => $pair['left'] . ' — ' . ($pair['student'] !== '' ? $pair['student'] : 'нет ответа'),
            $matchingRows
        ));
        $correctAnswer = array_values(array_map(
            static fn(array $pair): string => $pair['left'] . ' — ' . $pair['correct'],
            $matchingRows
        ));
    } else {
        $studentAnswer = $rawAnswer;
        $correctText = trim((string)($row['correct_text'] ?? ''));
        if ($correctText !== '') {
            $correctAnswer = array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                preg_split('/\s*\|\s*/u', $correctText) ?: []
            ), static fn(string $value): bool => $value !== ''));
        } else {
            $correctAnswer = [];
        }
    }

    $assetStmt->execute(['question_id' => $questionId]);
    $assets = [];
    foreach ($assetStmt->fetchAll() as $asset) {
        $assets[] = [
            'id' => (int)$asset['id'],
            'original_name' => (string)($asset['original_name'] ?? ''),
            'mime_type' => (string)($asset['mime_type'] ?? ''),
            'url' => './api/questions/asset.php?id=' . (int)$asset['id'],
        ];
    }

    $items[] = [
        'id' => $questionId,
        'type' => $interaction,
        'type_label' => $typeLabels[$interaction] ?? 'Вопрос',
        'text' => (string)$row['text'],
        'points' => (float)$row['points'],
        'earned_points' => (float)($answer['score'] ?? 0),
        'status' => $status,
        'needs_review' => $needsReview,
        'answered' => $hasAnswer,
        'student_answer' => $studentAnswer,
        'correct_answer' => $correctAnswer,
        'options' => $options,
        'matching' => $matchingRows,
        'original_text' => $interaction === 'correction'
            ? (string)($settings['original_text'] ?? '')
            : '',
        'assets' => $assets,
    ];
}

$published = $attempt['published_score'] !== null;
$displayScore = (float)($published ? $attempt['published_score'] : ($attempt['score'] ?? 0));
$displayPercent = (float)($published ? $attempt['published_percent'] : ($attempt['percent'] ?? 0));
$displayGrade = (string)($published ? $attempt['published_grade'] : ($attempt['grade'] ?? ''));
$displayComment = (string)($published ? ($attempt['published_comment'] ?? '') : '');

json_response([
    'ok' => true,
    'attempt' => [
        'id' => (int)$attempt['id'],
        'assignment_id' => (int)$attempt['assignment_id'],
        'assignment_title' => (string)($attempt['assignment_title'] ?? ''),
        'subject_name' => (string)($attempt['subject_name'] ?? ''),
        'student_name' => trim(
            (string)($attempt['student_last_name'] ?? '') . ' ' .
            (string)($attempt['student_first_name'] ?? '')
        ),
        'class_name' => (string)($attempt['class_name'] ?? ''),
        'submitted_at' => $attempt['submitted_at'] ?? null,
        'status' => (string)$attempt['status'],
        'score' => $displayScore,
        'max_score' => (float)($attempt['max_score'] ?? 0),
        'percent' => $displayPercent,
        'grade' => $displayGrade,
        'comment' => $displayComment,
        'adjusted' => $published,
    ],
    'summary' => [
        'total' => count($items),
        'correct' => $correctCount,
        'incorrect' => $incorrectCount,
        'unanswered' => $unansweredCount,
        'needs_review' => $reviewCount,
    ],
    'questions' => $items,
]);
