<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Оформление школы может изменять только главный администратор UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, false);
$color = strtolower(trim((string)($_POST['theme_color'] ?? '#1d68f0')));
$removeFavicon = (string)($_POST['remove_favicon'] ?? '') === '1';

if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
    json_response(['ok' => false, 'error' => 'Цвет должен быть в формате #RRGGBB.'], 422);
}

$pdo = app_db();
$current = school_branding($schoolId);
$faviconData = $removeFavicon ? null : $current['favicon_data'];

if (!empty($_FILES['favicon']) && ($_FILES['favicon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['favicon'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Не удалось загрузить favicon.'], 422);
    }
    if ((int)($file['size'] ?? 0) > 512 * 1024) {
        json_response(['ok' => false, 'error' => 'Favicon слишком большой. Максимум — 512 КБ.'], 413);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $imageInfo = @getimagesize($tmp);
    if (!$imageInfo || empty($imageInfo['mime'])) {
        json_response(['ok' => false, 'error' => 'Файл не распознан как изображение.'], 422);
    }

    $mime = strtolower((string)$imageInfo['mime']);
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        json_response(['ok' => false, 'error' => 'Для favicon используйте PNG, JPG или WebP.'], 422);
    }

    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    if ($width < 16 || $height < 16 || $width > 1024 || $height > 1024) {
        json_response(['ok' => false, 'error' => 'Размер favicon должен быть от 16×16 до 1024×1024 пикселей.'], 422);
    }
    if ($width !== $height) {
        json_response(['ok' => false, 'error' => 'Для favicon используйте квадратное изображение.'], 422);
    }

    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        json_response(['ok' => false, 'error' => 'Не удалось прочитать favicon.'], 422);
    }
    $faviconData = 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

$stmt = $pdo->prepare(
    'UPDATE schools
     SET theme_color = :theme_color,
         favicon_data = :favicon_data,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :school_id'
);
$stmt->execute([
    'theme_color' => $color,
    'favicon_data' => $faviconData,
    'school_id' => $schoolId,
]);

audit_event('school_branding_updated', 'school', $schoolId, [
    'theme_color' => $color,
    'favicon_changed' => !empty($_FILES['favicon']) || $removeFavicon,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'branding' => school_branding($schoolId),
]);
