<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$classId = (int)($data['class_id'] ?? 0);

if ($assignmentId < 1 || $classId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите задание и класс.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);

$stmt = $pdo->prepare(
    'SELECT a.id, a.subject_id, a.title
     FROM assignments a
     WHERE a.id = :assignment_id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'assignment_id' => $assignmentId,
    'school_id' => $schoolId,
]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено в выбранной школе.'], 404);
}

if (can_manage_school($user, $schoolId)) {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM classes
         WHERE id = :class_id AND school_id = :school_id
         LIMIT 1'
    );
    $stmt->execute([
        'class_id' => $classId,
        'school_id' => $schoolId,
    ]);
} else {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_classes
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND class_id = :class_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'class_id' => $classId,
        'subject_id' => (int)$assignment['subject_id'],
    ]);
}
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Этот класс недоступен для данного предмета.'], 403);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO assignment_classes (assignment_id, class_id)
         VALUES (:assignment_id, :class_id)'
    );
    $stmt->execute([
        'assignment_id' => $assignmentId,
        'class_id' => $classId,
    ]);

    $stmt = $pdo->prepare(
        'UPDATE assignments
         SET status = "published", updated_at = CURRENT_TIMESTAMP
         WHERE id = :assignment_id'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('assignment_assigned_to_class', 'assignment', $assignmentId, [
    'class_id' => $classId,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment_id' => $assignmentId,
    'class_id' => $classId,
    'status' => 'published',
]);
