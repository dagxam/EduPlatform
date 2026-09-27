<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Резервные копии доступны только главному администратору UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

try {
    $backup = backup_create_archive(app_db(), 'manual');
    backup_cleanup(30);

    audit_event('platform_backup_created', 'backup', null, [
        'file' => $backup['file'] ?? null,
        'size_bytes' => $backup['size_bytes'] ?? null,
        'kind' => 'manual',
    ], null, (int)$user['id']);

    json_response([
        'ok' => true,
        'backup' => $backup,
    ], 201);
} catch (Throwable $e) {
    error_log('UVORIA manual backup failed: ' . $e->getMessage());
    json_response([
        'ok' => false,
        'error' => 'Не удалось создать резервную копию. Проверьте свободное место и поддержку архивов на сервере.',
    ], 500);
}
