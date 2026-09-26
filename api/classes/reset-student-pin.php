<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
if ($classId < 1 || $studentId < 1) {
    json_response(['ok' => false, 'error' => 'Некорректный ученик или класс.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, true);
$stmt = $pdo->prepare(
    'SELECT 1
     FROM classes c
     JOIN class_students cs ON cs.class_id = c.id
     WHERE c.id = :class_id
       AND c.school_id = :school_id
       AND cs.student_id = :student_id'
);
$stmt->execute([
    'class_id' => $classId,
    'school_id' => $schoolId,
    'student_id' => $studentId,
]);

if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Ученик не найден в этом классе.'], 404);
}

$stmt = $pdo->prepare(
    'UPDATE class_students
     SET pin_hash = NULL, activated_at = NULL
     WHERE class_id = :class_id AND student_id = :student_id'
);
$stmt->execute(['class_id' => $classId, 'student_id' => $studentId]);

audit_event('student_pin_reset', 'user', $studentId, ['class_id' => $classId], $schoolId, (int)$user['id']);

json_response(['ok' => true]);
