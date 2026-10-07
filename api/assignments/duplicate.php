<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$sourceId = (int)($data['assignment_id'] ?? 0);
$title = trim((string)($data['title'] ?? ''));
$startsAtRaw = trim((string)($data['starts_at'] ?? ''));
$dueAtRaw = trim((string)($data['due_at'] ?? ''));
$timeLimit = isset($data['time_limit_minutes']) && $data['time_limit_minutes'] !== ''
    ? max(1, min(600, (int)$data['time_limit_minutes']))
    : null;
$maxAttempts = max(1, min(10, (int)($data['max_attempts'] ?? 1)));
$focusPolicy = in_array(($data['focus_policy'] ?? 'allow'), ['allow', 'strict'], true)
    ? (string)$data['focus_policy']
    : 'allow';

if ($sourceId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбрано исходное задание.'], 422);
}
if ($title === '') {
    json_response(['ok' => false, 'error' => 'Введите название новой копии.'], 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($title) : strlen($title)) > 180) {
    json_response(['ok' => false, 'error' => 'Название слишком длинное.'], 422);
}

function duplicate_assignment_datetime(string $value): ?string
{
    if ($value === '') return null;
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
        json_response(['ok' => false, 'error' => 'Некорректная дата или время.'], 422);
    }
    return $value;
}

function duplicate_assignment_private_file(string $folder, string $storedName, string $prefix, array &$copiedFiles): ?string
{
    $safeName = basename($storedName);
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $folder;
    $source = $base . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($source)) return null;

    if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
        throw new RuntimeException('Не удалось подготовить хранилище.');
    }

    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $newName = $prefix . '-' . bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
    $destination = $base . DIRECTORY_SEPARATOR . $newName;

    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось скопировать файл задания.');
    }

    $copiedFiles[] = $destination;
    return $newName;
}

$startsAt = duplicate_assignment_datetime($startsAtRaw);
$dueAt = duplicate_assignment_datetime($dueAtRaw);
if ($startsAt !== null && $dueAt !== null && $dueAt <= $startsAt) {
    json_response(['ok' => false, 'error' => 'Дедлайн должен быть позже даты открытия.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$stmt = $pdo->prepare(
    'SELECT a.*
     FROM assignments a
     WHERE a.id = :id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'id' => $sourceId,
    'school_id' => $schoolId,
]);
$source = $stmt->fetch();

if (!$source) {
    json_response(['ok' => false, 'error' => 'Исходное задание не найдено.'], 404);
}

if (!$manager) {
    if (!can_teach_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_subjects
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => (int)$source['subject_id'],
    ]);
    if ((int)$source['teacher_id'] !== (int)$user['id'] && !$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
    }
}

