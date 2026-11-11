<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$assignmentId = (int)($data['assignment_id'] ?? 0);
$preview = filter_var($data['preview'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($classId < 1 || $assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите класс и конкретную работу.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$classStmt = $pdo->prepare(
    'SELECT id, COALESCE(display_name, name) AS name
     FROM classes
     WHERE id = :class_id AND school_id = :school_id
     LIMIT 1'
);
$classStmt->execute([
    'class_id' => $classId,
    'school_id' => $schoolId,
]);
$class = $classStmt->fetch();
if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс не найден в выбранной школе.'], 404);
}

$assignmentStmt = $pdo->prepare(
    'SELECT id, title, teacher_id, subject_id, status
     FROM assignments
     WHERE id = :assignment_id AND school_id = :school_id
     LIMIT 1'
);
$assignmentStmt->execute([
    'assignment_id' => $assignmentId,
    'school_id' => $schoolId,
]);
$assignment = $assignmentStmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Работа не найдена в выбранной школе.'], 404);
}

if (!$manager) {
    if ((int)$assignment['teacher_id'] !== (int)$user['id']) {
        json_response(['ok' => false, 'error' => 'Сбрасывать результаты этой работы может её учитель или администратор школы.'], 403);
    }

    $accessStmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_classes
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND class_id = :class_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $accessStmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'class_id' => $classId,
        'subject_id' => (int)$assignment['subject_id'],
    ]);
    if (!$accessStmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому классу и предмету.'], 403);
    }
}

$attemptStmt = $pdo->prepare(
    'SELECT at.id, at.student_id, at.status
     FROM attempts at
     JOIN class_students cs ON cs.student_id = at.student_id
     WHERE at.assignment_id = :assignment_id
       AND cs.class_id = :class_id
     ORDER BY at.id'
);
$attemptStmt->execute([
    'assignment_id' => $assignmentId,
    'class_id' => $classId,
]);
$attempts = $attemptStmt->fetchAll();

$attemptIds = array_values(array_map('intval', array_column($attempts, 'id')));
$studentIds = array_values(array_unique(array_map('intval', array_column($attempts, 'student_id'))));
$activeAttempts = 0;
foreach ($attempts as $attempt) {
    if ((string)($attempt['status'] ?? '') === 'in_progress') {
        $activeAttempts++;
    }
}

if ($preview) {
    json_response([
        'ok' => true,
        'preview' => true,
        'class' => [
            'id' => (int)$class['id'],
            'name' => (string)$class['name'],
        ],
        'assignment' => [
            'id' => (int)$assignment['id'],
            'title' => (string)$assignment['title'],
        ],
        'attempts_count' => count($attemptIds),
        'students_affected' => count($studentIds),
        'active_attempts' => $activeAttempts,
    ]);
}

if (!$attemptIds) {
    json_response([
        'ok' => true,
        'attempts_deleted' => 0,
        'students_affected' => 0,
        'message' => 'По этой работе у класса уже нет результатов для сброса.',
    ]);
}

$placeholders = implode(',', array_fill(0, count($attemptIds), '?'));

$pdo->beginTransaction();
try {
    $deleteStmt = $pdo->prepare('DELETE FROM attempts WHERE id IN (' . $placeholders . ')');
    $deleteStmt->execute($attemptIds);
    $deleted = $deleteStmt->rowCount();

    if ($deleted !== count($attemptIds)) {
        throw new RuntimeException('Количество удалённых попыток изменилось во время сброса. Повторите действие.');
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

audit_event('attempt_class_results_reset', 'assignment', $assignmentId, [
    'class_id' => $classId,
    'assignment_id' => $assignmentId,
    'attempts_deleted' => count($attemptIds),
    'students_count' => count($studentIds),
    'active_attempts_deleted' => $activeAttempts,
    'student_ids' => $studentIds,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'attempts_deleted' => count($attemptIds),
    'students_affected' => count($studentIds),
    'active_attempts_deleted' => $activeAttempts,
    'message' => 'Работа класса сброшена: удалено попыток — ' . count($attemptIds)
        . ', учеников — ' . count($studentIds)
        . '. Теперь ученики могут выполнить её заново.',
]);
