<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$pdo = app_db();
$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 0) {
    json_response(['ok' => false, 'error' => 'Первичная настройка уже выполнена.'], 409);
}

$data = read_json_body();
$firstName = trim((string) ($data['first_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$email = normalize_email((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($firstName === '' || $lastName === '') {
    json_response(['ok' => false, 'error' => 'Укажите имя и фамилию.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email.'], 422);
}
$passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
if ($passwordLength < 8) {
    json_response(['ok' => false, 'error' => 'Пароль должен содержать минимум 8 символов.'], 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (first_name, last_name, email, password_hash, role, is_platform_admin)
     VALUES (:first_name, :last_name, :email, :password_hash, :role, 1)'
);
$stmt->execute([
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'admin',
]);

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $pdo->lastInsertId();

json_response([
    'ok' => true,
    'user' => current_user(),
], 201);
