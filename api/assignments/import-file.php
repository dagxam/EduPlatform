<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

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

function xml_text_nodes(string $xml, string $tagPattern): string
{
    $parts = [];
    if (preg_match_all($tagPattern, $xml, $matches)) {
        foreach ($matches[1] as $text) {
            $parts[] = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
}

function extract_docx_text(string $path): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $stat = $zip->statName('word/document.xml');
    if (!$stat || (int)($stat['size'] ?? 0) > 4 * 1024 * 1024) {
        $zip->close();
        return null;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return null;

    return xml_text_nodes($xml, '/<w:t\b[^>]*>(.*?)<\/w:t>/si');
}

function extract_pptx_text(string $path): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $slides = [];
    $totalXmlBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
            continue;
        }
        $stat = $zip->statIndex($i);
        $size = (int)($stat['size'] ?? 0);
        $totalXmlBytes += $size;
        if ($totalXmlBytes > 8 * 1024 * 1024) {
            $zip->close();
            return null;
        }
        $slides[(int)$m[1]] = $name;
    }

    ksort($slides);
    $texts = [];
    foreach ($slides as $number => $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false) continue;
        $text = xml_text_nodes($xml, '/<a:t\b[^>]*>(.*?)<\/a:t>/si');
        if ($text !== '') {
            $texts[] = 'Слайд ' . $number . ': ' . $text;
        }
    }
    $zip->close();

    return trim(implode("\n", $texts));
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$extractedText = null;
$parseStatus = 'uploaded_for_review';

if ($extension === 'docx') {
    $extractedText = extract_docx_text($tmpPath);
    if ($extractedText !== null && $extractedText !== '') {
        $parseStatus = 'text_extracted';
    }
} elseif ($extension === 'pptx') {
    $extractedText = extract_pptx_text($tmpPath);
    if ($extractedText !== null && $extractedText !== '') {
        $parseStatus = 'text_extracted';
    }
}

if ($extractedText !== null && strlen($extractedText) > 1500000) {
    $extractedText = substr($extractedText, 0, 1500000);
}

$storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'assignment-imports';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    json_response(['ok' => false, 'error' => 'Не удалось подготовить хранилище заданий.'], 500);
}

$storedName = 'assignment-' . bin2hex(random_bytes(12)) . '.' . $extension;
$destination = $storageDir . DIRECTORY_SEPARATOR . $storedName;

if (!move_uploaded_file($tmpPath, $destination)) {
    json_response(['ok' => false, 'error' => 'Не удалось сохранить файл задания.'], 500);
}

$mimeType = function_exists('mime_content_type')
    ? ((string)(mime_content_type($destination) ?: 'application/octet-stream'))
    : 'application/octet-stream';

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
         (assignment_id, original_name, stored_name, source_format, mime_type, size_bytes, parse_status, extracted_text)
         VALUES
         (:assignment_id, :original_name, :stored_name, :source_format, :mime_type, :size_bytes, :parse_status, :extracted_text)'
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
    ]);

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
        'parse_status' => $parseStatus,
        'extracted_chars' => $extractedText !== null ? strlen($extractedText) : 0,
    ],
], 201);
