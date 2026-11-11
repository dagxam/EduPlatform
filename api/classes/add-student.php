<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_student-import.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
require_privacy_confirmation(
    $data,
    'privacy_basis_confirmed',
    'Подтвердите законное основание передачи персональных данных учащегося.'
);

$classId = (int)($data['class_id'] ?? 0);
$firstName = trim(preg_replace('/\s+/u', ' ', (string)($data['first_name'] ?? '')) ?? '');
$lastName = trim(preg_replace('/\s+/u', ' ', (string)($data['last_name'] ?? '')) ?? '');
$middleName = trim(preg_replace('/\s+/u', ' ', (string)($data['middle_name'] ?? '')) ?? '');

if ($classId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран класс.'], 422);
}
if (!validate_student_name_part($firstName) || !validate_student_name_part($lastName)) {
    json_response(['ok' => false, 'error' => 'Имя и фамилия обязательны. Разрешены буквы, пробел, дефис и апостроф.'], 422);
}
if ($middleName !== '' && !validate_student_name_part($middleName)) {
    json_response(['ok' => false, 'error' => 'Проверьте отчество: разрешены буквы, пробел, дефис и апостроф.'], 422);
}

$pdo = app_db();
subscription_require_feature(
    $pdo,
    $user,
    'manual_student_add',
    'Ручное добавление ученика доступно только на тарифах PRO и Premium.'
);

$class = require_school_class_for_roster_manager($pdo, $user, $classId);
$schoolId = (int)$class['school_id'];
subscription_assert_student_capacity($pdo, $user, $schoolId, 1);

$duplicate = $pdo->prepare(
    'SELECT 1
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id
       AND lower(trim(u.last_name)) = lower(trim(:last_name))
       AND lower(trim(u.first_name)) = lower(trim(:first_name))
     LIMIT 1'
);
$duplicate->execute([
    'class_id' => $classId,
    'last_name' => $lastName,
    'first_name' => $firstName,
]);
if ($duplicate->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'В этом классе уже есть ученик с такими фамилией и именем.'], 409);
}

$pdo->beginTransaction();
try {
    $internalEmail = 'student-' . bin2hex(random_bytes(8)) . '@uvoria.local';
    $stmt = $pdo->prepare(
        'INSERT INTO users
         (first_name, last_name, middle_name, email, password_hash, role, class_name)
         VALUES
         (:first_name, :last_name, :middle_name, :email, :password_hash, "student", :class_name)'
    );
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'email' => $internalEmail,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'class_name' => (string)$class['name'],
    ]);
    $studentId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO class_students (student_id, class_id)
         VALUES (:student_id, :class_id)'
    )->execute([
        'student_id' => $studentId,
        'class_id' => $classId,
    ]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

audit_event('student_added_manually', 'student', $studentId, [
    'class_id' => $classId,
] + privacy_audit_metadata('lawful_basis_confirmed'), $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'student' => [
        'id' => $studentId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $middleName,
    ],
    'message' => 'Ученик добавлен в класс.',
], 201);
