<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, true);
$data = read_json_body();
$adminId = (int)($data['admin_id'] ?? 0);

if ($adminId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран администратор.'], 422);
}
if ($adminId === (int)$user['id'] && !is_platform_admin($user)) {
    json_response([
        'ok' => false,
        'error' => 'Нельзя понизить собственную роль во время активной сессии. Это должен сделать другой администратор.',
    ], 409);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.is_platform_admin
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :user_id
       AND su.role = "school_admin"
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
    json_response(['ok' => false, 'error' => 'Главного администратора UVORIA нельзя понизить до учителя.'], 409);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM school_users
     WHERE school_id = :school_id
       AND role = "school_admin"
       AND is_active = 1'
);
$stmt->execute(['school_id' => $schoolId]);
if ((int)$stmt->fetchColumn() <= 1) {
    json_response([
        'ok' => false,
        'error' => 'Нельзя понизить последнего администратора школы. Сначала назначьте другого.',
    ], 409);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE users SET role = "teacher", is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute(['id' => $adminId]);

    $stmt = $pdo->prepare(
        'UPDATE school_users
         SET role = "teacher", is_active = 1
         WHERE school_id = :school_id AND user_id = :user_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => $adminId,
    ]);

    // Возвращаем человека как "чистого" учителя.
    // Предметы и классы администратор назначит заново.
    $stmt = $pdo->prepare(
        'DELETE FROM teacher_classes
         WHERE school_id = :school_id AND teacher_id = :teacher_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => $adminId,
    ]);

    $stmt = $pdo->prepare(
        'DELETE FROM teacher_subjects
         WHERE school_id = :school_id AND teacher_id = :teacher_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => $adminId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('school_admin_demoted_to_teacher', 'user', $adminId, [
    'teacher_assignments_reset' => true,
], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'teacher_id' => $adminId]);
