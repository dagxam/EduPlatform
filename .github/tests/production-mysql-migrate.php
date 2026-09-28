<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/database/_migration.php';

if ($argc < 2) {
    fwrite(STDERR, "Credentials JSON path is required.\n");
    exit(2);
}

$raw = file_get_contents($argv[1]);
$config = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "Credentials JSON is invalid.\n");
    exit(2);
}

try {
    $summary = mysql_migration_run($config);
    $safe = [
        'ok' => true,
        'host' => $summary['host'] ?? null,
        'database' => $summary['database'] ?? null,
        'total_rows' => $summary['total_rows'] ?? null,
        'tables' => $summary['tables'] ?? [],
        'sqlite_backup' => $summary['sqlite_backup'] ?? null,
        'sqlite_source_preserved' => $summary['sqlite_source_preserved'] ?? false,
    ];
    echo json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Production MySQL migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
