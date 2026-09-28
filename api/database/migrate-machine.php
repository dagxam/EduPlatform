<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_migration.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$storage = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
$privateKeyPath = $storage . DIRECTORY_SEPARATOR . 'mysql-migration-private.pem';
$encryptedPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql-credentials.enc';
$donePath = $storage . DIRECTORY_SEPARATOR . 'mysql-machine-migration.done';

if (is_file($donePath) || is_file(mysql_migration_config_path())) {
    json_response([
        'ok' => true,
        'already_migrated' => true,
        'message' => 'MySQL уже включён.',
    ]);
}

if (!is_file($privateKeyPath) || !is_file($encryptedPath)) {
    json_response([
        'ok' => false,
        'error' => 'Одноразовый пакет миграции не подготовлен.',
        'code' => 'MIGRATION_PACKAGE_MISSING',
    ], 409);
}

$encryptedRaw = file_get_contents($encryptedPath);
$privateRaw = file_get_contents($privateKeyPath);
if ($encryptedRaw === false || $privateRaw === false) {
    json_response(['ok' => false, 'error' => 'Не удалось прочитать одноразовый пакет миграции.'], 500);
}

$package = json_decode($encryptedRaw, true);
$ciphertext = is_array($package) ? base64_decode((string)($package['ciphertext'] ?? ''), true) : false;
if ($ciphertext === false || $ciphertext === '') {
    json_response(['ok' => false, 'error' => 'Зашифрованный пакет повреждён.']);
}

$privateKey = openssl_pkey_get_private($privateRaw);
if ($privateKey === false) {
    json_response(['ok' => false, 'error' => 'Временный ключ миграции повреждён.'], 500);
}

$plain = '';
$decrypted = openssl_private_decrypt(
    $ciphertext,
    $plain,
    $privateKey,
    OPENSSL_PKCS1_OAEP_PADDING
);
if (!$decrypted) {
    json_response(['ok' => false, 'error' => 'Не удалось расшифровать параметры MySQL.']);
}

$credentials = json_decode($plain, true);
if (!is_array($credentials)) {
    json_response(['ok' => false, 'error' => 'Параметры MySQL имеют неверный формат.']);
}

$hosts = [];
foreach ((array)($credentials['hosts'] ?? []) as $host) {
    $host = trim((string)$host);
    if ($host !== '') $hosts[] = $host;
}
if (!in_array('localhost', $hosts, true)) $hosts[] = 'localhost';
$hosts = array_values(array_unique($hosts));

$base = [
    'port' => max(1, min(65535, (int)($credentials['port'] ?? 3306))),
    'database' => trim((string)($credentials['database'] ?? '')),
    'user' => trim((string)($credentials['user'] ?? '')),
    'password' => (string)($credentials['password'] ?? ''),
];

if ($base['database'] === '' || $base['user'] === '' || $base['password'] === '') {
    json_response(['ok' => false, 'error' => 'Зашифрованные параметры MySQL неполные.']);
}

$selected = null;
$errors = [];
foreach ($hosts as $host) {
    try {
        $candidate = $base + ['host' => $host];
        $probe = mysql_migration_connect($candidate);
        $probe->query('SELECT 1')->fetchColumn();
        $selected = $candidate;
        break;
    } catch (Throwable $e) {
        $errors[] = $host . ': ' . $e->getMessage();
    }
}

if ($selected === null) {
    json_response([
        'ok' => false,
        'error' => 'MySQL недоступен по указанным адресам.',
        'details' => $errors,
    ]);
}

$flag = maintenance_flag_path();
if (file_put_contents($flag, date(DATE_ATOM) . "\n", LOCK_EX) === false) {
    json_response(['ok' => false, 'error' => 'Не удалось включить режим обслуживания.'], 500);
}

try {
    usleep(800000);
    $summary = mysql_migration_run($selected);

    $safeDone = [
        'completed_at' => date(DATE_ATOM),
        'host' => $summary['host'] ?? null,
        'database' => $summary['database'] ?? null,
        'total_rows' => $summary['total_rows'] ?? null,
        'sqlite_backup' => $summary['sqlite_backup']['file'] ?? null,
    ];
    file_put_contents(
        $donePath,
        json_encode($safeDone, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    @unlink($privateKeyPath);
    @unlink($encryptedPath);
    @unlink($flag);

    json_response([
        'ok' => true,
        'message' => 'SQLite успешно перенесена в MySQL.',
        'summary' => $safeDone,
    ]);
} catch (Throwable $e) {
    @unlink($flag);
    error_log('[UROVIA] Machine MySQL migration failed: ' . $e->getMessage());
    json_response([
        'ok' => false,
        'error' => 'Миграция остановлена без переключения базы: ' . $e->getMessage(),
        'code' => 'MYSQL_MIGRATION_FAILED',
    ], 500);
}
