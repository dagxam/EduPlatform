<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);

$data = read_json_body();
$questionId = (int)($data['question_id'] ?? 0);
$pdo = app_db();
$question = question_editor_question($pdo, $user, $questionId, true);
$assignmentId = (int)$question['assignment_id'];
$assignment = question_editor_assignment($pdo, $user, $assignmentId, true);
$hasHistory = (int)($assignment['attempts_count'] ?? 0) > 0;

$stmt = $pdo->prepare('SELECT stored_name FROM question_assets WHERE question_id = :question_id');
$stmt->execute(['question_id' => $questionId]);
$files = array_map('strval', array_column($stmt->fetchAll(), 'stored_name'));

$pdo->beginTransaction();
try {
    if ($hasHistory) {
        $pdo->prepare('UPDATE questions SET is_active = 0 WHERE id = :id')
            ->execute(['id' => $questionId]);
    } else {
        $pdo->prepare('DELETE FROM questions WHERE id = :id')->execute(['id' => $questionId]);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM questions
         WHERE assignment_id = :assignment_id AND is_active = 1
         ORDER BY position, id'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    $update = $pdo->prepare('UPDATE questions SET position = :position WHERE id = :id AND is_active = 1');
    foreach ($stmt->fetchAll() as $index => $row) {
        $update->execute(['position' => $index + 1, 'id' => (int)$row['id']]);
    }

    question_editor_refresh_import_count($pdo, $assignmentId);
    $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $assignmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

if (!$hasHistory) {
    $assetDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets';
    foreach ($files as $storedName) {
        $path = $assetDir . DIRECTORY_SEPARATOR . basename($storedName);
        if (is_file($path)) @unlink($path);
    }
}

audit_event($hasHistory ? 'question_archived' : 'question_deleted', 'question', $questionId, [
    'assignment_id' => $assignmentId,
    'preserved_for_history' => $hasHistory,
], (int)$question['school_id'], (int)$user['id']);

json_response(['ok' => true, 'archived' => $hasHistory]);
