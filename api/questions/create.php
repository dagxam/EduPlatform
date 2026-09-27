<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$pdo = app_db();
$assignment = question_editor_assignment($pdo, $user, $assignmentId, true);
$normalized = question_editor_normalize_payload($data);

$stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM questions WHERE assignment_id = :assignment_id');
$stmt->execute(['assignment_id' => $assignmentId]);
$position = (int)$stmt->fetchColumn();

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
    );
    $stmt->execute([
        'assignment_id' => $assignmentId,
        'type' => $normalized['type'],
        'text' => $normalized['text'],
        'points' => $normalized['points'],
        'position' => $position,
        'correct_text' => $normalized['correct_text'],
        'interaction_type' => $normalized['interaction_type'],
        'settings_json' => $normalized['settings_json'],
    ]);
    $questionId = (int)$pdo->lastInsertId();
    question_editor_replace_options($pdo, $questionId, $normalized['options']);
    question_editor_refresh_import_count($pdo, $assignmentId);
    $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $assignmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('question_created', 'question', $questionId, [
    'assignment_id' => $assignmentId,
    'interaction_type' => $normalized['interaction_type'],
], (int)$assignment['school_id'], (int)$user['id']);

json_response(['ok' => true, 'question_id' => $questionId], 201);
