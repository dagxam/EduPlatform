<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
if (!can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Для этого аккаунта не включена роль учителя.'], 403);
}

$stmt = app_db()->prepare(
    'SELECT tc.subject_id, s.name AS subject_name,
            tc.class_id, COALESCE(c.display_name, c.name) AS class_name
     FROM teacher_classes tc
     JOIN subjects s ON s.id = tc.subject_id
     JOIN classes c ON c.id = tc.class_id
     JOIN school_subjects ss
       ON ss.school_id = tc.school_id AND ss.subject_id = tc.subject_id AND ss.is_active = 1
     WHERE tc.school_id = :school_id AND tc.teacher_id = :teacher_id
     ORDER BY s.name COLLATE NOCASE, class_name COLLATE NOCASE'
);
$stmt->execute([
    'school_id' => $schoolId,
    'teacher_id' => (int)$user['id'],
]);

json_response(['ok' => true, 'options' => $stmt->fetchAll()]);
