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
if ($adminId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран администратор.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM school_users
     WHERE school_id = :school_id AND role = "school_admin" AND is_active = 1'
);
$stmt->execute(['school_id' => $schoolId]);
if ((int)$stmt->fetchColumn() <= 1) {
    json_response([
        'ok' => false,
        'error' => 'Нельзя удалить последнего администратора школы. Сначала назначьте нового.',
    ], 409);
}

$stmt = $pdo->prepare(
    'UPDATE school_users SET is_active = 0
     WHERE school_id = :school_id AND user_id = :user_id AND role = "school_admin"'
);
$stmt->execute(['school_id' => $schoolId, 'user_id' => $adminId]);
if ($stmt->rowCount() !== 1) {
    json_response(['ok' => false, 'error' => 'Администратор не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM school_users
     WHERE user_id = :user_id AND role = "school_admin" AND is_active = 1'
);
$stmt->execute(['user_id' => $adminId]);
if ((int)$stmt->fetchColumn() === 0) {
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id AND is_platform_admin = 0')
        ->execute(['id' => $adminId]);
}

audit_event('school_admin_removed', 'user', $adminId, [], $schoolId, (int)$user['id']);
json_response(['ok' => true]);
