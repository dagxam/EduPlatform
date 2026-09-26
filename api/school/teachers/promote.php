<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, true);
$data = read_json_body();
$teacherId = (int)($data['teacher_id'] ?? 0);

if ($teacherId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран учитель.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.role, u.is_platform_admin, su.role AS school_role
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :teacher_id
       AND su.is_active = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'teacher_id' => $teacherId,
]);
$teacher = $stmt->fetch();

if (!$teacher || ($teacher['school_role'] ?? '') !== 'teacher' || ($teacher['role'] ?? '') !== 'teacher') {
    json_response(['ok' => false, 'error' => 'Учитель не найден в выбранной школе.'], 404);
}
if ((int)($teacher['is_platform_admin'] ?? 0) === 1) {
    json_response(['ok' => false, 'error' => 'Главный администратор UVORIA не может быть школьным учителем.'], 409);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE users SET role = "admin", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute(['id' => $teacherId]);

    $stmt = $pdo->prepare(
        'UPDATE school_users
         SET role = "school_admin", is_active = 1
         WHERE school_id = :school_id AND user_id = :user_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => $teacherId,
    ]);

    // Роль в школе взаимоисключающая: после повышения человек
    // больше не должен оставаться в активных назначениях учителя.
    $stmt = $pdo->prepare(
        'DELETE FROM teacher_classes
         WHERE school_id = :school_id AND teacher_id = :teacher_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => $teacherId,
    ]);

    $stmt = $pdo->prepare(
        'DELETE FROM teacher_subjects
         WHERE school_id = :school_id AND teacher_id = :teacher_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => $teacherId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('teacher_promoted_to_school_admin', 'user', $teacherId, [
    'teacher_assignments_cleared' => true,
], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'admin_id' => $teacherId]);
