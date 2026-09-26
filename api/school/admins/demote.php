<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Изменять роли администраторов может только главный администратор UVORIA.'], 403);
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
    'SELECT u.id, u.is_platform_admin, su.role, su.can_teach
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :user_id
       AND su.role IN ("school_admin", "owner")
       AND su.is_active = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'user_id' => $adminId,
]);
$admin = $stmt->fetch();

if (!$admin) {
    json_response(['ok' => false, 'error' => 'Администратор не найден в выбранной школе.'], 404);
}
if ((int)$admin['is_platform_admin'] === 1) {
    json_response(['ok' => false, 'error' => 'Главного администратора UVORIA нельзя перевести в учителя.'], 409);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM school_users
     WHERE school_id = :school_id
       AND role IN ("school_admin", "owner")
       AND is_active = 1'
);
$stmt->execute(['school_id' => $schoolId]);
if ((int)$stmt->fetchColumn() <= 1) {
    json_response([
        'ok' => false,
        'error' => 'Нельзя снять права последнего администратора школы. Сначала назначьте другого.',
    ], 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE users SET role = "teacher", is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute(['id' => $adminId]);

    $pdo->prepare(
        'UPDATE school_users
         SET role = "teacher", can_teach = 1, is_active = 1
         WHERE school_id = :school_id AND user_id = :user_id'
    )->execute([
        'school_id' => $schoolId,
        'user_id' => $adminId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('school_admin_role_removed_keep_teacher', 'user', $adminId, [], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'teacher_id' => $adminId]);
