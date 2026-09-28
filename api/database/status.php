<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}

$pdo = app_db();
$driver = db_driver($pdo);
$summary = null;
$summaryPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mysql-migration.json';

if (is_file($summaryPath)) {
    $raw = file_get_contents($summaryPath);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        unset($decoded['password']);
        $summary = $decoded;
    }
}

json_response([
    'ok' => true,
    'driver' => $driver,
    'mysql_enabled' => $driver === 'mysql',
    'sqlite_source_exists' => is_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'eduplatform.sqlite'),
    'migration' => $summary,
]);
