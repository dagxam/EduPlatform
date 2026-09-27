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

$data = read_json_body();
$file = trim((string)($data['file'] ?? ''));
if ($file === '') {
    json_response(['ok' => false, 'error' => 'Не выбрана резервная копия.'], 422);
}

if (!backup_delete($file)) {
    json_response(['ok' => false, 'error' => 'Резервная копия не найдена.'], 404);
}

audit_event('platform_backup_deleted', 'backup', null, [
    'file' => basename($file),
], null, (int)$user['id']);

json_response(['ok' => true]);
