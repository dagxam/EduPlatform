<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body(16384);
$identityRaw=$data['identity']??$data['email']??'';
if(!is_string($identityRaw)||!is_string($data['password']??null)||strlen($identityRaw)>512||strlen($data['password'])>4096)json_response(['ok'=>false,'error'=>'Некорректные данные входа.'],422);
$identity = trim($identityRaw);
$identity = function_exists('mb_strtolower') ? mb_strtolower($identity) : strtolower($identity);
$password = (string)($data['password'] ?? '');

if ($identity === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'Введите email/логин и пароль.'], 422);
}

throttle_check('staff_login_ip','',30,900);
throttle_check('staff_login', $identity);

$user = find_user_by_identity(app_db(), $identity);

if (!$user || !in_array($user['role'],['admin','teacher'],true) || !(int)$user['is_active'] || !password_verify($password, (string)$user['password_hash'])) {
    throttle_failure('staff_login_ip','',30,900,900);
    throttle_failure('staff_login', $identity);
    audit_event('login_failed', 'user', $user ? (int)$user['id'] : null, ['kind' => 'staff']);
    usleep(250000);
    json_response(['ok' => false, 'error' => 'Неверный email/логин или пароль.'], 401);
}

throttle_clear('staff_login', $identity);
session_regenerate_id(true);
$_SESSION=[];
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
