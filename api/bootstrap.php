<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('urovia_session');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function enforce_same_origin(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        json_response(['ok' => false, 'error' => 'Запрос отклонён системой безопасности.'], 403);
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $requestHost = preg_replace('/:\\d+$/', '', $host) ?? $host;
        if ($originHost === '' || $originHost !== $requestHost) {
            json_response(['ok' => false, 'error' => 'Запрос отклонён системой безопасности.'], 403);
        }
        return;
    }

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $refererHost = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));
        $requestHost = preg_replace('/:\\d+$/', '', $host) ?? $host;
        if ($refererHost === '' || $refererHost !== $requestHost) {
            json_response(['ok' => false, 'error' => 'Запрос отклонён системой безопасности.'], 403);
        }
    }
}

enforce_same_origin();

require_once __DIR__ . '/backups/_helpers.php';

function privacy_policy_version(): string
{
    return '2026-10-01';
}

function privacy_consent_version(): string
{
    return '2026-10-01';
}

function privacy_truthy(mixed $value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (int)$value === 1;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function require_privacy_confirmation(
    array $data,
    string $field,
    string $message = 'Необходимо подтвердить законное основание обработки персональных данных.'
): void {
    if (!privacy_truthy($data[$field] ?? null)) {
        json_response([
            'ok' => false,
            'error' => $message,
            'code' => 'PRIVACY_CONFIRMATION_REQUIRED',
        ], 422);
    }
}

function privacy_audit_metadata(string $basis): array
{
    static $hashes = null;
    if ($hashes === null) {
        $root = dirname(__DIR__);
        $policyPath = $root . DIRECTORY_SEPARATOR . 'privacy-policy.html';
        $consentPath = $root . DIRECTORY_SEPARATOR . 'personal-data-consent.html';
        $hashes = [
            'privacy_policy_sha256' => is_file($policyPath) ? (string)hash_file('sha256', $policyPath) : null,
            'privacy_consent_sha256' => is_file($consentPath) ? (string)hash_file('sha256', $consentPath) : null,
        ];
    }

    return [
        'privacy_basis' => $basis,
        'privacy_policy_version' => privacy_policy_version(),
        'privacy_consent_version' => privacy_consent_version(),
        'privacy_policy_sha256' => $hashes['privacy_policy_sha256'],
        'privacy_consent_sha256' => $hashes['privacy_consent_sha256'],
        'privacy_source_path' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
    ];
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $error): void {
        error_log(
            '[UROVIA] Unhandled exception: ' . get_class($error) . ': ' .
            $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine()
        );

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Внутренняя ошибка сервера. Повторите попытку.',
            'code' => 'INTERNAL_SERVER_ERROR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        error_log(
            '[UROVIA] Fatal error: ' . (string)($error['message'] ?? '') .
            ' in ' . (string)($error['file'] ?? '') . ':' . (string)($error['line'] ?? '')
        );

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Внутренняя ошибка сервера. Повторите попытку.',
            'code' => 'FATAL_SERVER_ERROR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

function maintenance_flag_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'maintenance.flag';
}

function enforce_maintenance_mode(): void
{
    if (PHP_SAPI === 'cli' || !is_file(maintenance_flag_path())) {
        return;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (
        str_ends_with($script, '/api/database/migrate.php') ||
        str_ends_with($script, '/api/database/migrate-machine.php')
    ) {
        return;
    }

    json_response([
        'ok' => false,
        'error' => 'UROVIA выполняет техническое обслуживание базы данных. Повторите запрос через несколько минут.',
        'code' => 'MAINTENANCE_MODE',
    ], 503);
}

enforce_maintenance_mode();

function read_json_body(int $maxBytes = 8388608): array
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        json_response([
            'ok' => false,
            'error' => 'Запрос слишком большой.',
            'code' => 'REQUEST_TOO_LARGE',
        ], 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        json_response([
            'ok' => false,
            'error' => 'Запрос слишком большой.',
            'code' => 'REQUEST_TOO_LARGE',
        ], 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Некорректный JSON.'], 400);
    }

    return $data;
}

function database_config_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'db-config.php';
}

function database_runtime_config(): ?array
{
    $path = database_config_path();
    if (!is_file($path)) {
        return null;
    }

    $config = require $path;
    if (!is_array($config) || ($config['driver'] ?? '') !== 'mysql') {
        return null;
    }

    return $config;
}

function db_driver(PDO $pdo): string
{
    return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
}

function db_is_mysql(PDO $pdo): bool
{
    return db_driver($pdo) === 'mysql';
}

function db_insert_ignore_prefix(PDO $pdo): string
{
    return db_is_mysql($pdo) ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
}

function db_upsert_clause(PDO $pdo, array $conflictColumns, array $updateColumns): string
{
    $safe = static function (string $name): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Некорректное имя поля базы данных.');
        }
        return $name;
    };

    $conflictColumns = array_values(array_map($safe, $conflictColumns));
    $updateColumns = array_values(array_map($safe, $updateColumns));

    if (db_is_mysql($pdo)) {
        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            static fn(string $column): string => $column . ' = VALUES(' . $column . ')',
            $updateColumns
        ));
    }

    return ' ON CONFLICT(' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' . implode(', ', array_map(
        static fn(string $column): string => $column . ' = excluded.' . $column,
        $updateColumns
    ));
}

function mysql_schema_version(): string
{
    return '2';
}

function mysql_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

function mysql_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $safe = static function (string $name): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Некорректное имя поля базы данных.');
        }
        return $name;
    };

    $table = $safe($table);
    $column = $safe($column);
    if (!preg_match('/^[a-zA-Z0-9_ (),]+$/', $definition)) {
        throw new InvalidArgumentException('Некорректное определение поля базы данных.');
    }

    if (!mysql_column_exists($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function mysql_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);
    return (bool)$stmt->fetchColumn();
}

