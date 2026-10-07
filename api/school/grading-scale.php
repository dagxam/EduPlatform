<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher', 'student']);
$pdo = app_db();
$schoolId = current_school_id();

if (($user['role'] ?? '') === 'student' && ($schoolId === null || $schoolId < 1)) {
    $stmt = $pdo->prepare(
        'SELECT c.school_id
         FROM class_students cs
         JOIN classes c ON c.id = cs.class_id
         WHERE cs.student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute(['student_id' => (int)$user['id']]);
    $resolved = (int)($stmt->fetchColumn() ?: 0);
    if ($resolved > 0) {
        $schoolId = $resolved;
        $_SESSION['active_school_id'] = $resolved;
    }
}

if ($schoolId === null || $schoolId < 1) {
    json_response([
        'ok' => true,
        'school_id' => null,
        'grade_scale' => default_grade_scale(),
        'can_edit' => false,
    ]);
}

if (($user['role'] ?? '') !== 'student' && !can_access_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
}

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'grade_scale' => school_grade_scale($pdo, $schoolId),
    'can_edit' => can_manage_school($user, $schoolId),
]);
