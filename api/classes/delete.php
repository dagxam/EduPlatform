<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$confirmed = ($data['confirm_delete'] ?? false) === true;

if ($classId < 1 || !$confirmed) {
    json_response(['ok' => false, 'error' => 'Удаление класса не подтверждено.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
if (!can_manage_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}

$stmt = $pdo->prepare(
    'SELECT id, COALESCE(display_name, name) AS name, academic_year
     FROM classes
     WHERE id = :class_id AND school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);
$class = $stmt->fetch();
if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.avatar_name
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id AND u.role = "student"'
);
$stmt->execute(['class_id' => $classId]);
$students = $stmt->fetchAll();
$studentIds = array_map('intval', array_column($students, 'id'));

$attemptIds = [];
if ($studentIds) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM attempts WHERE student_id IN ($placeholders)");
    $stmt->execute($studentIds);
    $attemptIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
}
$attemptsCount = count($attemptIds);

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'DELETE FROM audit_log
         WHERE (entity_type = "class" AND entity_id = :class_id)
            OR metadata_json LIKE :class_pattern'
    )->execute([
        'class_id' => $classId,
        'class_pattern' => '%"class_id":' . $classId . '%',
    ]);

    if ($attemptIds) {
        $attemptPlaceholders = implode(',', array_fill(0, count($attemptIds), '?'));
        $stmt = $pdo->prepare(
            "DELETE FROM audit_log WHERE entity_type = 'attempt' AND entity_id IN ($attemptPlaceholders)"
        );
        $stmt->execute($attemptIds);
    }

    if ($studentIds) {
        $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare(
            "DELETE FROM audit_log
             WHERE user_id IN ($studentPlaceholders)
                OR (entity_type = 'user' AND entity_id IN ($studentPlaceholders))"
        );
        $stmt->execute(array_merge($studentIds, $studentIds));

        $deleteStudentAuditByMetadata = $pdo->prepare(
            'DELETE FROM audit_log WHERE metadata_json LIKE :student_pattern'
        );
        foreach ($studentIds as $studentId) {
            $deleteStudentAuditByMetadata->execute([
                'student_pattern' => '%"student_id":' . $studentId . '%',
            ]);
        }

        $deleteStudent = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = "student"');
        foreach ($studentIds as $studentId) {
            $deleteStudent->execute(['id' => $studentId]);
        }
    }

    $stmt = $pdo->prepare(
        'DELETE FROM classes
         WHERE id = :class_id AND school_id = :school_id'
    );
    $stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Класс не удалось удалить.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$avatarDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars';
foreach ($students as $student) {
    $avatar = basename((string)($student['avatar_name'] ?? ''));
    if ($avatar === '') continue;
    $avatarPath = $avatarDir . DIRECTORY_SEPARATOR . $avatar;
    if (is_file($avatarPath)) @unlink($avatarPath);
}

audit_event('class_deleted', 'school', $schoolId, [
    'students_deleted' => count($studentIds),
    'attempts_deleted' => $attemptsCount,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'deleted' => [
        'class_id' => $classId,
        'class_name' => (string)$class['name'],
        'students' => count($studentIds),
        'attempts' => $attemptsCount,
    ],
    'message' => 'Класс и все его ученики удалены.',
]);
