<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
$academicYear = trim((string)($data['academic_year'] ?? ''));

if ($classId < 1 || $name === '') {
    json_response(['ok' => false, 'error' => 'Укажите класс и его название.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
if (!can_manage_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}

$stmt = $pdo->prepare(
    'SELECT id, COALESCE(display_name, name) AS current_name, academic_year
     FROM classes
     WHERE id = :class_id AND school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);
$class = $stmt->fetch();
if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT 1 FROM classes
     WHERE school_id = :school_id
       AND id <> :class_id
       AND lower(trim(COALESCE(display_name, name))) = lower(trim(:name))
     LIMIT 1'
);
$stmt->execute(['school_id' => $schoolId, 'class_id' => $classId, 'name' => $name]);
if ($stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'В этой школе уже есть класс с таким названием.'], 409);
}

$stmt = $pdo->prepare(
    'UPDATE classes
     SET display_name = :display_name, academic_year = :academic_year
     WHERE id = :class_id AND school_id = :school_id'
);
$stmt->execute([
    'display_name' => $name,
    'academic_year' => $academicYear !== '' ? $academicYear : null,
    'class_id' => $classId,
    'school_id' => $schoolId,
]);

$pdo->prepare(
    'UPDATE users
     SET class_name = :class_name, updated_at = CURRENT_TIMESTAMP
     WHERE role = "student"
       AND id IN (SELECT student_id FROM class_students WHERE class_id = :class_id)'
)->execute([
    'class_name' => $name,
    'class_id' => $classId,
]);

audit_event('class_updated', 'class', $classId, [
    'before' => [
        'name' => (string)$class['current_name'],
        'academic_year' => (string)($class['academic_year'] ?? ''),
    ],
    'after' => ['name' => $name, 'academic_year' => $academicYear],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'class' => ['id' => $classId, 'name' => $name, 'academic_year' => $academicYear],
    'message' => 'Данные класса обновлены.',
]);
