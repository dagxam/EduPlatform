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
$normalized = question_editor_normalize_payload($data);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE questions
         SET type = :type,
             text = :text,
             points = :points,
             correct_text = :correct_text,
             interaction_type = :interaction_type,
             settings_json = :settings_json
         WHERE id = :id'
    );
    $stmt->execute([
        'type' => $normalized['type'],
        'text' => $normalized['text'],
        'points' => $normalized['points'],
        'correct_text' => $normalized['correct_text'],
        'interaction_type' => $normalized['interaction_type'],
        'settings_json' => $normalized['settings_json'],
        'id' => $questionId,
    ]);
    question_editor_replace_options($pdo, $questionId, $normalized['options']);
    $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => (int)$question['assignment_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('question_updated', 'question', $questionId, [
    'assignment_id' => (int)$question['assignment_id'],
    'interaction_type' => $normalized['interaction_type'],
], (int)$question['school_id'], (int)$user['id']);

json_response(['ok' => true, 'question_id' => $questionId]);
