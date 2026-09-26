<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_student-import.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$classId = (int)($_POST['class_id'] ?? 0);
if ($classId < 1 || empty($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Выберите класс и Word-файл.'], 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Не удалось загрузить файл.'], 422);
}
if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'DOCX слишком большой. Максимальный размер — 5 МБ.'], 413);
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if ($extension !== 'docx') {
    json_response(['ok' => false, 'error' => 'Сейчас поддерживается формат .docx. Старый .doc сохраните как DOCX.'], 422);
}

$pdo = app_db();
$class = require_school_class_for_admin($pdo, $user, $classId);
$students = parse_docx_students((string)$file['tmp_name']);
if (!$students) {
    json_response([
        'ok' => false,
        'error' => 'Не удалось найти фамилии и имена. Используйте строки вида «Магомедов Али» или таблицу «Фамилия | Имя».',
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
]);
