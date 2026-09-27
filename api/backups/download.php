<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Резервные копии доступны только главному администратору UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$file = trim((string)($_GET['file'] ?? ''));
$path = backup_find_archive($file);
if ($path === null) {
    json_response(['ok' => false, 'error' => 'Резервная копия не найдена.'], 404);
}

audit_event('platform_backup_downloaded', 'backup', null, [
    'file' => basename($path),
], null, (int)$user['id']);

header_remove('Content-Type');
header_remove('Cache-Control');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

while (!feof($handle)) {
    $chunk = fread($handle, 1024 * 1024);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($handle);
exit;
