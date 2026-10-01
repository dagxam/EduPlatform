<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_student-import.php';
require dirname(__DIR__) . '/assignments/_import_parser.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$classId = (int)($_POST['class_id'] ?? 0);
require_privacy_confirmation(
    $_POST,
    'privacy_basis_confirmed',
    'Подтвердите законное основание передачи персональных данных учащихся.'
);
if ($classId < 1 || empty($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Выберите класс и файл со списком учеников.'], 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Не удалось загрузить файл.'], 422);
}
if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Файл слишком большой. Максимальный размер — 10 МБ.'], 413);
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$allowed = ['doc', 'docx', 'pdf', 'xls', 'xlsx'];
if (!in_array($extension, $allowed, true)) {
    json_response([
        'ok' => false,
        'error' => 'Поддерживаются Word DOC/DOCX, PDF и Excel XLS/XLSX.',
    ], 422);
}

$tmpName = (string)($file['tmp_name'] ?? '');
$head = @file_get_contents($tmpName, false, null, 0, 16);
$head = is_string($head) ? $head : '';
$trimmedHead = ltrim($head);
$ole = str_starts_with($head, "ÐÏà¡±á");
$zip = str_starts_with($head, "PK")
    || str_starts_with($head, "PK")
    || str_starts_with($head, "PK");

$signatureValid = match ($extension) {
    'pdf' => str_starts_with($head, '%PDF-'),
    'docx', 'xlsx' => $zip,
    'doc' => $ole || str_starts_with($trimmedHead, '{\rtf'),
    'xls' => $ole || str_starts_with($trimmedHead, '<?xml') || str_starts_with($trimmedHead, '<html'),
    default => false,
};
if (!$signatureValid) {
    json_response([
        'ok' => false,
        'error' => 'Содержимое файла не соответствует выбранному формату.',
    ], 422);
}

$pdo = app_db();
$class = require_school_class_for_admin($pdo, $user, $classId);
$students = parse_student_import_file($tmpName, $extension);
if (!$students) {
    json_response([
        'ok' => false,
        'error' => 'Не удалось найти фамилии и имена. Используйте строки вида «Магомедов Али» либо таблицу с отдельными колонками «Фамилия» и «Имя».',
    ], 422);
}

$existing = class_existing_student_keys($pdo, $classId);
$preview = [];
$duplicates = 0;
foreach ($students as $student) {
    $key = student_name_key($student['last_name'], $student['first_name']);
    $duplicate = isset($existing[$key]);
    if ($duplicate) $duplicates++;

    $preview[] = [
        'last_name' => $student['last_name'],
        'first_name' => $student['first_name'],
        'duplicate' => $duplicate,
    ];
}

json_response([
    'ok' => true,
    'preview' => true,
    'class' => [
        'id' => (int)$class['id'],
        'name' => $class['name'],
    ],
    'students' => $preview,
    'found_count' => count($preview),
    'existing_count' => $duplicates,
    'source_format' => strtoupper($extension),
]);
