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

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_platform_admin = 1")->fetchColumn() === 0) {
        $pdo->exec("UPDATE users SET is_platform_admin = 1 WHERE id = (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1)");
    }
    add_column_if_missing($pdo, 'class_access', 'registration_expires_at', 'TEXT');
    add_column_if_missing($pdo, 'classes', 'school_id', 'INTEGER');
    add_column_if_missing($pdo, 'classes', 'display_name', 'TEXT');
    add_column_if_missing($pdo, 'assignments', 'school_id', 'INTEGER');
    add_column_if_missing($pdo, 'assignments', 'focus_policy', "TEXT NOT NULL DEFAULT 'allow'");
    add_column_if_missing($pdo, 'attempts', 'last_seen_at', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'termination_reason', 'TEXT');
    add_column_if_missing($pdo, 'attempts', 'focus_violations', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'attempts', 'attempt_session_hash', 'TEXT');
    add_column_if_missing($pdo, 'answers', 'updated_at', 'TEXT');
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
        'SELECT id, first_name, last_name, email, role, is_platform_admin, class_name, is_active
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(int) $user['is_active']) {
        $_SESSION = [];
        return null;
    }

    unset($user['is_active']);
    return $user;
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
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', $scope . '|' . $identifier . '|' . $ip . '|' . $agent);
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
