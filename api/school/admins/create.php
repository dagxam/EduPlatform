<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Доступно только администратору UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}
$schoolId = require_active_school($user, false);

$data = read_json_body();
$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$email = normalize_email((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($firstName === '' || $lastName === '') {
    json_response(['ok' => false, 'error' => 'Укажите имя и фамилию администратора.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare('SELECT id, role, is_platform_admin FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    if ((int)$existing['is_platform_admin'] === 1) {
        json_response(['ok' => false, 'error' => 'Главный администратор UVORIA не назначается администратором отдельной школы.'], 409);
    }
    if (($existing['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'error' => 'Этот email уже принадлежит аккаунту с другой ролью.'], 409);
    }

    $membershipStmt = $pdo->prepare(
        'SELECT school_id FROM school_users
         WHERE user_id = :user_id
           AND role = "school_admin"
           AND is_active = 1
           AND school_id <> :school_id
         LIMIT 1'
    );
    $membershipStmt->execute([
        'user_id' => (int)$existing['id'],
        'school_id' => $schoolId,
    ]);
    if ($membershipStmt->fetchColumn()) {
        json_response([
            'ok' => false,
            'error' => 'Этот администратор уже закреплён за другой школой. Для каждой школы используйте отдельный аккаунт.',
        ], 409);
    }
} else {
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($length < 8) {
        json_response(['ok' => false, 'error' => 'Для нового администратора пароль должен содержать минимум 8 символов.'], 422);
    }
}

$pdo->beginTransaction();
try {
    if ($existing) {
        $adminId = (int)$existing['id'];
        $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $adminId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role, is_platform_admin)
             VALUES (:first_name, :last_name, :email, :password_hash, "admin", 0)'
        );
        $stmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $adminId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO school_users (school_id, user_id, role, can_teach, is_active)
         VALUES (:school_id, :user_id, "school_admin", 0, 1)
         ON CONFLICT(school_id, user_id) DO UPDATE SET role = "school_admin", is_active = 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $adminId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('school_admin_appointed', 'user', $adminId, [], $schoolId, (int)$user['id']);
json_response(['ok' => true, 'admin_id' => $adminId], 201);
