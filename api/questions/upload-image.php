<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);

$questionId = (int)($_POST['question_id'] ?? 0);
if ($questionId < 1 || empty($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Выберите вопрос и изображение.'], 422);
}

$pdo = app_db();
$question = question_editor_question($pdo, $user, $questionId, true);
$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Не удалось загрузить изображение.'], 422);
}
if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Изображение слишком большое. Максимум 5 МБ.'], 413);
}

$tmp = (string)($file['tmp_name'] ?? '');
$info = @getimagesize($tmp);
$mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
$extensions = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($extensions[$mime])) {
    json_response(['ok' => false, 'error' => 'Поддерживаются PNG, JPG, WEBP и GIF.'], 422);
}

$dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'Не удалось подготовить хранилище изображений.'], 500);
}

$storedName = 'question-' . $questionId . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
$destination = $dir . DIRECTORY_SEPARATOR . $storedName;
if (!move_uploaded_file($tmp, $destination)) {
    json_response(['ok' => false, 'error' => 'Не удалось сохранить изображение.'], 500);
}

$stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM question_assets WHERE question_id = :question_id');
$stmt->execute(['question_id' => $questionId]);
$position = (int)$stmt->fetchColumn();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO question_assets (question_id, stored_name, original_name, mime_type, position)
         VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
    );
    $stmt->execute([
        'question_id' => $questionId,
        'stored_name' => $storedName,
        'original_name' => basename((string)($file['name'] ?? 'image.' . $extensions[$mime])),
        'mime_type' => $mime,
        'position' => $position,
    ]);
    $assetId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($destination);
    throw $e;
}

audit_event('question_image_added', 'question', $questionId, [
    'assignment_id' => (int)$question['assignment_id'],
    'asset_id' => $assetId,
], (int)$question['school_id'], (int)$user['id']);

json_response([
    'ok' => true,
    'asset' => [
        'id' => $assetId,
        'original_name' => basename((string)($file['name'] ?? '')),
        'mime_type' => $mime,
        'url' => './api/questions/asset.php?id=' . $assetId,
    ],
], 201);
