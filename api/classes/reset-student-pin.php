<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
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
$schoolId = require_active_school($user, false);

if (($user['role'] ?? '') === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_classes tc
         JOIN class_students cs ON cs.class_id = tc.class_id
         WHERE tc.school_id = :school_id
           AND tc.teacher_id = :teacher_id
           AND tc.class_id = :class_id
           AND cs.student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'class_id' => $classId,
        'student_id' => $studentId,
    ]);
} else {
    if (!can_manage_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM classes c
         JOIN class_students cs ON cs.class_id = c.id
         WHERE c.id = :class_id
           AND c.school_id = :school_id
           AND cs.student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'class_id' => $classId,
        'school_id' => $schoolId,
        'student_id' => $studentId,
    ]);
}

if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Ученик не найден или класс не назначен этому сотруднику.'], 404);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE class_students
         SET pin_hash = NULL, activated_at = NULL
         WHERE class_id = :class_id AND student_id = :student_id'
    );
    $stmt->execute(['class_id' => $classId, 'student_id' => $studentId]);

    $pdo->prepare(
        'UPDATE users
         SET session_version = session_version + 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :student_id'
    )->execute(['student_id' => $studentId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

audit_event('student_pin_reset', 'user', $studentId, ['class_id' => $classId], $schoolId, (int)$user['id']);

json_response(['ok' => true]);
