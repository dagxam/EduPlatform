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

$items = backup_list();

json_response([
    'ok' => true,
    'items' => $items,
    'automatic_enabled' => true,
    'retention_days' => 30,
    'last_automatic' => array_values(array_filter(
        $items,
        static fn(array $item): bool => ($item['kind'] ?? '') === 'automatic'
    ))[0] ?? null,
]);
