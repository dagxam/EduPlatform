<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$title = trim((string)($data['title'] ?? ''));
$subjectId = (int)($data['subject_id'] ?? 0);
$classId = (int)($data['class_id'] ?? 0);
$type = trim((string)($data['type'] ?? 'quiz'));
$timeLimit = isset($data['time_limit_minutes']) && $data['time_limit_minutes'] !== ''
    ? max(1, (int)$data['time_limit_minutes'])
    : null;
$maxAttempts = max(1, min(10, (int)($data['max_attempts'] ?? 1)));
$focusPolicy = in_array(($data['focus_policy'] ?? 'allow'), ['allow', 'strict'], true)
    ? (string)$data['focus_policy']
    : 'allow';

if ($title === '' || $subjectId < 1 || $classId < 1) {
    json_response(['ok' => false, 'error' => 'Укажите название, предмет и класс.'], 422);
}
if (!in_array($type, ['quiz', 'file', 'independent'], true)) {
    $type = 'quiz';
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
if (!can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Для этого аккаунта не включена роль учителя.'], 403);
}

$stmt = $pdo->prepare(
    'SELECT 1
     FROM teacher_classes tc
     JOIN school_subjects ss
       ON ss.school_id = tc.school_id AND ss.subject_id = tc.subject_id AND ss.is_active = 1
     JOIN classes c ON c.id = tc.class_id AND c.school_id = tc.school_id
     WHERE tc.school_id = :school_id
       AND tc.teacher_id = :teacher_id
       AND tc.subject_id = :subject_id
       AND tc.class_id = :class_id
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'teacher_id' => (int)$user['id'],
    'subject_id' => $subjectId,
    'class_id' => $classId,
]);
if (!$stmt->fetchColumn()) {
    json_response([
        'ok' => false,
        'error' => 'Этот предмет и класс не назначены вашему аккаунту администратором школы.',
    ], 403);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, type, status, max_attempts, time_limit_minutes, focus_policy)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :type, "draft", :max_attempts, :time_limit_minutes, :focus_policy)'
    );
    $stmt->execute([
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
        'title' => $title,
        'type' => $type,
        'max_attempts' => $maxAttempts,
        'time_limit_minutes' => $timeLimit,
        'focus_policy' => $focusPolicy,
    ]);
    $assignmentId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO assignment_classes (assignment_id, class_id)
         VALUES (:assignment_id, :class_id)'
    );
    $stmt->execute(['assignment_id' => $assignmentId, 'class_id' => $classId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('assignment_created', 'assignment', $assignmentId, [
    'class_id' => $classId,
    'subject_id' => $subjectId,
    'focus_policy' => $focusPolicy,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment' => [
        'id' => $assignmentId,
        'title' => $title,
        'status' => 'draft',
        'focus_policy' => $focusPolicy,
    ],
], 201);
