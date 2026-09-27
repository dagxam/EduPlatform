<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);

$stmt = $pdo->prepare(
    'SELECT a.id, a.school_id, a.teacher_id, a.title, a.status, a.workflow_status,
            a.subject_id, ai.stored_name AS import_stored_name
     FROM assignments a
     LEFT JOIN assignment_imports ai ON ai.assignment_id = a.id
     WHERE a.id = :id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'id' => $assignmentId,
    'school_id' => $schoolId,
]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено в выбранной школе.'], 404);
}

$isManager = can_manage_school($user, $schoolId);
$isOwner = (int)$assignment['teacher_id'] === (int)$user['id'];

if (!$isManager && !$isOwner) {
    json_response([
        'ok' => false,
        'error' => 'Удалить задание может его автор или администратор школы.',
    ], 403);
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE assignment_id = :assignment_id');
$stmt->execute(['assignment_id' => $assignmentId]);
$attemptsCount = (int)$stmt->fetchColumn();

if ($attemptsCount > 0) {
    json_response([
        'ok' => false,
        'error' => 'Удаление заблокировано: по этому заданию уже есть попытки учеников. Результаты учеников не удаляются.',
        'code' => 'ASSIGNMENT_HAS_ATTEMPTS',
    ], 409);
}

$stmt = $pdo->prepare(
    'SELECT qa.stored_name
     FROM question_assets qa
     JOIN questions q ON q.id = qa.question_id
     WHERE q.assignment_id = :assignment_id'
);
$stmt->execute(['assignment_id' => $assignmentId]);
$assetFiles = array_values(array_filter(array_map(
    'strval',
    array_column($stmt->fetchAll(), 'stored_name')
)));

$title = (string)$assignment['title'];
$subjectId = (int)($assignment['subject_id'] ?? 0);
$importStoredName = trim((string)($assignment['import_stored_name'] ?? ''));

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = :id AND school_id = :school_id');
    $stmt->execute([
        'id' => $assignmentId,
        'school_id' => $schoolId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Задание не удалось удалить.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';

if ($importStoredName !== '') {
    $path = $root . DIRECTORY_SEPARATOR . 'assignment-imports' . DIRECTORY_SEPARATOR . basename($importStoredName);
    if (is_file($path)) @unlink($path);
}

$assetDir = $root . DIRECTORY_SEPARATOR . 'question-assets';
foreach ($assetFiles as $storedName) {
    $path = $assetDir . DIRECTORY_SEPARATOR . basename($storedName);
    if (is_file($path)) @unlink($path);
}

audit_event('assignment_deleted', 'assignment', $assignmentId, [
    'title' => $title,
    'subject_id' => $subjectId,
    'deleted_import_file' => $importStoredName !== '',
    'deleted_assets_count' => count($assetFiles),
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment_id' => $assignmentId,
    'message' => 'Задание удалено. Теперь можно исправить файл и загрузить его заново.',
]);
