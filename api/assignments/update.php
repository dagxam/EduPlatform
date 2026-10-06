<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/questions/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$title = trim((string)($data['title'] ?? ''));
$timeLimit = ($data['time_limit_minutes'] ?? '') !== ''
    ? (int)$data['time_limit_minutes']
    : null;
$maxAttempts = (int)($data['max_attempts'] ?? 1);
$focusPolicy = trim((string)($data['focus_policy'] ?? 'allow'));

$pdo = app_db();
$assignment = question_editor_assignment($pdo, $user, $assignmentId, true);

if ($title === '') {
    json_response(['ok' => false, 'error' => 'Введите название задания.'], 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($title) : strlen($title)) > 500) {
    json_response(['ok' => false, 'error' => 'Название задания слишком длинное.'], 422);
}
if ($timeLimit !== null && ($timeLimit < 1 || $timeLimit > 300)) {
    json_response(['ok' => false, 'error' => 'Время выполнения должно быть от 1 до 300 минут.'], 422);
}
if ($maxAttempts < 1 || $maxAttempts > 10) {
    json_response(['ok' => false, 'error' => 'Количество попыток должно быть от 1 до 10.'], 422);
}
if (!in_array($focusPolicy, ['allow', 'strict'], true)) {
    json_response(['ok' => false, 'error' => 'Неизвестный режим контроля.'], 422);
}

$stmt = $pdo->prepare(
    'UPDATE assignments
     SET title = :title,
         time_limit_minutes = :time_limit_minutes,
         max_attempts = :max_attempts,
         focus_policy = :focus_policy,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'title' => $title,
    'time_limit_minutes' => $timeLimit,
    'max_attempts' => $maxAttempts,
    'focus_policy' => $focusPolicy,
    'id' => $assignmentId,
]);

audit_event('assignment_settings_updated', 'assignment', $assignmentId, [
    'title' => $title,
    'time_limit_minutes' => $timeLimit,
    'max_attempts' => $maxAttempts,
    'focus_policy' => $focusPolicy,
], (int)$assignment['school_id'], (int)$user['id']);

json_response([
    'ok' => true,
    'assignment' => [
        'id' => $assignmentId,
        'title' => $title,
        'time_limit_minutes' => $timeLimit,
        'max_attempts' => $maxAttempts,
        'focus_policy' => $focusPolicy,
    ],
    'message' => 'Настройки задания сохранены.',
]);
