<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Назначать администраторов может только главный администратор UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, false);
$data = read_json_body();
$teacherId = (int)($data['teacher_id'] ?? 0);

if ($teacherId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран учитель.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.role, u.is_platform_admin, su.role AS school_role, su.can_teach
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :teacher_id
       AND su.is_active = 1
       AND su.can_teach = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'teacher_id' => $teacherId,
]);
$teacher = $stmt->fetch();

if (!$teacher) {
    json_response(['ok' => false, 'error' => 'Учитель не найден в выбранной школе.'], 404);
}
if ((int)($teacher['is_platform_admin'] ?? 0) === 1) {
    json_response(['ok' => false, 'error' => 'Главный администратор UVORIA уже имеет максимальные права.'], 409);
}
if (in_array(($teacher['school_role'] ?? ''), ['school_admin', 'owner'], true)) {
    json_response(['ok' => false, 'error' => 'Этот пользователь уже является администратором школы.'], 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE users SET role = "admin", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute(['id' => $teacherId]);

    $pdo->prepare(
        'UPDATE school_users
         SET role = "school_admin", can_teach = 1, is_active = 1
         WHERE school_id = :school_id AND user_id = :user_id'
    )->execute([
        'school_id' => $schoolId,
        'user_id' => $teacherId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('teacher_granted_school_admin', 'user', $teacherId, [
    'kept_teacher_role' => true,
], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'admin_id' => $teacherId, 'can_teach' => true]);
