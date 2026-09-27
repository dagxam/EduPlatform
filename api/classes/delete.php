<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$confirmed = ($data['confirm_delete'] ?? false) === true;

if ($classId < 1 || !$confirmed) {
    json_response(['ok' => false, 'error' => 'Удаление класса не подтверждено.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
if (!can_manage_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}

$stmt = $pdo->prepare(
    'SELECT id, COALESCE(display_name, name) AS name, academic_year
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
    'SELECT u.id
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id AND u.role = "student"'
);
$stmt->execute(['class_id' => $classId]);
$studentIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM attempts
     WHERE student_id IN (
       SELECT student_id FROM class_students WHERE class_id = :class_id
     )'
);
$stmt->execute(['class_id' => $classId]);
$attemptsCount = (int)$stmt->fetchColumn();

$pdo->beginTransaction();
try {
    if ($studentIds) {
        $deleteStudent = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = "student"');
        foreach ($studentIds as $studentId) {
            $deleteStudent->execute(['id' => $studentId]);
        }
    }

    $stmt = $pdo->prepare(
        'DELETE FROM classes
         WHERE id = :class_id AND school_id = :school_id'
    );
    $stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Класс не удалось удалить.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('class_deleted', 'school', $schoolId, [
    'class_name' => (string)$class['name'],
    'academic_year' => (string)($class['academic_year'] ?? ''),
    'students_deleted' => count($studentIds),
    'attempts_deleted' => $attemptsCount,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'deleted' => [
        'class_id' => $classId,
        'class_name' => (string)$class['name'],
        'students' => count($studentIds),
        'attempts' => $attemptsCount,
    ],
    'message' => 'Класс и все его ученики удалены.',
]);
