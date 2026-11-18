<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';
require __DIR__ . '/_helpers.php';
require dirname(__DIR__) . '/materials/_copy.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$attemptId = (int)($data['attempt_id'] ?? 0);
if ($attemptId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран результат ученика.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
subscription_require_feature(
    $pdo,
    $user,
    'advanced_analytics',
    'Автоматическая работа над ошибками доступна на тарифах PRO и Premium.'
);
$attempt = result_attempt_for_staff($pdo, $user, $schoolId, $attemptId);
regrade_attempt_answers($pdo, $attemptId);

$questionOrder = array_map('intval', decoded_json_array($attempt['question_order_json'] ?? null));
if (!$questionOrder) {
    $stmt = $pdo->prepare(
        'SELECT id FROM questions
         WHERE assignment_id = :assignment_id
         ORDER BY position, id'
    );
    $stmt->execute(['assignment_id' => (int)$attempt['assignment_id']]);
    $questionOrder = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$stmt = $pdo->prepare(
    'SELECT question_id, answer_text, is_correct, needs_review
     FROM answers
     WHERE attempt_id = :attempt_id'
);
$stmt->execute(['attempt_id' => $attemptId]);
$answers = [];
foreach ($stmt->fetchAll() as $row) {
    $answers[(int)$row['question_id']] = $row;
}

$problemQuestionIds = [];
foreach ($questionOrder as $questionId) {
    $answer = $answers[$questionId] ?? null;
    if ($answer !== null && (int)($answer['needs_review'] ?? 0) === 1) continue;

    $raw = trim((string)($answer['answer_text'] ?? ''));
    $isIncorrect = $answer !== null
        && $answer['is_correct'] !== null
        && (int)$answer['is_correct'] === 0;
    $isUnanswered = $answer === null || $raw === '' || $raw === '[]' || $raw === '{}';

    if ($isIncorrect || $isUnanswered) {
        $problemQuestionIds[] = $questionId;
    }
}

if (!$problemQuestionIds) {
    json_response([
        'ok' => false,
        'error' => 'Для этой работы нет подтверждённых ошибок или пропущенных вопросов.',
        'code' => 'NO_REMEDIATION_QUESTIONS',
    ], 409);
}

$stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id AND school_id = :school_id LIMIT 1');
$stmt->execute([
    'id' => (int)$attempt['assignment_id'],
    'school_id' => $schoolId,
]);
$sourceAssignment = $stmt->fetch();
if (!$sourceAssignment) {
    json_response(['ok' => false, 'error' => 'Исходное задание не найдено.'], 404);
}

$studentName = trim(
    (string)($attempt['student_last_name'] ?? '') . ' ' .
    (string)($attempt['student_first_name'] ?? '')
);
$title = 'Работа над ошибками — ' . (string)$attempt['assignment_title'];
if ($studentName !== '') $title .= ' — ' . $studentName;
$title = mb_substr($title, 0, 180);
$copiedFiles = [];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, description, type, status, workflow_status,
          max_attempts, time_limit_minutes, show_answers, focus_policy,
          variant_count, shuffle_questions, shuffle_options, shuffle_structured)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :description, "independent", "draft", "draft",
          1, NULL, 0, "allow", 1, 0, 0, 0)'
    );
    $stmt->execute([
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
        'subject_id' => (int)$sourceAssignment['subject_id'],
        'title' => $title,
        'description' => 'Автоматически создано по ошибкам и пропущенным вопросам из работы «' .
            (string)$attempt['assignment_title'] . '».',
    ]);
    $newAssignmentId = (int)$pdo->lastInsertId();

    $placeholders = implode(',', array_fill(0, count($problemQuestionIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT * FROM questions
         WHERE assignment_id = ? AND id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([(int)$attempt['assignment_id']], $problemQuestionIds));
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int)$row['id']] = $row;
    }

    $insertQuestion = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
    );
    $optionSelect = $pdo->prepare(
        'SELECT * FROM question_options WHERE question_id = :question_id ORDER BY position, id'
    );
    $optionInsert = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, position)
         VALUES (:question_id, :text, :is_correct, :position)'
    );
    $assetSelect = $pdo->prepare(
        'SELECT * FROM question_assets WHERE question_id = :question_id ORDER BY position, id'
    );
    $assetInsert = $pdo->prepare(
        'INSERT INTO question_assets
         (question_id, stored_name, original_name, mime_type, position)
         VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
    );

    $position = 0;
    foreach ($problemQuestionIds as $sourceQuestionId) {
        if (!isset($rows[$sourceQuestionId])) continue;
        $question = $rows[$sourceQuestionId];
        $position++;

        $insertQuestion->execute([
            'assignment_id' => $newAssignmentId,
            'type' => (string)$question['type'],
            'text' => (string)$question['text'],
            'points' => (float)$question['points'],
            'position' => $position,
            'correct_text' => $question['correct_text'],
            'interaction_type' => $question['interaction_type'],
            'settings_json' => $question['settings_json'],
        ]);
        $newQuestionId = (int)$pdo->lastInsertId();

        $optionSelect->execute(['question_id' => $sourceQuestionId]);
        foreach ($optionSelect->fetchAll() as $option) {
            $optionInsert->execute([
                'question_id' => $newQuestionId,
                'text' => (string)$option['text'],
                'is_correct' => (int)$option['is_correct'],
                'position' => (int)$option['position'],
            ]);
        }

        $assetSelect->execute(['question_id' => $sourceQuestionId]);
        foreach ($assetSelect->fetchAll() as $asset) {
            $stored = material_copy_private_file(
                'question-assets',
                (string)$asset['stored_name'],
                'remediation-question',
                $copiedFiles
            );
            if ($stored === null) continue;

            $assetInsert->execute([
                'question_id' => $newQuestionId,
                'stored_name' => $stored,
                'original_name' => $asset['original_name'],
                'mime_type' => $asset['mime_type'],
                'position' => (int)$asset['position'],
            ]);
        }
    }

    if ($position < 1) {
        throw new RuntimeException('Не удалось подготовить вопросы для работы над ошибками.');
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($copiedFiles as $path) @unlink($path);
    throw $error;
}

audit_event('remediation_assignment_created', 'assignment', $newAssignmentId, [
    'source_attempt_id' => $attemptId,
    'source_assignment_id' => (int)$attempt['assignment_id'],
    'student_id' => (int)$attempt['student_id'],
    'questions_count' => count($problemQuestionIds),
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment_id' => $newAssignmentId,
    'questions_count' => count($problemQuestionIds),
    'message' => 'Работа над ошибками создана как отдельный черновик.',
], 201);
