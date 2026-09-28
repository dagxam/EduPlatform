<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_migration.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Миграция базы доступна только главному администратору UROVIA.'], 403);
}

$data = read_json_body();
$hosts = [];

$primary = trim((string)($data['host'] ?? ''));
if ($primary !== '') $hosts[] = $primary;

$fallbacks = $data['fallback_hosts'] ?? [];
if (is_string($fallbacks)) {
    $fallbacks = preg_split('/[\s,;]+/', $fallbacks) ?: [];
}
if (is_array($fallbacks)) {
    foreach ($fallbacks as $host) {
        $host = trim((string)$host);
        if ($host !== '') $hosts[] = $host;
    }
}

if (!in_array('localhost', $hosts, true)) {
    $hosts[] = 'localhost';
}
$hosts = array_values(array_unique($hosts));

$base = [
    'port' => max(1, min(65535, (int)($data['port'] ?? 3306))),
    'database' => trim((string)($data['database'] ?? '')),
    'user' => trim((string)($data['user'] ?? '')),
    'password' => (string)($data['password'] ?? ''),
];

if ($base['database'] === '' || $base['user'] === '' || $base['password'] === '') {
    json_response(['ok' => false, 'error' => 'Заполните имя базы, пользователя и пароль.'], 422);
}

$selected = null;
$connectErrors = [];
foreach ($hosts as $host) {
    try {
        $candidate = $base + ['host' => $host];
        $probe = mysql_migration_connect($candidate);
        $probe->query('SELECT 1')->fetchColumn();
        $selected = $candidate;
        break;
    } catch (Throwable $e) {
        $connectErrors[] = $host . ': ' . $e->getMessage();
    }
}

if ($selected === null) {
    json_response([
        'ok' => false,
        'error' => 'Не удалось подключиться к MySQL ни по одному из указанных адресов.',
        'details' => $connectErrors,
    ], 422);
}

$flag = maintenance_flag_path();
if (file_put_contents($flag, date(DATE_ATOM) . "\n", LOCK_EX) === false) {
    json_response(['ok' => false, 'error' => 'Не удалось включить режим обслуживания.'], 500);
}

try {
    // Give already-running student requests a short window to finish.
    usleep(800000);

    $summary = mysql_migration_run($selected);

    audit_event(
        'database_migrated_to_mysql',
        'database',
        null,
        [
            'host' => $summary['host'] ?? null,
            'database' => $summary['database'] ?? null,
            'total_rows' => $summary['total_rows'] ?? null,
            'sqlite_backup' => $summary['sqlite_backup']['file'] ?? null,
        ],
        null,
        (int)$user['id']
    );

    @unlink($flag);

    json_response([
        'ok' => true,
        'message' => 'База успешно перенесена в MySQL. SQLite сохранена для аварийного отката.',
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    @unlink($flag);
    error_log('[UROVIA] MySQL migration failed: ' . $e->getMessage());

    json_response([
        'ok' => false,
        'error' => 'Миграция остановлена без переключения базы: ' . $e->getMessage(),
        'code' => 'MYSQL_MIGRATION_FAILED',
    ], 500);
}
