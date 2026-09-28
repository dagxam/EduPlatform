<?php
declare(strict_types=1);

function backup_storage_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
}

function backup_dir(): string
{
    return backup_storage_dir() . DIRECTORY_SEPARATOR . 'backups';
}

function backup_ensure_dir(): string
{
    $dir = backup_dir();
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог резервных копий.');
    }
    return $dir;
}

function backup_quote_sqlite_path(string $path): string
{
    return str_replace("'", "''", $path);
}

function backup_asset_files(): array
{
    $storage = backup_storage_dir();
    if (!is_dir($storage)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = substr($path, strlen($storage) + 1);
        if ($relative === false || $relative === '') {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        if (
            str_starts_with($relative, 'backups/') ||
            preg_match('/^eduplatform\.sqlite(?:-.+)?$/', basename($relative)) ||
            in_array(basename($relative), [
                '.htaccess',
                'db-config.php',
                'db-config.php.tmp',
                'maintenance.flag',
                '.mysql-migration.lock',
            ], true)
        ) {
            continue;
        }

        $files[] = [
            'path' => $path,
            'archive_path' => 'storage/' . $relative,
            'size' => (int)$file->getSize(),
        ];
    }

    return $files;
}

function backup_metadata_path(string $archivePath): string
{
    return $archivePath . '.meta.json';
}

function backup_write_metadata(string $archivePath, array $metadata): void
{
    $payload = json_encode(
        $metadata,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false || file_put_contents(backup_metadata_path($archivePath), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить сведения о резервной копии.');
    }
}

function backup_safe_mysql_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Некорректное имя объекта MySQL.');
    }
    return chr(96) . $name . chr(96);
}

function backup_mysql_dump(PDO $pdo, string $path): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать временный MySQL-дамп.');
    }

    try {
        fwrite($handle, "-- UROVIA MySQL backup\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $tableRow) {
            $table = (string)($tableRow[0] ?? '');
            if ($table === '') continue;
            $quotedTable = backup_safe_mysql_identifier($table);

            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            $createSql = (string)($createRow[1] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('Не удалось получить структуру MySQL-таблицы ' . $table . '.');
            }

            fwrite($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
            fwrite($handle, $createSql . ";\n");

            $data = $pdo->query('SELECT * FROM ' . $quotedTable);
            while ($row = $data->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $columnSql = implode(', ', array_map('backup_safe_mysql_identifier', $columns));
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $values[] = (string)$value;
                    } else {
                        $values[] = $pdo->quote((string)$value);
                    }
                }
                fwrite(
                    $handle,
                    'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' .
                    implode(', ', $values) . ");\n"
                );
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }

    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('MySQL-дамп не создан.');
    }
}

function backup_create_archive(PDO $pdo, string $kind = 'manual'): array
{
    $dir = backup_ensure_dir();
    $kind = $kind === 'automatic' ? 'automatic' : 'manual';
    $stamp = date('Ymd-His');
    $token = bin2hex(random_bytes(4));
    $base = 'uvoria-' . $kind . '-' . $stamp . '-' . $token;
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isMysql = $driver === 'mysql';
    $snapshot = $dir . DIRECTORY_SEPARATOR . '.' . $base . ($isMysql ? '.sql.tmp' : '.sqlite.tmp');
    $archiveDatabasePath = $isMysql ? 'database/urovia-mysql.sql' : 'database/eduplatform.sqlite';

    if (is_file($snapshot)) {
        @unlink($snapshot);
    }

    try {
        if ($isMysql) {
            backup_mysql_dump($pdo, $snapshot);
        } else {
            $pdo->exec("VACUUM INTO '" . backup_quote_sqlite_path($snapshot) . "'");
            if (!is_file($snapshot) || filesize($snapshot) === 0) {
                throw new RuntimeException('Не удалось создать снимок базы данных.');
            }
        }

        $assets = backup_asset_files();
        $info = [
            'product' => 'UROVIA',
            'backup_version' => 2,
            'kind' => $kind,
            'created_at' => date(DATE_ATOM),
            'database_driver' => $isMysql ? 'mysql' : 'sqlite',
            'database' => $archiveDatabasePath,
            'included_storage' => array_values(array_map(
                static fn(array $item): string => (string)$item['archive_path'],
                $assets
            )),
            'retention_days' => 30,
        ];
        $infoJson = json_encode(
            $info,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($infoJson === false) {
            throw new RuntimeException('Не удалось сформировать описание резервной копии.');
        }

        $archivePath = '';
        $format = '';

        if (class_exists('ZipArchive')) {
            $archivePath = $dir . DIRECTORY_SEPARATOR . $base . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Не удалось создать ZIP-архив.');
            }

            if (!$zip->addFile($snapshot, $archiveDatabasePath)) {
                $zip->close();
                throw new RuntimeException('Не удалось добавить базу данных в архив.');
            }
            $zip->addFromString('backup-info.json', $infoJson);

            foreach ($assets as $asset) {
                $zip->addFile((string)$asset['path'], (string)$asset['archive_path']);
            }

            if (!$zip->close()) {
                throw new RuntimeException('Не удалось завершить ZIP-архив.');
            }
            $format = 'zip';
        } elseif (class_exists('PharData')) {
            $tarPath = $dir . DIRECTORY_SEPARATOR . $base . '.tar';
            $archivePath = $tarPath . '.gz';

            if (is_file($tarPath)) @unlink($tarPath);
            if (is_file($archivePath)) @unlink($archivePath);

            $phar = new PharData($tarPath);
            $phar->addFile($snapshot, $archiveDatabasePath);
            $phar->addFromString('backup-info.json', $infoJson);
            foreach ($assets as $asset) {
                $phar->addFile((string)$asset['path'], (string)$asset['archive_path']);
            }
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($tarPath);

            if (!is_file($archivePath)) {
                throw new RuntimeException('Не удалось завершить TAR.GZ-архив.');
            }
            $format = 'tar.gz';
        } else {
            throw new RuntimeException('На сервере недоступны ZIP и PharData для создания архива.');
        }

        if (!is_file($archivePath) || filesize($archivePath) === 0) {
            throw new RuntimeException('Архив резервной копии не создан.');
        }

        $metadata = [
            'file' => basename($archivePath),
            'kind' => $kind,
            'format' => $format,
            'database_driver' => $isMysql ? 'mysql' : 'sqlite',
            'created_at' => date(DATE_ATOM),
            'size_bytes' => (int)filesize($archivePath),
            'sha256' => hash_file('sha256', $archivePath) ?: null,
            'database_size_bytes' => (int)filesize($snapshot),
            'asset_files' => count($assets),
            'asset_bytes' => array_sum(array_column($assets, 'size')),
            'retention_days' => 30,
        ];
        backup_write_metadata($archivePath, $metadata);

        return $metadata;
    } finally {
        if (is_file($snapshot)) {
            @unlink($snapshot);
        }
    }
}

