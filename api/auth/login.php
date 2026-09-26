<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$identity = trim((string)($data['identity'] ?? $data['email'] ?? ''));
$identity = function_exists('mb_strtolower') ? mb_strtolower($identity) : strtolower($identity);
$password = (string)($data['password'] ?? '');

if ($identity === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'Введите email/логин и пароль.'], 422);
}

throttle_check('staff_login', $identity);

$stmt = app_db()->prepare(
    'SELECT id, password_hash, is_active
     FROM users
     WHERE email = :identity OR login_name = :identity
     LIMIT 1'
);
$stmt->execute(['identity' => $identity]);
$user = $stmt->fetch();

if (!$user || !(int)$user['is_active'] || !password_verify($password, (string)$user['password_hash'])) {
    throttle_failure('staff_login', $identity);
    audit_event('login_failed', 'user', $user ? (int)$user['id'] : null, ['kind' => 'staff']);
    usleep(250000);
    json_response(['ok' => false, 'error' => 'Неверный email/логин или пароль.'], 401);
}

throttle_clear('staff_login', $identity);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
unset($_SESSION['active_school_id']);

$fullUser = current_user();
if ($fullUser && in_array($fullUser['role'], ['admin', 'teacher'], true) && !is_platform_admin($fullUser)) {
    ensure_staff_school_context($fullUser);
}

audit_event('login_succeeded', 'user', (int)$user['id'], [
    'kind' => 'staff',
    'used_login_name' => !filter_var($identity, FILTER_VALIDATE_EMAIL),
], current_school_id(), (int)$user['id']);

json_response([
    'ok' => true,
    'user' => $fullUser,
    'active_school_id' => current_school_id(),
    'must_change_password' => (int)($fullUser['must_change_password'] ?? 0) === 1,
]);
