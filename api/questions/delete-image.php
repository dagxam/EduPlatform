<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);

$data = read_json_body();
$assetId = (int)($data['asset_id'] ?? 0);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT qa.id, qa.stored_name, qa.position, qa.original_name, qa.mime_type,
            q.id AS question_id, q.assignment_id
     FROM question_assets qa
     JOIN questions q ON q.id = qa.question_id
     WHERE qa.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $assetId]);
$asset = $stmt->fetch();
if (!$asset) json_response(['ok' => false, 'error' => 'Изображение не найдено.'], 404);

$question = question_editor_question($pdo, $user, (int)$asset['question_id'], true);
$assignment = question_editor_assignment($pdo, $user, (int)$asset['assignment_id'], true);
$hasHistory = (int)($assignment['attempts_count'] ?? 0) > 0;
$effectiveQuestionId = (int)$asset['question_id'];
$deletedAssetId = $assetId;

if ($hasHistory) {
    $pdo->beginTransaction();
    try {
        $effectiveQuestionId = question_editor_fork_revision($pdo, (int)$asset['question_id']);
        $stmt = $pdo->prepare(
            'SELECT id
             FROM question_assets
             WHERE question_id = :question_id
               AND stored_name = :stored_name
               AND position = :position
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'question_id' => $effectiveQuestionId,
            'stored_name' => (string)$asset['stored_name'],
            'position' => (int)$asset['position'],
        ]);
        $copiedAssetId = (int)($stmt->fetchColumn() ?: 0);
        if ($copiedAssetId > 0) {
            $pdo->prepare('DELETE FROM question_assets WHERE id = :id')->execute(['id' => $copiedAssetId]);
            $deletedAssetId = $copiedAssetId;
        }
        $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => (int)$asset['assignment_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} else {
    $pdo->prepare('DELETE FROM question_assets WHERE id = :id')->execute(['id' => $assetId]);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM question_assets WHERE stored_name = :stored_name');
    $stmt->execute(['stored_name' => (string)$asset['stored_name']]);
    if ((int)$stmt->fetchColumn() === 0) {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets' . DIRECTORY_SEPARATOR . basename((string)$asset['stored_name']);
        if (is_file($path)) @unlink($path);
    }
}

audit_event('question_image_deleted', 'question', $effectiveQuestionId, [
    'assignment_id' => (int)$asset['assignment_id'],
    'asset_id' => $deletedAssetId,
    'previous_question_id' => $hasHistory ? (int)$asset['question_id'] : null,
], (int)$question['school_id'], (int)$user['id']);

json_response([
    'ok' => true,
    'question_id' => $effectiveQuestionId,
    'revised' => $hasHistory,
]);
