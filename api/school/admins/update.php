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
$adminId = (int)($data['admin_id'] ?? 0);
$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$email = normalize_email((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($adminId < 1 || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Проверьте данные администратора.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT 1 FROM school_users
     WHERE school_id = :school_id AND user_id = :user_id
       AND role = "school_admin" AND is_active = 1'
);
$stmt->execute(['school_id' => $schoolId, 'user_id' => $adminId]);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Администратор не найден в этой школе.'], 404);
}

$params = [
    'id' => $adminId,
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
];
$sql = 'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, updated_at = CURRENT_TIMESTAMP';
if ($password !== '') {
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($length < 8) {
        json_response(['ok' => false, 'error' => 'Новый пароль должен содержать минимум 8 символов.'], 422);
    }
    $sql .= ', password_hash = :password_hash';
    $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}
$sql .= ' WHERE id = :id';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Этот email уже используется другим аккаунтом.'], 409);
    }
    throw $e;
}

audit_event('school_admin_updated', 'user', $adminId, [], $schoolId, (int)$user['id']);
json_response(['ok' => true]);
