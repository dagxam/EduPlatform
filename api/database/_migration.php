<?php
declare(strict_types=1);

function mysql_migration_storage_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
}

function mysql_migration_sqlite_path(): string
{
    return mysql_migration_storage_dir() . DIRECTORY_SEPARATOR . 'eduplatform.sqlite';
}

function mysql_migration_config_path(): string
{
    return mysql_migration_storage_dir() . DIRECTORY_SEPARATOR . 'db-config.php';
}

function mysql_migration_summary_path(): string
{
    return mysql_migration_storage_dir() . DIRECTORY_SEPARATOR . 'mysql-migration.json';
}

function mysql_migration_lock_path(): string
{
    return mysql_migration_storage_dir() . DIRECTORY_SEPARATOR . '.mysql-migration.lock';
}

function mysql_migration_safe_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Некорректное имя таблицы или поля.');
    }
    return chr(96) . $name . chr(96);
}

function mysql_migration_open_sqlite(): PDO
{
    $path = mysql_migration_sqlite_path();
    if (!is_file($path)) {
        throw new RuntimeException('Текущая SQLite-база не найдена.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function mysql_migration_connect(array $config): PDO
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('На сервере не включено расширение PDO_MySQL.');
    }

    $host = trim((string)($config['host'] ?? ''));
    $database = trim((string)($config['database'] ?? ''));
    $user = trim((string)($config['user'] ?? ''));
    $password = (string)($config['password'] ?? '');
    $port = max(1, min(65535, (int)($config['port'] ?? 3306)));

    if ($host === '' || $database === '' || $user === '' || $password === '') {
        throw new RuntimeException('Не заполнены параметры подключения к MySQL.');
    }

    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
        throw new RuntimeException('Некорректный адрес MySQL-сервера.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function mysql_migration_source_tables(PDO $sqlite): array
{
    $stmt = $sqlite->query(
        "SELECT name
         FROM sqlite_master
         WHERE type = 'table'
           AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    );
    return array_values(array_map(
        static fn(array $row): string => (string)$row['name'],
        $stmt->fetchAll()
    ));
}

function mysql_migration_target_tables(PDO $mysql): array
{
    $rows = $mysql->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    return array_values(array_map(
        static fn(array $row): string => (string)($row[0] ?? ''),
        $rows
    ));
}

function mysql_migration_source_columns(PDO $sqlite, string $table): array
{
    $quoted = mysql_migration_safe_identifier($table);
    $rows = $sqlite->query('PRAGMA table_info(' . $quoted . ')')->fetchAll();
    return array_values(array_map(
        static fn(array $row): string => (string)$row['name'],
        $rows
    ));
}

function mysql_migration_target_columns(PDO $mysql, string $table): array
{
    $rows = $mysql->query('SHOW COLUMNS FROM ' . mysql_migration_safe_identifier($table))->fetchAll();
    return array_values(array_map(
        static fn(array $row): string => (string)$row['Field'],
        $rows
    ));
}

function mysql_migration_source_count(PDO $sqlite, string $table): int
{
    return (int)$sqlite
        ->query('SELECT COUNT(*) FROM ' . mysql_migration_safe_identifier($table))
        ->fetchColumn();
}

function mysql_migration_target_count(PDO $mysql, string $table): int
{
    return (int)$mysql
        ->query('SELECT COUNT(*) FROM ' . mysql_migration_safe_identifier($table))
        ->fetchColumn();
}

function mysql_migration_copy_table(PDO $sqlite, PDO $mysql, string $table, array $columns): int
{
    if (!$columns) return 0;

    $quotedTable = mysql_migration_safe_identifier($table);
    $quotedColumns = array_map('mysql_migration_safe_identifier', $columns);
    $placeholders = array_fill(0, count($columns), '?');
    $insert = $mysql->prepare(
        'INSERT INTO ' . $quotedTable .
        ' (' . implode(', ', $quotedColumns) . ')' .
        ' VALUES (' . implode(', ', $placeholders) . ')'
    );

    $source = $sqlite->query(
        'SELECT ' . implode(', ', $quotedColumns) .
        ' FROM ' . $quotedTable
    );

    $count = 0;
    while ($row = $source->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        $insert->execute($values);
        $count++;
    }
    return $count;
}

function mysql_migration_validate_source(PDO $sqlite): void
{
    $foreignKeyErrors = $sqlite->query('PRAGMA foreign_key_check')->fetchAll();
    if ($foreignKeyErrors) {
        throw new RuntimeException(
            'В текущей SQLite-базе обнаружены нарушения внешних связей. Миграция остановлена.'
        );
    }

    $integrity = (string)$sqlite->query('PRAGMA integrity_check')->fetchColumn();
    if (strtolower(trim($integrity)) !== 'ok') {
        throw new RuntimeException('SQLite integrity_check не пройден: ' . $integrity);
    }
}

function mysql_migration_validate_target_fks(PDO $mysql): void
{
    $stmt = $mysql->query(
        "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );

    foreach ($stmt->fetchAll() as $fk) {
        $childTable = mysql_migration_safe_identifier((string)$fk['TABLE_NAME']);
        $childColumn = mysql_migration_safe_identifier((string)$fk['COLUMN_NAME']);
        $parentTable = mysql_migration_safe_identifier((string)$fk['REFERENCED_TABLE_NAME']);
        $parentColumn = mysql_migration_safe_identifier((string)$fk['REFERENCED_COLUMN_NAME']);

        $sql =
            'SELECT COUNT(*) FROM ' . $childTable . ' c ' .
            'LEFT JOIN ' . $parentTable . ' p ON c.' . $childColumn . ' = p.' . $parentColumn . ' ' .
            'WHERE c.' . $childColumn . ' IS NOT NULL AND p.' . $parentColumn . ' IS NULL';
        $orphans = (int)$mysql->query($sql)->fetchColumn();
        if ($orphans > 0) {
            throw new RuntimeException(
                'После переноса обнаружены нарушенные связи: ' .
                (string)$fk['TABLE_NAME'] . '.' . (string)$fk['COLUMN_NAME']
            );
        }
    }
}

function mysql_migration_write_config(array $config): void
{
    $path = mysql_migration_config_path();
    $tmp = $path . '.tmp';

    $payload = "<?php\nreturn " . var_export([
        'driver' => 'mysql',
        'host' => (string)$config['host'],
        'port' => (int)$config['port'],
        'database' => (string)$config['database'],
        'user' => (string)$config['user'],
        'password' => (string)$config['password'],
    ], true) . ";\n";

    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить закрытую конфигурацию MySQL.');
    }
    @chmod($tmp, 0600);

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось включить MySQL-конфигурацию.');
    }
    @chmod($path, 0600);
}

function mysql_migration_run(array $config): array
{
    $storage = mysql_migration_storage_dir();
    if (!is_dir($storage)) {
        throw new RuntimeException('Каталог storage не найден.');
    }
    if (is_file(mysql_migration_config_path())) {
        throw new RuntimeException('MySQL уже включён. Повторная миграция заблокирована.');
    }

    $lock = fopen(mysql_migration_lock_path(), 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Миграция уже выполняется в другом запросе.');
    }

    $sqlite = null;
    $mysql = null;

    try {
        $sqlite = mysql_migration_open_sqlite();
        $sqlite->exec('PRAGMA wal_checkpoint(FULL)');
        mysql_migration_validate_source($sqlite);

        // Full pre-migration backup. The original SQLite file itself is never deleted.
        $backup = backup_create_archive($sqlite, 'manual');

        $mysql = mysql_migration_connect($config);
        mysql_apply_schema($mysql);

        $sourceTables = mysql_migration_source_tables($sqlite);
        $targetTables = array_fill_keys(mysql_migration_target_tables($mysql), true);
        $tableSummary = [];

        foreach ($sourceTables as $table) {
            if (!isset($targetTables[$table])) {
                throw new RuntimeException(
                    'В MySQL-схеме отсутствует таблица «' . $table . '». Переключение отменено.'
                );
            }

            $sourceColumns = mysql_migration_source_columns($sqlite, $table);
            $targetColumns = array_fill_keys(mysql_migration_target_columns($mysql, $table), true);
            foreach ($sourceColumns as $column) {
                if (!isset($targetColumns[$column])) {
                    throw new RuntimeException(
                        'В MySQL-схеме отсутствует поле «' . $table . '.' . $column . '». Переключение отменено.'
                    );
                }
            }

            $sourceCount = mysql_migration_source_count($sqlite, $table);
            $targetCount = mysql_migration_target_count($mysql, $table);
            if ($targetCount !== 0) {
                throw new RuntimeException(
                    'MySQL-база уже содержит данные в таблице «' . $table . '». ' .
                    'Для безопасной миграции нужна пустая база.'
                );
            }

            $tableSummary[$table] = [
                'source' => $sourceCount,
                'target' => 0,
            ];
        }

        $mysql->exec('SET FOREIGN_KEY_CHECKS=0');
        $mysql->beginTransaction();
        try {
            foreach ($sourceTables as $table) {
                $columns = mysql_migration_source_columns($sqlite, $table);
                $copied = mysql_migration_copy_table($sqlite, $mysql, $table, $columns);
                $tableSummary[$table]['target'] = $copied;

                if ($copied !== $tableSummary[$table]['source']) {
                    throw new RuntimeException(
                        'Количество строк не совпало для таблицы «' . $table . '».'
                    );
                }
            }
            $mysql->commit();
        } catch (Throwable $e) {
            if ($mysql->inTransaction()) $mysql->rollBack();
            throw $e;
        } finally {
            $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        foreach ($sourceTables as $table) {
            $targetCount = mysql_migration_target_count($mysql, $table);
            if ($targetCount !== $tableSummary[$table]['source']) {
                throw new RuntimeException(
                    'Контрольная проверка строк не пройдена для таблицы «' . $table . '».'
                );
            }
            $tableSummary[$table]['target'] = $targetCount;

            $columns = mysql_migration_source_columns($sqlite, $table);
            if (in_array('id', $columns, true)) {
                $maxId = (int)$mysql
                    ->query('SELECT COALESCE(MAX(id), 0) FROM ' . mysql_migration_safe_identifier($table))
                    ->fetchColumn();
                if ($maxId > 0) {
                    $mysql->exec(
                        'ALTER TABLE ' . mysql_migration_safe_identifier($table) .
                        ' AUTO_INCREMENT = ' . ($maxId + 1)
                    );
                }
            }
        }

        mysql_migration_validate_target_fks($mysql);

        $totalSource = array_sum(array_column($tableSummary, 'source'));
        $totalTarget = array_sum(array_column($tableSummary, 'target'));
        if ($totalSource !== $totalTarget) {
            throw new RuntimeException('Общее количество записей после миграции не совпало.');
        }

        $summary = [
            'completed_at' => date(DATE_ATOM),
            'driver' => 'mysql',
            'host' => (string)$config['host'],
            'database' => (string)$config['database'],
            'tables' => $tableSummary,
            'total_rows' => $totalTarget,
            'sqlite_backup' => [
                'file' => $backup['file'] ?? null,
                'sha256' => $backup['sha256'] ?? null,
                'size_bytes' => $backup['size_bytes'] ?? null,
            ],
            'sqlite_source_preserved' => true,
        ];

        $summaryJson = json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($summaryJson === false || file_put_contents(mysql_migration_summary_path(), $summaryJson, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить отчёт миграции.');
        }

        // Switch only after every copy and integrity check has succeeded.
        mysql_migration_write_config($config);

        return $summary;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
