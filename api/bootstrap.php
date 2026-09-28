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

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Некорректный JSON.'], 400);
    }

    return $data;
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        json_response([
            'ok' => false,
            'error' => 'На сервере не включено расширение PDO_SQLite.',
            'code' => 'SQLITE_UNAVAILABLE',
        ], 500);
    }

    $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        json_response(['ok' => false, 'error' => 'Не удалось создать папку storage.'], 500);
    }

    $databaseFile = $storageDir . DIRECTORY_SEPARATOR . 'eduplatform.sqlite';
    $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');

    $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        json_response(['ok' => false, 'error' => 'Не найден файл схемы базы данных.'], 500);
    }

    $pdo->exec($schema);
    apply_schema_migrations($pdo);
    backup_maybe_run_daily($pdo);
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

    $pdo->exec("UPDATE assignments
        SET workflow_status = 'assigned'
        WHERE status = 'published' AND workflow_status = 'draft'");
    $pdo->exec("UPDATE assignments
        SET workflow_status = 'completed', completed_at = COALESCE(completed_at, updated_at)
        WHERE status = 'closed' AND workflow_status <> 'completed'");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignments_workflow_status
        ON assignments(school_id, workflow_status)");
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
         VALUES (:key, :failures, :window_started, :locked_until, :updated_at)
         ON CONFLICT(key_hash) DO UPDATE SET
           failures = excluded.failures,
           window_started = excluded.window_started,
           locked_until = excluded.locked_until,
           updated_at = excluded.updated_at'
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
