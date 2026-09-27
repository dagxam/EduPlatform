<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$targetId = max(1, (int)($_GET['user_id'] ?? $user['id']));
$pdo = app_db();

if ($targetId !== (int)$user['id']) {
    $schoolId = current_school_id();
    if ($schoolId === null || !can_access_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет доступа к изображению.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM school_users
         WHERE school_id = :school_id AND user_id = :user_id AND is_active = 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $targetId]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Сотрудник не найден.'], 404);
    }
}

$stmt = $pdo->prepare('SELECT avatar_name FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $targetId]);
$name = basename((string)($stmt->fetchColumn() ?: ''));
if ($name === '') {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header_remove('Content-Type');
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
