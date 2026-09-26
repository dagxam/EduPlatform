<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}
$schoolId = require_active_school($user, true);
$data = read_json_body();
$teacherId = (int)($data['teacher_id'] ?? 0);
$pairs = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];

if ($teacherId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран учитель.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT 1 FROM school_users
     WHERE school_id = :school_id AND user_id = :teacher_id
       AND role = "teacher" AND is_active = 1'
);
$stmt->execute(['school_id' => $schoolId, 'teacher_id' => $teacherId]);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Учитель не относится к выбранной школе.'], 404);
}

$normalized = [];
foreach ($pairs as $pair) {
    if (!is_array($pair)) continue;
    $subjectId = (int)($pair['subject_id'] ?? 0);
    $classId = (int)($pair['class_id'] ?? 0);
    if ($subjectId < 1 || $classId < 1) continue;

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM school_subjects ss
         JOIN classes c ON c.school_id = ss.school_id
         WHERE ss.school_id = :school_id
           AND ss.subject_id = :subject_id
           AND ss.is_active = 1
           AND c.id = :class_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
        'class_id' => $classId,
    ]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Один из предметов или классов недоступен в этой школе.'], 422);
    }
    $normalized[$subjectId . ':' . $classId] = [$subjectId, $classId];
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'DELETE FROM teacher_classes WHERE school_id = :school_id AND teacher_id = :teacher_id'
    )->execute(['school_id' => $schoolId, 'teacher_id' => $teacherId]);

    $pdo->prepare(
        'DELETE FROM teacher_subjects WHERE school_id = :school_id AND teacher_id = :teacher_id'
    )->execute(['school_id' => $schoolId, 'teacher_id' => $teacherId]);

    $insertClass = $pdo->prepare(
        'INSERT INTO teacher_classes (school_id, teacher_id, class_id, subject_id)
         VALUES (:school_id, :teacher_id, :class_id, :subject_id)'
    );
    $insertSubject = $pdo->prepare(
        'INSERT OR IGNORE INTO teacher_subjects (school_id, teacher_id, subject_id)
         VALUES (:school_id, :teacher_id, :subject_id)'
    );

    foreach ($normalized as [$subjectId, $classId]) {
        $insertClass->execute([
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);
        $insertSubject->execute([
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
            'subject_id' => $subjectId,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('teacher_assignments_updated', 'user', $teacherId, [
    'pairs_count' => count($normalized),
], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'assignments_count' => count($normalized)]);
