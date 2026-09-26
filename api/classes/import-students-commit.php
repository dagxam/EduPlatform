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
$rows = is_array($data['students'] ?? null) ? $data['students'] : [];

if ($classId < 1 || !$rows) {
    json_response(['ok' => false, 'error' => 'Нет учеников для добавления.'], 422);
}
if (count($rows) > 100) {
    json_response(['ok' => false, 'error' => 'За один импорт можно добавить не более 100 учеников.'], 422);
}

$pdo = app_db();
$class = require_school_class_for_admin($pdo, $user, $classId);
$existing = class_existing_student_keys($pdo, $classId);

$clean = [];
$payloadSeen = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $lastName = trim(preg_replace('/\s+/u', ' ', (string)($row['last_name'] ?? '')) ?? '');
    $firstName = trim(preg_replace('/\s+/u', ' ', (string)($row['first_name'] ?? '')) ?? '');

    if (!validate_student_name_part($lastName) || !validate_student_name_part($firstName)) {
        json_response([
            'ok' => false,
            'error' => 'Проверьте фамилии и имена: разрешены только буквы, пробел, дефис и апостроф.',
        ], 422);
    }

    $key = student_name_key($lastName, $firstName);
    if (isset($payloadSeen[$key])) {
        continue;
    }
    $payloadSeen[$key] = true;
    $clean[$key] = ['last_name' => $lastName, 'first_name' => $firstName];
}

$inserted = [];
$skipped = [];
$pdo->beginTransaction();
try {
    $userStmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, role, class_name)
         VALUES (:first_name, :last_name, :email, :password_hash, "student", :class_name)'
    );
    $linkStmt = $pdo->prepare(
        'INSERT INTO class_students (student_id, class_id)
         VALUES (:student_id, :class_id)'
    );

    foreach ($clean as $key => $student) {
        if (isset($existing[$key])) {
            $skipped[] = $student;
            continue;
        }

        $internalEmail = 'student-' . bin2hex(random_bytes(8)) . '@uvoria.local';
        $userStmt->execute([
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'email' => $internalEmail,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'class_name' => $class['name'],
        ]);
        $studentId = (int)$pdo->lastInsertId();
        $linkStmt->execute(['student_id' => $studentId, 'class_id' => $classId]);
        $inserted[] = ['id' => $studentId] + $student;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('students_imported', 'class', $classId, [
    'imported_count' => count($inserted),
    'skipped_count' => count($skipped),
], (int)$class['school_id'], (int)$user['id']);

json_response([
    'ok' => true,
    'imported_count' => count($inserted),
    'skipped_count' => count($skipped),
    'students' => $inserted,
    'skipped' => $skipped,
]);
