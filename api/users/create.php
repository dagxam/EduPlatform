<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Этот системный раздел доступен только администратору UVORIA.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$firstName = trim((string) ($data['first_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$email = normalize_email((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');
$role = (string) ($data['role'] ?? '');
$className = trim((string) ($data['class_name'] ?? ''));

if ($firstName === '' || $lastName === '') {
    json_response(['ok' => false, 'error' => 'Укажите имя и фамилию.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email.'], 422);
}
if (!in_array($role, ['teacher', 'student'], true)) {
    json_response(['ok' => false, 'error' => 'Можно создать учителя или ученика.'], 422);
}

$passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
if ($passwordLength < 8) {
    json_response(['ok' => false, 'error' => 'Пароль должен содержать минимум 8 символов.'], 422);
}
if ($role === 'student' && $className === '') {
    json_response(['ok' => false, 'error' => 'Для ученика укажите класс.'], 422);
}

$pdo = app_db();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, role, class_name)
         VALUES (:first_name, :last_name, :email, :password_hash, :role, :class_name)'
    );
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'class_name' => $role === 'student' ? $className : null,
    ]);
} catch (PDOException $e) {
    if ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Пользователь с таким email уже существует.'], 409);
    }
    throw $e;
}

$id = (int) $pdo->lastInsertId();
$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, role, class_name, is_active, created_at
     FROM users WHERE id = :id'
);
$stmt->execute(['id' => $id]);

json_response([
    'ok' => true,
    'user' => $stmt->fetch(),
], 201);