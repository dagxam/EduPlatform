<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$newPassword = (string)($data['new_password'] ?? '');
$confirmPassword = (string)($data['confirm_password'] ?? '');

$length = function_exists('mb_strlen') ? mb_strlen($newPassword) : strlen($newPassword);
if ($length < 8) {
    json_response(['ok' => false, 'error' => 'Новый пароль должен содержать минимум 8 символов.'], 422);
}
if ($newPassword !== $confirmPassword) {
    json_response(['ok' => false, 'error' => 'Пароли не совпадают.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'UPDATE users
     SET password_hash = :password_hash,
         must_change_password = 0,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => (int)$user['id'],
]);

audit_event('temporary_password_changed', 'user', (int)$user['id'], [], current_school_id(), (int)$user['id']);

json_response(['ok' => true]);