$copiedFiles = [];
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, description, type, status, workflow_status,
          max_attempts, time_limit_minutes, starts_at, due_at, show_answers,
          focus_policy, variant_count, shuffle_questions, shuffle_options, shuffle_structured)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :description, :type, "draft", "draft",
          :max_attempts, :time_limit_minutes, :starts_at, :due_at, :show_answers,
          :focus_policy, :variant_count, :shuffle_questions, :shuffle_options, :shuffle_structured)'
    );
    $stmt->execute([
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
        'subject_id' => (int)$source['subject_id'],
        'title' => $title,
        'description' => $source['description'],
        'type' => (string)$source['type'],
        'max_attempts' => $maxAttempts,
        'time_limit_minutes' => $timeLimit,
        'starts_at' => $startsAt,
        'due_at' => $dueAt,
        'show_answers' => (int)$source['show_answers'],
        'focus_policy' => $focusPolicy,
        'variant_count' => 1,
        'shuffle_questions' => 0,
        'shuffle_options' => 0,
        'shuffle_structured' => 0,
    ]);
    $newAssignmentId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'SELECT * FROM assignment_imports
         WHERE assignment_id = :assignment_id
         LIMIT 1'
    );
    $stmt->execute(['assignment_id' => $sourceId]);
    $import = $stmt->fetch();

    if ($import) {
        $newStoredName = duplicate_assignment_private_file(
            'assignment-imports',
            (string)$import['stored_name'],
            'duplicate-assignment',
            $copiedFiles
        );

        if ($newStoredName !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO assignment_imports
                 (assignment_id, original_name, stored_name, source_format, mime_type, size_bytes,
                  parse_status, extracted_text, parsed_question_count, parser_message)
                 VALUES
                 (:assignment_id, :original_name, :stored_name, :source_format, :mime_type, :size_bytes,
                  :parse_status, :extracted_text, :parsed_question_count, :parser_message)'
            );
            $stmt->execute([
                'assignment_id' => $newAssignmentId,
                'original_name' => (string)$import['original_name'],
                'stored_name' => $newStoredName,
                'source_format' => (string)$import['source_format'],
                'mime_type' => $import['mime_type'],
                'size_bytes' => (int)$import['size_bytes'],
                'parse_status' => (string)$import['parse_status'],
                'extracted_text' => $import['extracted_text'],
                'parsed_question_count' => (int)($import['parsed_question_count'] ?? 0),
                'parser_message' => $import['parser_message'],
            ]);
        }
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM questions
         WHERE assignment_id = :assignment_id
           AND is_active = 1
         ORDER BY position, id'
    );
    $stmt->execute(['assignment_id' => $sourceId]);
    $questions = $stmt->fetchAll();

    $insertQuestion = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
    );
    $optionQuery = $pdo->prepare(
        'SELECT * FROM question_options
         WHERE question_id = :question_id
         ORDER BY position, id'
    );
    $insertOption = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, position)
         VALUES (:question_id, :text, :is_correct, :position)'
    );
    $assetQuery = $pdo->prepare(
        'SELECT * FROM question_assets
         WHERE question_id = :question_id
         ORDER BY position, id'
    );
    $insertAsset = $pdo->prepare(
        'INSERT INTO question_assets
         (question_id, stored_name, original_name, mime_type, position)
         VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
    );

    foreach ($questions as $question) {
        $insertQuestion->execute([
            'assignment_id' => $newAssignmentId,
            'type' => (string)$question['type'],
            'text' => (string)$question['text'],
            'points' => (float)$question['points'],
            'position' => (int)$question['position'],
            'correct_text' => $question['correct_text'],
            'interaction_type' => $question['interaction_type'],
            'settings_json' => $question['settings_json'],
        ]);
        $newQuestionId = (int)$pdo->lastInsertId();

        $optionQuery->execute(['question_id' => (int)$question['id']]);
        foreach ($optionQuery->fetchAll() as $option) {
            $insertOption->execute([
                'question_id' => $newQuestionId,
                'text' => (string)$option['text'],
                'is_correct' => (int)$option['is_correct'],
                'position' => (int)$option['position'],
            ]);
        }

        $assetQuery->execute(['question_id' => (int)$question['id']]);
        foreach ($assetQuery->fetchAll() as $asset) {
            $newAssetName = duplicate_assignment_private_file(
                'question-assets',
                (string)$asset['stored_name'],
                'duplicate-question',
                $copiedFiles
            );
            if ($newAssetName === null) continue;

            $insertAsset->execute([
                'question_id' => $newQuestionId,
                'stored_name' => $newAssetName,
                'original_name' => $asset['original_name'],
                'mime_type' => $asset['mime_type'],
                'position' => (int)$asset['position'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($copiedFiles as $path) {
        @unlink($path);
    }
    throw $e;
}

audit_event('assignment_duplicated', 'assignment', $newAssignmentId, [
    'source_assignment_id' => $sourceId,
    'subject_id' => (int)$source['subject_id'],
    'questions_count' => count($questions),
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment' => [
        'id' => $newAssignmentId,
        'title' => $title,
        'subject_id' => (int)$source['subject_id'],
        'status' => 'draft',
        'workflow_status' => 'draft',
        'questions_count' => count($questions),
        'starts_at' => $startsAt,
        'due_at' => $dueAt,
    ],
], 201);