function mysql_add_index_if_missing(PDO $pdo, string $table, string $index, string $columns): void
{
    $safe = static function (string $name): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Некорректное имя индекса базы данных.');
        }
        return $name;
    };

    $table = $safe($table);
    $index = $safe($index);
    if (!preg_match('/^[a-zA-Z0-9_, ()]+$/', $columns)) {
        throw new InvalidArgumentException('Некорректный список полей индекса.');
    }

    if (!mysql_index_exists($pdo, $table, $index)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' (' . $columns . ')');
    }
}

function mysql_apply_runtime_migrations(PDO $pdo): void
{
    mysql_add_column_if_missing($pdo, 'schools', 'grade_5_min', 'INT NOT NULL DEFAULT 85');
    mysql_add_column_if_missing($pdo, 'schools', 'grade_4_min', 'INT NOT NULL DEFAULT 71');
    mysql_add_column_if_missing($pdo, 'schools', 'grade_3_min', 'INT NOT NULL DEFAULT 50');
    mysql_add_column_if_missing($pdo, 'questions', 'is_active', 'TINYINT NOT NULL DEFAULT 1');
    mysql_add_column_if_missing($pdo, 'questions', 'revision_of_id', 'BIGINT UNSIGNED NULL');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assignment_students (
            assignment_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            time_limit_minutes INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (assignment_id, student_id),
            KEY idx_assignment_students_student (student_id, assignment_id),
            CONSTRAINT fk_assignment_students_assignment
                FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_assignment_students_student
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    mysql_add_index_if_missing(
        $pdo,
        'questions',
        'idx_questions_assignment_active_position',
        'assignment_id, is_active, position, id'
    );

    mysql_add_index_if_missing(
        $pdo,
        'attempts',
        'idx_attempts_assignment_student_status_id',
        'assignment_id, student_id, status, id'
    );
    mysql_add_index_if_missing(
        $pdo,
        'audit_log',
        'idx_audit_log_entity_event',
        'entity_type, entity_id, event_type, id'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS library_reactions (
            library_item_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            liked TINYINT NOT NULL DEFAULT 0,
            rating TINYINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (library_item_id, user_id),
            KEY idx_library_reactions_item (library_item_id, liked, rating),
            CONSTRAINT fk_library_reactions_item
                FOREIGN KEY (library_item_id) REFERENCES library_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_library_reactions_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_code VARCHAR(32) NOT NULL DEFAULT 'basic',
            status VARCHAR(32) NOT NULL DEFAULT 'inactive',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            trial_ends_at DATETIME NULL,
            auto_renew TINYINT NOT NULL DEFAULT 0,
            billing_period VARCHAR(16) NULL,
            payment_provider VARCHAR(64) NULL,
            provider_customer_id VARCHAR(191) NULL,
            provider_payment_method_id VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_subscriptions_user (user_id),
            CONSTRAINT fk_user_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscription_usage_monthly (
            user_id BIGINT UNSIGNED NOT NULL,
            period_key CHAR(7) NOT NULL,
            ai_generations INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, period_key),
            CONSTRAINT fk_subscription_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payment_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_code VARCHAR(32) NOT NULL,
            billing_period VARCHAR(16) NOT NULL,
            amount_minor BIGINT NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'RUB',
            provider VARCHAR(64) NOT NULL,
            provider_payment_id VARCHAR(191) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'created',
            idempotence_key CHAR(64) NOT NULL,
            checkout_url TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_payment_orders_idempotence (idempotence_key),
            KEY idx_payment_orders_user (user_id, created_at),
            CONSTRAINT fk_payment_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );


    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscription_admin_grants (
            user_id BIGINT UNSIGNED NOT NULL,
            plan_code VARCHAR(32) NOT NULL,
            starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ends_at DATETIME NULL,
            note VARCHAR(500) NULL,
            assigned_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            KEY idx_subscription_admin_grants_end (ends_at),
            CONSTRAINT fk_subscription_admin_grants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_subscription_admin_grants_admin FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );}

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_legal_profile (
            id TINYINT UNSIGNED NOT NULL,
            entity_type VARCHAR(32) NOT NULL DEFAULT 'self_employed',
            legal_name VARCHAR(255) NULL,
            inn VARCHAR(32) NULL,
            ogrn VARCHAR(32) NULL,
            legal_address VARCHAR(500) NULL,
            postal_address VARCHAR(500) NULL,
            phone VARCHAR(64) NULL,
            email VARCHAR(191) NULL,
            support_email VARCHAR(191) NULL,
            tax_note VARCHAR(500) NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_site_legal_profile_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "INSERT INTO site_legal_profile (id, entity_type, email, support_email)
         VALUES (1, 'self_employed', 'info@urovia.ru', 'info@urovia.ru')
         ON DUPLICATE KEY UPDATE id = VALUES(id)"
    );

function automatic_backup_allowed_for_request(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ([
        '/api/teacher/',
        '/api/admin/',
        '/api/school/',
        '/api/assignments/',
        '/api/results/',
        '/api/classes/',
        '/api/subjects/',
        '/api/backups/',
    ] as $prefix) {
        if (str_contains($script, $prefix)) {
            return true;
        }
    }
    return false;
}

function maybe_run_automatic_backup(PDO $pdo): void
{
    if (automatic_backup_allowed_for_request()) {
        backup_maybe_run_daily($pdo);
    }
}

function mysql_apply_schema(PDO $pdo): void
{
    static $applied = false;
    if ($applied) return;

    try {
        $stmt = $pdo->prepare(
            "SELECT meta_value FROM urovia_meta WHERE meta_key = 'schema_version' LIMIT 1"
        );
        $stmt->execute();
        if ((string)($stmt->fetchColumn() ?: '') === mysql_schema_version()) {
            // Keep additive hotfix migrations running even when the base schema version
            // is already current. This lets existing MySQL installations receive
            // newly introduced tables/indexes without a destructive full reimport.
            mysql_apply_runtime_migrations($pdo);
            $applied = true;
            return;
        }
    } catch (Throwable) {
        // First MySQL boot: metadata table does not exist yet.
    }

    $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.mysql.sql';
    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        throw new RuntimeException('Не найден файл MySQL-схемы базы данных.');
    }

    $schema = preg_replace('/^\s*--.*$/m', '', $schema) ?? $schema;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        $pdo->exec($statement);
    }

    mysql_apply_runtime_migrations($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO urovia_meta (meta_key, meta_value, updated_at)
         VALUES ('schema_version', :version, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute(['version' => mysql_schema_version()]);

    $applied = true;
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        json_response(['ok' => false, 'error' => 'Не удалось создать папку storage.'], 500);
    }

    $runtime = database_runtime_config();
    if ($runtime !== null) {
        if (!extension_loaded('pdo_mysql')) {
            json_response([
                'ok' => false,
                'error' => 'На сервере не включено расширение PDO_MySQL.',
                'code' => 'MYSQL_UNAVAILABLE',
            ], 500);
        }

        $host = trim((string)($runtime['host'] ?? ''));
        $database = trim((string)($runtime['database'] ?? ''));
        $user = (string)($runtime['user'] ?? '');
        $password = (string)($runtime['password'] ?? '');
        $port = max(1, (int)($runtime['port'] ?? 3306));

        if ($host === '' || $database === '' || $user === '') {
            json_response([
                'ok' => false,
                'error' => 'Конфигурация MySQL неполная.',
                'code' => 'MYSQL_CONFIG_INVALID',
            ], 500);
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (Throwable $e) {
            error_log('[UROVIA] Could not force MySQL UTC timezone: ' . $e->getMessage());
        }

        mysql_apply_schema($pdo);
        maybe_run_automatic_backup($pdo);
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        json_response([
            'ok' => false,
            'error' => 'На сервере не включено расширение PDO_SQLite.',
            'code' => 'SQLITE_UNAVAILABLE',
        ], 500);
    }

    $databaseFile = $storageDir . DIRECTORY_SEPARATOR . 'eduplatform.sqlite';
    $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // A whole class can autosave and submit at nearly the same moment.
    // Wait for short-lived write locks instead of failing immediately.
    $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    // WAL allows readers and a writer to coexist and is substantially more
    // suitable for classroom concurrency than SQLite's rollback journal.
    try {
        $journalMode = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== 'wal' && $databaseFile !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } catch (Throwable $e) {
        error_log('[UROVIA] SQLite WAL setup skipped: ' . $e->getMessage());
    }

    $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        json_response(['ok' => false, 'error' => 'Не найден файл схемы базы данных.'], 500);
    }

    $pdo->exec($schema);
    apply_schema_migrations($pdo);
    maybe_run_automatic_backup($pdo);
    return $pdo;
}

function sqlite_column_exists(PDO $pdo, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    if ($table === '') {
        return false;
    }

    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($rows as $row) {
        if (($row['name'] ?? null) === $column) {
            return true;
        }
    }
    return false;
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (sqlite_column_exists($pdo, $table, $column)) {
        return;
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
    if ($safeTable === '' || $safeColumn === '') {
        throw new RuntimeException('Некорректная миграция базы данных.');
    }

    $pdo->exec('ALTER TABLE ' . $safeTable . ' ADD COLUMN ' . $safeColumn . ' ' . $definition);
}

function apply_schema_migrations(PDO $pdo): void
{
    add_column_if_missing($pdo, 'users', 'is_platform_admin', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'session_version', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'middle_name', 'TEXT');
    add_column_if_missing($pdo, 'users', 'phone', 'TEXT');
    add_column_if_missing($pdo, 'users', 'avatar_name', 'TEXT');
    add_column_if_missing($pdo, 'users', 'login_name', 'TEXT COLLATE NOCASE');
    add_column_if_missing($pdo, 'users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'credentials_sent_at', 'TEXT');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_login_name
        ON users(login_name)
        WHERE login_name IS NOT NULL");
    add_column_if_missing($pdo, 'school_users', 'can_teach', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'schools', 'theme_color', "TEXT NOT NULL DEFAULT '#1d68f0'");
    add_column_if_missing($pdo, 'schools', 'assignment_review_required', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'schools', 'grade_5_min', 'INTEGER NOT NULL DEFAULT 85');
    add_column_if_missing($pdo, 'schools', 'grade_4_min', 'INTEGER NOT NULL DEFAULT 71');
    add_column_if_missing($pdo, 'schools', 'grade_3_min', 'INTEGER NOT NULL DEFAULT 50');
    $pdo->exec("UPDATE school_users SET can_teach = 1 WHERE role = 'teacher' AND can_teach = 0");
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_platform_admin = 1")->fetchColumn() === 0) {
        $pdo->exec("UPDATE users SET is_platform_admin = 1 WHERE id = (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1)");
    }
    add_column_if_missing($pdo, 'class_access', 'registration_expires_at', 'TEXT');
    add_column_if_missing($pdo, 'classes', 'school_id', 'INTEGER');
    add_column_if_missing($pdo, 'classes', 'display_name', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'school_id', 'INTEGER');
    add_column_if_missing($pdo, 'assignments', 'focus_policy', "TEXT NOT NULL DEFAULT 'allow'");
    add_column_if_missing($pdo, 'assignments', 'variant_count', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'assignments', 'shuffle_questions', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'assignments', 'shuffle_options', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'assignments', 'shuffle_structured', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'assignments', 'workflow_status', "TEXT NOT NULL DEFAULT 'draft'");
    add_column_if_missing($pdo, 'assignments', 'review_submitted_at', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'reviewed_at', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'reviewed_by', 'INTEGER');
    add_column_if_missing($pdo, 'assignments', 'review_comment', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'completed_at', 'TEXT');
    add_column_if_missing($pdo, 'assignment_classes', 'time_limit_minutes', 'INTEGER');
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignment_students (
        assignment_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        time_limit_minutes INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (assignment_id, student_id),
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignment_students_student
        ON assignment_students(student_id, assignment_id)");

    $pdo->exec("UPDATE assignments
        SET workflow_status = 'assigned'
        WHERE status = 'published' AND workflow_status = 'draft'");
    $pdo->exec("UPDATE assignments
        SET workflow_status = 'completed', completed_at = COALESCE(completed_at, updated_at)
        WHERE status = 'closed' AND workflow_status <> 'completed'");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignments_workflow_status
        ON assignments(school_id, workflow_status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attempts_assignment_student_status_id
        ON attempts(assignment_id, student_id, status, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_entity_event
        ON audit_log(entity_type, entity_id, event_type, id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_material_transfers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_school_id INTEGER NOT NULL,
        target_school_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        sender_user_id INTEGER,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'rejected')),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at TEXT,
        resolved_by INTEGER,
        FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_material_transfer_assignments (
        transfer_id INTEGER NOT NULL,
        assignment_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        title_snapshot TEXT NOT NULL,
        type_snapshot TEXT,
        questions_count_snapshot INTEGER NOT NULL DEFAULT 0,
        source_format_snapshot TEXT,
        PRIMARY KEY (transfer_id, assignment_id),
        FOREIGN KEY (transfer_id) REFERENCES school_material_transfers(id) ON DELETE CASCADE,
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_material_transfers_target
        ON school_material_transfers(target_school_id, status, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_material_transfers_source
        ON school_material_transfers(source_school_id, created_at)");
    add_column_if_missing($pdo, 'questions', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'questions', 'revision_of_id', 'INTEGER');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_questions_assignment_active_position
        ON questions(assignment_id, is_active, position, id)");

    add_column_if_missing($pdo, 'attempts', 'last_seen_at', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'termination_reason', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'focus_violations', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'attempts', 'attempt_session_hash', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'variant_index', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'attempts', 'variant_label', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'question_order_json', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'option_order_json', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'structured_order_json', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'time_limit_snapshot', 'INTEGER');
    add_column_if_missing($pdo, 'attempts', 'manual_score', 'REAL');
    add_column_if_missing($pdo, 'attempts', 'manual_percent', 'REAL');
    add_column_if_missing($pdo, 'attempts', 'manual_grade', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'manual_comment', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'manual_updated_at', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'manual_updated_by', 'INTEGER');
    add_column_if_missing($pdo, 'attempts', 'published_score', 'REAL');
    add_column_if_missing($pdo, 'attempts', 'published_percent', 'REAL');
    add_column_if_missing($pdo, 'attempts', 'published_grade', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'published_comment', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'result_published_at', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'result_published_by', 'INTEGER');
    add_column_if_missing($pdo, 'attempts', 'result_revision', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attempts_student_status_submitted
        ON attempts(student_id, status, submitted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attempts_assignment_status_submitted
        ON attempts(assignment_id, status, submitted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignments_school_status_updated
        ON assignments(school_id, status, updated_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignment_classes_class
        ON assignment_classes(class_id, assignment_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attempt_result_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        attempt_id INTEGER NOT NULL,
        revision INTEGER NOT NULL,
        score REAL NOT NULL,
        max_score REAL NOT NULL,
        percent REAL NOT NULL,
        grade TEXT NOT NULL,
        comment TEXT,
        published_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE (attempt_id, revision)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attempt_result_revisions_attempt
        ON attempt_result_revisions(attempt_id, revision)");
    add_column_if_missing($pdo, 'answers', 'updated_at', 'TEXT');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_answers_attempt_updated
        ON answers(attempt_id, updated_at)");
    add_column_if_missing($pdo, 'questions', 'interaction_type', 'TEXT');
    add_column_if_missing($pdo, 'questions', 'settings_json', 'TEXT');
    add_column_if_missing($pdo, 'assignment_imports', 'parsed_question_count', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'assignment_imports', 'parser_message', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'source_school_id', 'INTEGER');
    add_column_if_missing($pdo, 'assignments', 'source_assignment_id', 'INTEGER');
    add_column_if_missing($pdo, 'assignments', 'shared_by_user_id', 'INTEGER');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_assignments_shared_origin
        ON assignments(school_id, source_school_id, source_assignment_id)
        WHERE source_assignment_id IS NOT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL,
        used_at INTEGER,
        created_at INTEGER NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user
        ON password_reset_tokens(user_id, expires_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_school_id INTEGER NOT NULL,
        source_assignment_id INTEGER NOT NULL,
        subject_id INTEGER,
        submitted_by INTEGER,
        approved_by INTEGER,
        status TEXT NOT NULL DEFAULT 'pending',
        title_snapshot TEXT NOT NULL,
        description_snapshot TEXT,
        questions_count_snapshot INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_at TEXT,
        FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (source_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE (source_school_id, source_assignment_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        library_item_id INTEGER NOT NULL,
        target_school_id INTEGER NOT NULL,
        target_assignment_id INTEGER NOT NULL,
        imported_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_item_id) REFERENCES library_items(id) ON DELETE CASCADE,
        FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
        FOREIGN KEY (target_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
        FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE (library_item_id, target_school_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_library_items_status
        ON library_items(status, published_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_library_items_school
        ON library_items(source_school_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_library_imports_target
        ON library_imports(target_school_id, created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_reactions (
        library_item_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        liked INTEGER NOT NULL DEFAULT 0,
        rating INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (library_item_id, user_id),
        FOREIGN KEY (library_item_id) REFERENCES library_items(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_library_reactions_item
        ON library_reactions(library_item_id, liked, rating)");
}

function audit_event(string $eventType, ?string $entityType = null, ?int $entityId = null, array $metadata = [], ?int $schoolId = null, ?int $userId = null): void
{
    try {
        $stmt = app_db()->prepare(
            'INSERT INTO audit_log (school_id, user_id, event_type, entity_type, entity_id, metadata_json)
             VALUES (:school_id, :user_id, :event_type, :entity_type, :entity_id, :metadata_json)'
        );
        $stmt->execute([
            'school_id' => $schoolId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable) {
        // Audit logging must not take the main application down.
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = app_db()->prepare(
        'SELECT id, first_name, last_name, middle_name, phone, avatar_name, email, login_name, role, is_platform_admin,
                session_version, class_name, must_change_password, credentials_sent_at, is_active
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(int) $user['is_active']) {
        $_SESSION = [];
        return null;
    }

    $version = (int)($user['session_version'] ?? 0);
    if (isset($_SESSION['session_version']) && (int)$_SESSION['session_version'] !== $version) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['session_version'] = $version;

    unset($user['is_active'], $user['session_version']);
    return $user;
}

function school_branding(int $schoolId): array
{
    if ($schoolId < 1) {
        return ['theme_color' => '#1d68f0'];
    }

    $stmt = app_db()->prepare(
        'SELECT theme_color
         FROM schools
         WHERE id = :id AND status = "active"
         LIMIT 1'
    );
    $stmt->execute(['id' => $schoolId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['theme_color' => '#1d68f0'];
    }

    $color = strtoupper(trim((string)($row['theme_color'] ?? '')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
        $color = '#1D68F0';
    }

    return ['theme_color' => strtolower($color)];
}

function current_school_id(): ?int
{
    $value = (int)($_SESSION['active_school_id'] ?? 0);
    return $value > 0 ? $value : null;
}

function is_platform_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' && (int)($user['is_platform_admin'] ?? 0) === 1;
}

function school_membership_role(int $userId, int $schoolId): ?string
{
    $stmt = app_db()->prepare(
        'SELECT role FROM school_users
         WHERE school_id = :school_id AND user_id = :user_id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $userId]);
    $role = $stmt->fetchColumn();
    return $role !== false ? (string)$role : null;
}

function school_can_teach(int $userId, int $schoolId): bool
{
    $stmt = app_db()->prepare(
        'SELECT can_teach
         FROM school_users
         WHERE school_id = :school_id
           AND user_id = :user_id
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => $userId,
    ]);
    return (int)($stmt->fetchColumn() ?: 0) === 1;
}

function can_teach_school(array $user, int $schoolId): bool
{
    if ($schoolId < 1 || ($user['role'] ?? '') === 'student') {
        return false;
    }

    return school_can_teach((int)$user['id'], $schoolId);
}

function can_access_school(array $user, int $schoolId): bool
{
    if ($schoolId < 1) {
        return false;
    }

    if (is_platform_admin($user)) {
        $stmt = app_db()->prepare('SELECT 1 FROM schools WHERE id = :id AND status = "active"');
        $stmt->execute(['id' => $schoolId]);
        return (bool)$stmt->fetchColumn();
    }

    return school_membership_role((int)$user['id'], $schoolId) !== null;
}

function can_manage_school(array $user, int $schoolId): bool
{
    if (is_platform_admin($user)) {
        return can_access_school($user, $schoolId);
    }

    if (($user['role'] ?? '') !== 'admin') {
        return false;
    }

    return in_array(school_membership_role((int)$user['id'], $schoolId), ['owner', 'school_admin'], true);
}

function ensure_staff_school_context(array $user): ?int
{
    if (($user['role'] ?? '') === 'student' || is_platform_admin($user)) {
        return current_school_id();
    }

    $current = current_school_id();
    if ($current !== null && can_access_school($user, $current)) {
        return $current;
    }

    $stmt = app_db()->prepare(
        'SELECT s.id
         FROM school_users su
         JOIN schools s ON s.id = su.school_id
         WHERE su.user_id = :user_id
           AND su.is_active = 1
           AND s.status = "active"
         ORDER BY su.created_at ASC, s.id ASC
         LIMIT 1'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $schoolId = (int)($stmt->fetchColumn() ?: 0);

    if ($schoolId > 0) {
        $_SESSION['active_school_id'] = $schoolId;
        return $schoolId;
    }

    unset($_SESSION['active_school_id']);
    return null;
}

function require_active_school(array $user, bool $manage = false): int
{
    $schoolId = current_school_id();
    if ($schoolId === null) {
        json_response(['ok' => false, 'error' => 'Сначала выберите школу.'], 409);
    }

    $allowed = $manage ? can_manage_school($user, $schoolId) : can_access_school($user, $schoolId);
    if (!$allowed) {
        unset($_SESSION['active_school_id']);
        json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
    }

    return $schoolId;
}

function require_user(?array $roles = null): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Требуется вход в систему.'], 401);
    }

    if ($roles !== null && !in_array($user['role'], $roles, true)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }

    return $user;
}

function throttle_key(string $scope, string $identifier = ''): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Do not include User-Agent: an attacker can rotate it on every request
    // and otherwise bypass the brute-force counter.
    return hash('sha256', $scope . '|' . $identifier . '|' . $ip);
}

function throttle_check(string $scope, string $identifier = '', int $maxFailures = 6, int $windowSeconds = 900): void
{
    $pdo = app_db();
    $key = throttle_key($scope, $identifier);
    $now = time();

    $stmt = $pdo->prepare('SELECT failures, window_started, locked_until FROM auth_throttle WHERE key_hash = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $lockedUntil = (int)($row['locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        json_response([
            'ok' => false,
            'error' => 'Слишком много попыток входа. Попробуйте позже.',
            'code' => 'RATE_LIMITED',
            'retry_after' => $lockedUntil - $now,
        ], 429);
    }

    if (($now - (int)$row['window_started']) > $windowSeconds) {
        $pdo->prepare('DELETE FROM auth_throttle WHERE key_hash = :key')->execute(['key' => $key]);
    }
}

function throttle_failure(string $scope, string $identifier = '', int $maxFailures = 6, int $windowSeconds = 900, int $lockSeconds = 900): void
{
    $pdo = app_db();
    $key = throttle_key($scope, $identifier);
    $now = time();

    $stmt = $pdo->prepare('SELECT failures, window_started FROM auth_throttle WHERE key_hash = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();

    if (!$row || ($now - (int)$row['window_started']) > $windowSeconds) {
        $failures = 1;
        $windowStarted = $now;
    } else {
        $failures = (int)$row['failures'] + 1;
        $windowStarted = (int)$row['window_started'];
    }

    $lockedUntil = $failures >= $maxFailures ? $now + $lockSeconds : null;

    $stmt = $pdo->prepare(
        'INSERT INTO auth_throttle (key_hash, failures, window_started, locked_until, updated_at)
         VALUES (:key, :failures, :window_started, :locked_until, :updated_at)'
        . db_upsert_clause(
            $pdo,
            ['key_hash'],
            ['failures', 'window_started', 'locked_until', 'updated_at']
        )
    );
    $stmt->execute([
        'key' => $key,
        'failures' => $failures,
        'window_started' => $windowStarted,
        'locked_until' => $lockedUntil,
        'updated_at' => $now,
    ]);
}

function throttle_clear(string $scope, string $identifier = ''): void
{
    app_db()->prepare('DELETE FROM auth_throttle WHERE key_hash = :key')
        ->execute(['key' => throttle_key($scope, $identifier)]);
}

function normalize_email(string $email): string
{
    $email = trim($email);
    return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
}

function default_grade_scale(): array
{
    return [
        'grade_5_min' => 85,
        'grade_4_min' => 71,
        'grade_3_min' => 50,
    ];
}

function normalize_grade_scale(?array $scale): array
{
    $defaults = default_grade_scale();
    $grade5 = (int)($scale['grade_5_min'] ?? $defaults['grade_5_min']);
    $grade4 = (int)($scale['grade_4_min'] ?? $defaults['grade_4_min']);
    $grade3 = (int)($scale['grade_3_min'] ?? $defaults['grade_3_min']);

    if (
        $grade3 < 1 || $grade3 > 98
        || $grade4 <= $grade3 || $grade4 > 99
        || $grade5 <= $grade4 || $grade5 > 100
    ) {
        return $defaults;
    }

    return [
        'grade_5_min' => $grade5,
        'grade_4_min' => $grade4,
        'grade_3_min' => $grade3,
    ];
}

function school_grade_scale(PDO $pdo, ?int $schoolId): array
{
    if ($schoolId === null || $schoolId < 1) {
        return default_grade_scale();
    }

    static $cache = [];
    if (isset($cache[$schoolId])) {
        return $cache[$schoolId];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT grade_5_min, grade_4_min, grade_3_min
             FROM schools
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $schoolId]);
        $row = $stmt->fetch();
        return $cache[$schoolId] = normalize_grade_scale(is_array($row) ? $row : null);
    } catch (Throwable) {
        return $cache[$schoolId] = default_grade_scale();
    }
}

function site_legal_profile(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT entity_type, legal_name, inn, ogrn, legal_address, postal_address,
                phone, email, support_email, tax_note, updated_at
         FROM site_legal_profile
         WHERE id = 1
         LIMIT 1'
    );
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [
            'entity_type' => 'self_employed',
            'legal_name' => null,
            'inn' => null,
            'ogrn' => null,
            'legal_address' => null,
            'postal_address' => null,
            'phone' => null,
            'email' => 'info@urovia.ru',
            'support_email' => 'info@urovia.ru',
            'tax_note' => null,
            'updated_at' => null,
        ];
    }
    return $row;
}

function yookassa_config_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'yookassa-config.php';
}

function yookassa_runtime_config(): array
{
    $path = yookassa_config_path();
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function yookassa_config(): array
{
    $stored = yookassa_runtime_config();
    $envTestMode = (string)(getenv('YOO_KASSA_TEST_MODE') ?: '1');

    return [
        'shop_id' => trim((string)($stored['shop_id'] ?? getenv('YOO_KASSA_SHOP_ID') ?: '')),
        'secret_key' => trim((string)($stored['secret_key'] ?? getenv('YOO_KASSA_SECRET_KEY') ?: '')),
        'api_base' => rtrim((string)($stored['api_base'] ?? getenv('YOO_KASSA_API_BASE') ?: 'https://api.yookassa.ru/v3'), '/'),
        'test_mode' => array_key_exists('test_mode', $stored)
            ? (bool)$stored['test_mode']
            : filter_var($envTestMode, FILTER_VALIDATE_BOOLEAN),
        'cron_secret' => trim((string)($stored['cron_secret'] ?? getenv('SUBSCRIPTION_CRON_SECRET') ?: '')),
    ];
}

function yookassa_is_configured(): bool
{
    $config = yookassa_config();
    return $config['shop_id'] !== '' && $config['secret_key'] !== '';
}

function yookassa_request(string $method, string $path, ?array $payload = null, ?string $idempotenceKey = null): array
{
    $config = yookassa_config();
    if ($config['shop_id'] === '' || $config['secret_key'] === '') {
        throw new RuntimeException('ЮKassa не настроена: отсутствует shopId или секретный ключ.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На сервере не включён cURL, необходимый для ЮKassa.');
    }

    $url = $config['api_base'] . '/' . ltrim($path, '/');
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Не удалось инициализировать соединение с ЮKassa.');
    }

    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($idempotenceKey !== null && $idempotenceKey !== '') {
        $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['shop_id'] . ':' . $config['secret_key'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $curlError !== '') {
        throw new RuntimeException('Не удалось связаться с ЮKassa.');
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('ЮKassa вернула некорректный ответ.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $description = trim((string)($data['description'] ?? $data['message'] ?? ''));
        throw new RuntimeException($description !== '' ? 'ЮKassa: ' . $description : 'ЮKassa отклонила запрос.');
    }

    return $data;
}

function subscription_activate_from_payment(PDO $pdo, array $order, array $payment): void
{
    $userId = (int)$order['user_id'];
    $planCode = (string)$order['plan_code'];
    $period = (string)$order['billing_period'];
    $now = time();
    $startsAt = gmdate('Y-m-d H:i:s', $now);

    $existingStmt = $pdo->prepare(
        'SELECT ends_at FROM user_subscriptions WHERE user_id = :user_id LIMIT 1'
    );
    $existingStmt->execute(['user_id' => $userId]);
    $existingEnds = $existingStmt->fetchColumn();
    $baseTs = $now;
    if (is_string($existingEnds) && $existingEnds !== '') {
        $existingTs = strtotime($existingEnds);
        if ($existingTs !== false && $existingTs > $baseTs) {
            $baseTs = $existingTs;
        }
    }

    $endsTs = $period === 'year'
        ? strtotime('+1 year', $baseTs)
        : strtotime('+1 month', $baseTs);
    if ($endsTs === false) {
        throw new RuntimeException('Не удалось рассчитать срок подписки.');
    }
    $endsAt = gmdate('Y-m-d H:i:s', $endsTs);

    $savedMethod = null;
    if (!empty($payment['payment_method']['saved']) && !empty($payment['payment_method']['id'])) {
        $savedMethod = (string)$payment['payment_method']['id'];
    }

    $autoRenew = $savedMethod !== null ? 1 : 0;

    $stmt = $pdo->prepare(
        'INSERT INTO user_subscriptions
         (user_id, plan_code, status, starts_at, ends_at, trial_ends_at, auto_renew, billing_period,
          payment_provider, provider_payment_method_id, created_at, updated_at)
         VALUES (:user_id, :plan_code, "active", :starts_at, :ends_at, NULL, :auto_renew, :billing_period,
                 "yookassa", :provider_payment_method_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        . db_upsert_clause(
            $pdo,
            ['user_id'],
            ['plan_code', 'status', 'starts_at', 'ends_at', 'trial_ends_at', 'auto_renew', 'billing_period',
             'payment_provider', 'provider_payment_method_id', 'updated_at']
        )
    );
    $stmt->execute([
        'user_id' => $userId,
        'plan_code' => $planCode,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'auto_renew' => $autoRenew,
        'billing_period' => $period,
        'provider_payment_method_id' => $savedMethod,
    ]);
}

function subscription_plan_catalog(): array
{
    return [
        'basic' => [
            'code' => 'basic',
            'name' => 'Базовый',
            'monthly_price_minor' => 0,
            'yearly_price_minor' => 0,
            'max_classes' => 2,
            'max_students' => 60,
            'max_active_assignments' => 10,
            'ai_monthly_limit' => 0,
            'features' => [
                'manual_assignments' => true,
                'auto_grading' => true,
                'journal' => true,
                'library_access' => true,
                'file_import' => false,
                'advanced_analytics' => false,
                'export_results' => false,
                'marketplace_sell' => false,
                'premium_ai' => false,
            ],
        ],
        'pro' => [
            'code' => 'pro',
            'name' => 'PRO',
            'monthly_price_minor' => 44900,
            'yearly_price_minor' => 449000,
            'max_classes' => 10,
            'max_students' => 300,
            'max_active_assignments' => 100,
            'ai_monthly_limit' => 50,
            'features' => [
                'manual_assignments' => true,
                'auto_grading' => true,
                'journal' => true,
                'library_access' => true,
                'file_import' => true,
                'advanced_analytics' => true,
                'export_results' => true,
                'marketplace_sell' => true,
                'premium_ai' => false,
            ],
        ],
        'premium' => [
            'code' => 'premium',
            'name' => 'Premium',
            'monthly_price_minor' => 89900,
            'yearly_price_minor' => 899000,
            'max_classes' => null,
            'max_students' => null,
            'max_active_assignments' => null,
            'ai_monthly_limit' => 300,
            'features' => [
                'manual_assignments' => true,
                'auto_grading' => true,
                'journal' => true,
                'library_access' => true,
                'file_import' => true,
                'advanced_analytics' => true,
                'export_results' => true,
                'marketplace_sell' => true,
                'premium_ai' => true,
            ],
        ],
    ];
}

function subscription_effective_state(PDO $pdo, array $user): array
{
    $plans = subscription_plan_catalog();
    $basic = $plans['basic'];
    if (($user['role'] ?? '') === 'student') {
        return [
            'plan_code' => 'basic',
            'plan' => $basic,
            'status' => 'student',
            'trial_ends_at' => null,
            'ends_at' => null,
            'auto_renew' => false,
            'billing_period' => null,
            'enforcement_enabled' => false,
        ];
    }

    $grantStmt = $pdo->prepare(
        'SELECT plan_code, starts_at, ends_at, note, assigned_by
         FROM subscription_admin_grants
         WHERE user_id = :user_id
           AND starts_at <= CURRENT_TIMESTAMP
           AND (ends_at IS NULL OR ends_at >= CURRENT_TIMESTAMP)
         LIMIT 1'
    );
    $grantStmt->execute(['user_id' => (int)$user['id']]);
    $grant = $grantStmt->fetch();

    if (is_array($grant) && isset($plans[(string)$grant['plan_code']])) {
        $grantCode = (string)$grant['plan_code'];
        return [
            'plan_code' => $grantCode,
            'plan' => $plans[$grantCode],
            'status' => 'admin_grant',
            'trial_ends_at' => null,
            'ends_at' => $grant['ends_at'] ?? null,
            'auto_renew' => false,
            'billing_period' => null,
            'payment_provider' => 'manual_admin',
            'grant_note' => $grant['note'] ?? null,
            'assigned_by' => isset($grant['assigned_by']) ? (int)$grant['assigned_by'] : null,
            'enforcement_enabled' => false,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT plan_code, status, starts_at, ends_at, trial_ends_at, auto_renew, billing_period, payment_provider
         FROM user_subscriptions
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    $row = $stmt->fetch();

    $planCode = is_array($row) ? (string)($row['plan_code'] ?? 'basic') : 'basic';
    if (!isset($plans[$planCode])) $planCode = 'basic';
    $status = is_array($row) ? (string)($row['status'] ?? 'inactive') : 'inactive';

    $now = time();
    $trialEnds = is_array($row) && !empty($row['trial_ends_at']) ? strtotime((string)$row['trial_ends_at']) : false;
    $endsAt = is_array($row) && !empty($row['ends_at']) ? strtotime((string)$row['ends_at']) : false;

    if ($status === 'trial' && $trialEnds !== false && $trialEnds < $now) {
        $status = 'expired';
        $planCode = 'basic';
    } elseif ($status === 'active' && $endsAt !== false && $endsAt < $now) {
        $status = 'expired';
        $planCode = 'basic';
    } elseif (!in_array($status, ['trial', 'active'], true)) {
        $planCode = 'basic';
    }

    return [
        'plan_code' => $planCode,
        'plan' => $plans[$planCode],
        'status' => $status,
        'trial_ends_at' => is_array($row) ? ($row['trial_ends_at'] ?? null) : null,
        'ends_at' => is_array($row) ? ($row['ends_at'] ?? null) : null,
        'auto_renew' => is_array($row) ? (bool)($row['auto_renew'] ?? 0) : false,
        'billing_period' => is_array($row) ? ($row['billing_period'] ?? null) : null,
        'payment_provider' => is_array($row) ? ($row['payment_provider'] ?? null) : null,
        // Stage 1 intentionally does not block existing workflows yet.
        'enforcement_enabled' => false,
    ];
}

function subscription_feature_allowed(PDO $pdo, array $user, string $feature): bool
{
    $state = subscription_effective_state($pdo, $user);
    if (!$state['enforcement_enabled']) {
        return true;
    }
    return (bool)($state['plan']['features'][$feature] ?? false);
}

function subscription_usage_snapshot(PDO $pdo, array $user, ?int $schoolId): array
{
    if (($user['role'] ?? '') === 'student') {
        return ['classes' => 0, 'students' => 0, 'active_assignments' => 0, 'ai_generations' => 0];
    }

    $userId = (int)$user['id'];
    $classes = 0;
    $students = 0;
    $activeAssignments = 0;

    if ($schoolId !== null && $schoolId > 0) {
        if (is_platform_admin($user) || can_manage_school($user, $schoolId)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = :school_id');
            $stmt->execute(['school_id' => $schoolId]);
            $classes = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT cs.student_id)
                 FROM class_students cs
                 JOIN classes c ON c.id = cs.class_id
                 WHERE c.school_id = :school_id'
            );
            $stmt->execute(['school_id' => $schoolId]);
            $students = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM assignments
                 WHERE school_id = :school_id
                   AND status = "published"
                   AND COALESCE(workflow_status, "assigned") <> "completed"'
            );
            $stmt->execute(['school_id' => $schoolId]);
            $activeAssignments = (int)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT class_id) FROM teacher_classes
                 WHERE school_id = :school_id AND teacher_id = :teacher_id'
            );
            $stmt->execute(['school_id' => $schoolId, 'teacher_id' => $userId]);
            $classes = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT cs.student_id)
                 FROM teacher_classes tc
                 JOIN class_students cs ON cs.class_id = tc.class_id
                 WHERE tc.school_id = :school_id AND tc.teacher_id = :teacher_id'
            );
            $stmt->execute(['school_id' => $schoolId, 'teacher_id' => $userId]);
            $students = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM assignments
                 WHERE school_id = :school_id
                   AND teacher_id = :teacher_id
                   AND status = "published"
                   AND COALESCE(workflow_status, "assigned") <> "completed"'
            );
            $stmt->execute(['school_id' => $schoolId, 'teacher_id' => $userId]);
            $activeAssignments = (int)$stmt->fetchColumn();
        }
    }

    $periodKey = gmdate('Y-m');
    $stmt = $pdo->prepare(
        'SELECT ai_generations FROM subscription_usage_monthly
         WHERE user_id = :user_id AND period_key = :period_key
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId, 'period_key' => $periodKey]);

    return [
        'classes' => $classes,
        'students' => $students,
        'active_assignments' => $activeAssignments,
        'ai_generations' => (int)($stmt->fetchColumn() ?: 0),
    ];
}

function grade_from_percent(float $percent, ?array $scale = null): string
{
    $percent = max(0.0, min(100.0, $percent));
    $scale = normalize_grade_scale($scale);

    if ($percent >= (float)$scale['grade_5_min']) return '5';
    if ($percent >= (float)$scale['grade_4_min']) return '4';
    if ($percent >= (float)$scale['grade_3_min']) return '3';
    return '2';
}

function grade_from_percent_for_school(PDO $pdo, ?int $schoolId, float $percent): string
{
    return grade_from_percent($percent, school_grade_scale($pdo, $schoolId));
}

function grade_from_percent_for_assignment(PDO $pdo, int $assignmentId, float $percent): string
{
    $stmt = $pdo->prepare('SELECT school_id FROM assignments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $assignmentId]);
    $schoolId = (int)($stmt->fetchColumn() ?: 0);
    return grade_from_percent_for_school($pdo, $schoolId > 0 ? $schoolId : null, $percent);
}

function find_user_by_identity(PDO $pdo, string $identity): ?array
{
    $identity = trim($identity);
    if ($identity === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, login_name, password_hash,
                role, is_active, is_platform_admin
         FROM users
         WHERE email = :identity_email OR login_name = :identity_login
         LIMIT 1'
    );
    $stmt->execute([
        'identity_email' => $identity,
        'identity_login' => $identity,
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function generate_staff_login(PDO $pdo): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $login = 'uv' . $suffix;

        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE login_name = :login LIMIT 1');
        $stmt->execute(['login' => $login]);
        if (!$stmt->fetchColumn()) {
            return $login;
        }
    }

    throw new RuntimeException('Не удалось создать уникальный логин.');
}

function generate_temporary_password(int $length = 14): string
{
    $length = max(12, min(32, $length));
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $lower . $upper . $digits;

    $chars = [
        $lower[random_int(0, strlen($lower) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];

    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function uvoria_app_url(): string
{
    $configured = trim((string)(getenv('UVORIA_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return 'https://urovia.ru';
}

function uvoria_mail_from(): string
{
    $from = trim((string)(getenv('UVORIA_MAIL_FROM') ?: 'info@urovia.ru'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'info@urovia.ru';
    }
    return $from;
}

function send_uvoria_email(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return false;
    }

    $from = uvoria_mail_from();

    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: UROVIA <' . $from . '>',
        'Reply-To: ' . $from,
        'Sender: ' . $from,
        'X-Mailer: UROVIA',
    ];

    $headerString = implode("\r\n", $headers);
    $envelopeSender = '-f' . escapeshellarg($from);

    if (@mail($to, $encodedSubject, $body, $headerString, $envelopeSender)) {
        return true;
    }

    return @mail($to, $encodedSubject, $body, $headerString);
}
