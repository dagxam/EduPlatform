<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_student-import.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
$firstName = trim(preg_replace('/\s+/u', ' ', (string)($data['first_name'] ?? '')) ?? '');
$lastName = trim(preg_replace('/\s+/u', ' ', (string)($data['last_name'] ?? '')) ?? '');
$middleName = trim(preg_replace('/\s+/u', ' ', (string)($data['middle_name'] ?? '')) ?? '');

if ($classId < 1 || $studentId < 1) {
    json_response(['ok' => false, 'error' => 'Некорректный ученик или класс.'], 422);
}
if (!validate_student_name_part($firstName) || !validate_student_name_part($lastName)) {
    json_response([
        'ok' => false,
        'error' => 'Имя и фамилия обязательны. Разрешены буквы, пробел, дефис и апостроф.',
    ], 422);
}
if ($middleName !== '' && !validate_student_name_part($middleName)) {
    json_response([
        'ok' => false,
        'error' => 'Проверьте отчество: разрешены буквы, пробел, дефис и апостроф.',
    ], 422);
}

$pdo = app_db();
$class = require_school_class_for_roster_manager($pdo, $user, $classId);
$schoolId = (int)$class['school_id'];

$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.middle_name, u.role
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id
       AND cs.student_id = :student_id
     LIMIT 1'
);
$stmt->execute([
    'class_id' => $classId,
    'student_id' => $studentId,
]);
$student = $stmt->fetch();

if (!$student || (string)$student['role'] !== 'student') {
    json_response(['ok' => false, 'error' => 'Ученик не найден в этом классе.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT 1
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id
       AND cs.student_id <> :student_id
       AND lower(trim(u.last_name)) = lower(trim(:last_name))
       AND lower(trim(u.first_name)) = lower(trim(:first_name))
     LIMIT 1'
);
$stmt->execute([
    'class_id' => $classId,
    'student_id' => $studentId,
    'last_name' => $lastName,
    'first_name' => $firstName,
]);
if ($stmt->fetchColumn()) {
    json_response([
        'ok' => false,
        'error' => 'В этом классе уже есть ученик с такими фамилией и именем.',
    ], 409);
}

$before = [
    'first_name' => (string)$student['first_name'],
    'last_name' => (string)$student['last_name'],
    'middle_name' => (string)($student['middle_name'] ?? ''),
];

$stmt = $pdo->prepare(
    'UPDATE users
     SET first_name = :first_name,
         last_name = :last_name,
         middle_name = :middle_name,
         class_name = :class_name,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :student_id AND role = "student"'
);
$stmt->execute([
    'first_name' => $firstName,
    'last_name' => $lastName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'class_name' => (string)$class['name'],
    'student_id' => $studentId,
]);

audit_event('student_profile_updated', 'user', $studentId, [
    'class_id' => $classId,
    'before' => $before,
    'after' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $middleName,
    ],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'student' => [
        'id' => $studentId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $middleName,
    ],
    'message' => 'Данные ученика обновлены.',
]);