function backup_read_metadata(string $archivePath): array
{
    $metaPath = backup_metadata_path($archivePath);
    if (is_file($metaPath)) {
        $raw = file_get_contents($metaPath);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [
        'file' => basename($archivePath),
        'kind' => str_contains(basename($archivePath), '-automatic-') ? 'automatic' : 'manual',
        'format' => str_ends_with($archivePath, '.tar.gz') ? 'tar.gz' : 'zip',
        'created_at' => date(DATE_ATOM, (int)filemtime($archivePath)),
        'size_bytes' => (int)filesize($archivePath),
        'sha256' => null,
        'asset_files' => null,
        'asset_bytes' => null,
        'retention_days' => 30,
    ];
}

function backup_list(): array
{
    $dir = backup_ensure_dir();
    $paths = array_merge(
        glob($dir . DIRECTORY_SEPARATOR . 'uvoria-*.zip') ?: [],
        glob($dir . DIRECTORY_SEPARATOR . 'uvoria-*.tar.gz') ?: []
    );

    usort($paths, static fn(string $a, string $b): int => (int)filemtime($b) <=> (int)filemtime($a));

    return array_values(array_map(
        static fn(string $path): array => backup_read_metadata($path),
        $paths
    ));
}

function backup_find_archive(string $file): ?string
{
    $file = basename($file);
    if (!preg_match('/^uvoria-(?:automatic|manual)-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.(?:zip|tar\.gz)$/', $file)) {
        return null;
    }

    $path = backup_dir() . DIRECTORY_SEPARATOR . $file;
    return is_file($path) ? $path : null;
}

function backup_delete(string $file): bool
{
    $path = backup_find_archive($file);
    if ($path === null) {
        return false;
    }

    $ok = @unlink($path);
    $meta = backup_metadata_path($path);
    if (is_file($meta)) {
        @unlink($meta);
    }
    return $ok;
}

function backup_cleanup(int $retentionDays = 30): void
{
    $retentionDays = max(7, min(30, $retentionDays));
    $cutoff = time() - ($retentionDays * 86400);

    foreach (backup_list() as $item) {
        $file = (string)($item['file'] ?? '');
        $path = backup_find_archive($file);
        if ($path === null) continue;
        if ((int)filemtime($path) < $cutoff) {
            backup_delete($file);
        }
    }
}

function backup_has_automatic_for_today(): bool
{
    $today = date('Ymd');
    foreach (backup_list() as $item) {
        $file = (string)($item['file'] ?? '');
        if (($item['kind'] ?? '') === 'automatic' && str_contains($file, '-automatic-' . $today . '-')) {
            return true;
        }
    }
    return false;
}

function backup_maybe_run_daily(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $dir = backup_ensure_dir();
        $lockPath = $dir . DIRECTORY_SEPARATOR . '.backup.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) return;

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            backup_cleanup(30);
            if (!backup_has_automatic_for_today()) {
                backup_create_archive($pdo, 'automatic');
                backup_cleanup(30);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        error_log('UVORIA automatic backup failed: ' . $e->getMessage());
    }
}
