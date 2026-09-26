<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$email = normalize_email((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'Введите email и пароль.'], 422);
}

throttle_check('staff_login', $email);

$stmt = app_db()->prepare(
    'SELECT id, password_hash, is_active FROM users WHERE email = :email LIMIT 1'
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
    throttle_failure('staff_login', $email);
    audit_event('login_failed', 'user', $user ? (int)$user['id'] : null, ['kind' => 'staff']);
    usleep(250000);
    json_response(['ok' => false, 'error' => 'Неверный email или пароль.'], 401);
}

throttle_clear('staff_login', $email);
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
unset($_SESSION['active_school_id']);

$fullUser = current_user();
if ($fullUser && in_array($fullUser['role'], ['admin', 'teacher'], true) && !is_platform_admin($fullUser)) {
    ensure_staff_school_context($fullUser);
}

audit_event('login_succeeded', 'user', (int)$user['id'], ['kind' => 'staff'], current_school_id(), (int)$user['id']);

json_response([
    'ok' => true,
    'user' => $fullUser,
    'active_school_id' => current_school_id(),
]);
