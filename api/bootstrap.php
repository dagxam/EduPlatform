<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

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
    return $pdo;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = app_db()->prepare(
        'SELECT id, first_name, last_name, email, role, class_name, is_active
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

function normalize_email(string $email): string
{
    $email = trim($email);\n    return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
}
