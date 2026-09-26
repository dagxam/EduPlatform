<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}
$schoolId = require_active_school($user, true);

$data = read_json_body();
$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$email = normalize_email((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($firstName === '' || $lastName === '') {
    json_response(['ok' => false, 'error' => 'Укажите имя и фамилию учителя.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email.'], 422);
}
$length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
if ($length < 8) {
    json_response(['ok' => false, 'error' => 'Пароль должен содержать минимум 8 символов.'], 422);
}

$pdo = app_db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, role, is_platform_admin)
         VALUES (:first_name, :last_name, :email, :password_hash, "teacher", 0)'
    );
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $teacherId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO school_users (school_id, user_id, role, can_teach)
         VALUES (:school_id, :user_id, "teacher", 1)'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $teacherId]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Пользователь с таким email уже существует.'], 409);
    }
    throw $e;
}

audit_event('teacher_created', 'user', $teacherId, [], $schoolId, (int)$user['id']);
json_response([
    'ok' => true,
    'teacher' => [
        'id' => $teacherId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
    ],
], 201);
