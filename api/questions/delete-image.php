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
    'SELECT qa.id, qa.stored_name, q.id AS question_id, q.assignment_id
     FROM question_assets qa
     JOIN questions q ON q.id = qa.question_id
     WHERE qa.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $assetId]);
$asset = $stmt->fetch();
if (!$asset) json_response(['ok' => false, 'error' => 'Изображение не найдено.'], 404);

$question = question_editor_question($pdo, $user, (int)$asset['question_id'], true);
$pdo->prepare('DELETE FROM question_assets WHERE id = :id')->execute(['id' => $assetId]);

$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets' . DIRECTORY_SEPARATOR . basename((string)$asset['stored_name']);
if (is_file($path)) @unlink($path);

audit_event('question_image_deleted', 'question', (int)$asset['question_id'], [
    'assignment_id' => (int)$asset['assignment_id'],
    'asset_id' => $assetId,
], (int)$question['school_id'], (int)$user['id']);

json_response(['ok' => true]);
