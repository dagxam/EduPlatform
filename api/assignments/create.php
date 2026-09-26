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
$type = trim((string)($data['type'] ?? 'quiz'));
$timeLimit = isset($data['time_limit_minutes']) && $data['time_limit_minutes'] !== ''
    ? max(1, (int)$data['time_limit_minutes'])
    : null;
$maxAttempts = max(1, min(10, (int)($data['max_attempts'] ?? 1)));
$focusPolicy = in_array(($data['focus_policy'] ?? 'allow'), ['allow', 'strict'], true)
    ? (string)$data['focus_policy']
    : 'allow';

if ($title === '' || $subjectId < 1) {
    json_response(['ok' => false, 'error' => 'Укажите название и предмет.'], 422);
}
if (!in_array($type, ['quiz', 'file', 'independent'], true)) {
    $type = 'quiz';
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$isSchoolManager = can_manage_school($user, $schoolId);

if (!$isSchoolManager && !can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет прав для создания задания в этой школе.'], 403);
}

if ($isSchoolManager) {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM school_subjects
         WHERE school_id = :school_id AND subject_id = :subject_id AND is_active = 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
    ]);
} else {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM teacher_subjects
         WHERE school_id = :school_id AND teacher_id = :teacher_id AND subject_id = :subject_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => $subjectId,
    ]);
}
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Этот предмет недоступен вашему аккаунту.'], 403);
}

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

audit_event('assignment_created', 'assignment', $assignmentId, [
    'subject_id' => $subjectId,
    'library_item' => true,
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
