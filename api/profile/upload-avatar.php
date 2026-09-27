<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$targetId = max(1, (int)($_POST['user_id'] ?? $user['id']));
$self = $targetId === (int)$user['id'];
$pdo = app_db();
$schoolId = current_school_id();

if (!$self) {
    if ($schoolId === null || !can_manage_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет прав на изменение фото.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT role FROM school_users
         WHERE school_id = :school_id AND user_id = :user_id AND is_active = 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $targetId]);
    $role = $stmt->fetchColumn();
    if ($role === false || (!is_platform_admin($user) && (string)$role !== 'teacher')) {
        json_response(['ok' => false, 'error' => 'Этот профиль может изменить только главный администратор UVORIA.'], 403);
    }
}

$file = $_FILES['avatar'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Выберите изображение PNG, JPG или WebP.'], 422);
}
if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Фото должно быть не больше 5 МБ.'], 422);
}

$tmp = (string)$file['tmp_name'];
$info = @getimagesize($tmp);
if (!$info || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    json_response(['ok' => false, 'error' => 'Поддерживаются только PNG, JPG и WebP.'], 422);
}

$dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'Не удалось подготовить хранилище фото.'], 500);
}

$ext = match ((int)$info[2]) {
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
    default => 'jpg',
};
$newName = 'staff-' . $targetId . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
$destination = $dir . DIRECTORY_SEPARATOR . $newName;
$saved = false;

if (function_exists('imagecreatetruecolor')) {
    $src = match ((int)$info[2]) {
        IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmp) : false,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmp) : false,
    };
    if ($src) {
        $width = imagesx($src);
        $height = imagesy($src);
        $side = min($width, $height);
        $srcX = (int)(($width - $side) / 2);
        $srcY = (int)(($height - $side) / 2);
        $dst = imagecreatetruecolor(512, 512);
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, 512, 512, $side, $side);
        $saved = match ($ext) {
            'png' => imagepng($dst, $destination, 7),
            'webp' => function_exists('imagewebp') ? imagewebp($dst, $destination, 86) : false,
            default => imagejpeg($dst, $destination, 88),
        };
        imagedestroy($dst);
        imagedestroy($src);
    }
}

if (!$saved) {
    $saved = move_uploaded_file($tmp, $destination);
}
if (!$saved) {
    json_response(['ok' => false, 'error' => 'Не удалось сохранить фото.'], 500);
}

$stmt = $pdo->prepare('SELECT avatar_name FROM users WHERE id = :id');
$stmt->execute(['id' => $targetId]);
$oldName = basename((string)($stmt->fetchColumn() ?: ''));

$pdo->prepare(
    'UPDATE users SET avatar_name = :avatar_name, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
)->execute(['avatar_name' => $newName, 'id' => $targetId]);

if ($oldName !== '') {
    $oldPath = $dir . DIRECTORY_SEPARATOR . $oldName;
    if (is_file($oldPath)) @unlink($oldPath);
}

audit_event('staff_avatar_updated', 'user', $targetId, [], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'avatar_url' => './api/profile/avatar.php?user_id=' . $targetId . '&v=' . rawurlencode($newName),
]);
