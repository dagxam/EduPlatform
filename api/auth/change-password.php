<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$currentPassword = (string)($data['current_password'] ?? '');
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

if ((int)($user['must_change_password'] ?? 0) !== 1) {
    if ($currentPassword === '') {
        json_response(['ok' => false, 'error' => 'Введите текущий пароль.'], 422);
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$user['id']]);
    $currentHash = (string)($stmt->fetchColumn() ?: '');
    if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
        json_response(['ok' => false, 'error' => 'Текущий пароль указан неверно.'], 401);
    }
}

$stmt = $pdo->prepare(
    'UPDATE users
     SET password_hash = :password_hash,
         must_change_password = 0,
         session_version = session_version + 1,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => (int)$user['id'],
]);

$versionStmt = $pdo->prepare('SELECT session_version FROM users WHERE id = :id');
$versionStmt->execute(['id' => (int)$user['id']]);
$_SESSION['session_version'] = (int)($versionStmt->fetchColumn() ?: 0);

audit_event('temporary_password_changed', 'user', (int)$user['id'], [], current_school_id(), (int)$user['id']);

json_response(['ok' => true]);
