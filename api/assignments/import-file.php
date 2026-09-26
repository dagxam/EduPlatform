<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_import_parser.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$subjectId = (int)($_POST['subject_id'] ?? 0);
$classId = (int)($_POST['class_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$focusPolicy = in_array(($_POST['focus_policy'] ?? 'allow'), ['allow', 'strict'], true)
    ? (string)$_POST['focus_policy']
    : 'allow';

if ($subjectId < 1 || $classId < 1 || empty($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Выберите предмет, класс и файл задания.'], 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Не удалось загрузить файл задания.'], 422);
}
if ((int)($file['size'] ?? 0) > 20 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Файл слишком большой. Максимум — 20 МБ.'], 413);
}

$originalName = trim((string)($file['name'] ?? ''));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = ['docx', 'pdf', 'ppt', 'pptx'];
if (!in_array($extension, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Поддерживаются DOCX, PDF, PPT и PPTX.'], 422);
}

if ($title === '') {
    $title = trim((string)pathinfo($originalName, PATHINFO_FILENAME));
}
if ($title === '') {
    $title = 'Импортированное задание';
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$isSchoolManager = can_manage_school($user, $schoolId);

if (!$isSchoolManager && !can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет прав для добавления задания в этой школе.'], 403);
}

if ($isSchoolManager) {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM school_subjects ss
         JOIN classes c ON c.school_id = ss.school_id
         WHERE ss.school_id = :school_id
           AND ss.subject_id = :subject_id
           AND ss.is_active = 1
           AND c.id = :class_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
        'class_id' => $classId,
    ]);
} else {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_classes tc
         JOIN school_subjects ss
           ON ss.school_id = tc.school_id AND ss.subject_id = tc.subject_id AND ss.is_active = 1
         WHERE tc.school_id = :school_id
           AND tc.teacher_id = :teacher_id
           AND tc.subject_id = :subject_id
           AND tc.class_id = :class_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => $subjectId,
        'class_id' => $classId,
    ]);
}

if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Этот предмет и класс недоступны для создания задания.'], 403);
}

$storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'assignment-imports';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    json_response(['ok' => false, 'error' => 'Не удалось подготовить хранилище заданий.'], 500);
}

$storedName = 'assignment-' . bin2hex(random_bytes(12)) . '.' . $extension;
$destination = $storageDir . DIRECTORY_SEPARATOR . $storedName;
$tmpPath = (string)($file['tmp_name'] ?? '');

if (!move_uploaded_file($tmpPath, $destination)) {
    json_response(['ok' => false, 'error' => 'Не удалось сохранить файл задания.'], 500);
}

$mimeType = function_exists('mime_content_type')
    ? ((string)(mime_content_type($destination) ?: 'application/octet-stream'))
    : 'application/octet-stream';

$extractedText = import_extract_text($destination, $extension);
if ($extractedText !== null && strlen($extractedText) > 1500000) {
    $extractedText = substr($extractedText, 0, 1500000);
}

$media = import_extract_media($destination, $extension);
$parsedQuestions = $extractedText !== null ? import_parse_questions($extractedText) : [];

$parseStatus = 'uploaded_for_review';
$parserMessage = 'Файл сохранён. Автоматическое извлечение текста для этого файла не дало достаточного результата.';
if ($extractedText !== null && trim($extractedText) !== '') {
    $parseStatus = 'text_extracted';
    $parserMessage = 'Текст извлечён. Вопросы не найдены по шаблону UVORIA — проверьте структуру файла.';
}
if ($parsedQuestions) {
    $parseStatus = 'questions_parsed';
    $parserMessage = 'Вопросы автоматически распознаны и добавлены в черновик для проверки.';
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, description, type, status, max_attempts, focus_policy)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :description, "file", "draft", 1, :focus_policy)'
    );
    $stmt->execute([
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
        'title' => $title,
        'description' => 'Импортировано из ' . strtoupper($extension) . '.',
        'focus_policy' => $focusPolicy,
    ]);
    $assignmentId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO assignment_classes (assignment_id, class_id)
         VALUES (:assignment_id, :class_id)'
    );
    $stmt->execute([
        'assignment_id' => $assignmentId,
        'class_id' => $classId,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO assignment_imports
         (assignment_id, original_name, stored_name, source_format, mime_type, size_bytes,
          parse_status, extracted_text, parsed_question_count, parser_message)
         VALUES
         (:assignment_id, :original_name, :stored_name, :source_format, :mime_type, :size_bytes,
          :parse_status, :extracted_text, 0, :parser_message)'
    );
    $stmt->execute([
        'assignment_id' => $assignmentId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'source_format' => $extension,
        'mime_type' => $mimeType,
        'size_bytes' => (int)($file['size'] ?? 0),
        'parse_status' => $parseStatus,
        'extracted_text' => $extractedText,
        'parser_message' => $parserMessage,
    ]);

    $stored = ['count' => 0, 'types' => []];
    if ($parsedQuestions) {
        $stored = import_store_questions($pdo, $assignmentId, $parsedQuestions, $media);
        $stmt = $pdo->prepare(
            'UPDATE assignment_imports
             SET parsed_question_count = :count,
                 parse_status = "questions_parsed",
                 parser_message = :message
             WHERE assignment_id = :assignment_id'
        );
        $stmt->execute([
            'count' => (int)$stored['count'],
            'message' => 'Распознано вопросов: ' . (int)$stored['count'] . '. Проверьте черновик перед публикацией.',
            'assignment_id' => $assignmentId,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($destination);
    throw $e;
}

audit_event('assignment_file_imported', 'assignment', $assignmentId, [
    'subject_id' => $subjectId,
    'class_id' => $classId,
    'format' => $extension,
    'parse_status' => $parseStatus,
    'parsed_question_count' => (int)($stored['count'] ?? 0),
    'parsed_types' => $stored['types'] ?? [],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment' => [
        'id' => $assignmentId,
        'title' => $title,
        'status' => 'draft',
        'subject_id' => $subjectId,
        'class_id' => $classId,
    ],
    'import' => [
        'format' => strtoupper($extension),
        'parse_status' => $parsedQuestions ? 'questions_parsed' : $parseStatus,
        'extracted_chars' => $extractedText !== null ? strlen($extractedText) : 0,
        'parsed_question_count' => (int)($stored['count'] ?? 0),
        'types' => $stored['types'] ?? [],
        'message' => $parsedQuestions
            ? 'Вопросы распознаны и добавлены в черновик.'
            : $parserMessage,
    ],
], 201);
