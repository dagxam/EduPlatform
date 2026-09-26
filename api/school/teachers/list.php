<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.is_active
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.role = "teacher"
       AND su.is_active = 1
     ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE'
);
$stmt->execute(['school_id' => $schoolId]);
$teachers = $stmt->fetchAll();

$assignStmt = $pdo->prepare(
    'SELECT tc.teacher_id, tc.subject_id, s.name AS subject_name,
            tc.class_id, COALESCE(c.display_name, c.name) AS class_name
     FROM teacher_classes tc
     JOIN subjects s ON s.id = tc.subject_id
     JOIN classes c ON c.id = tc.class_id
     WHERE tc.school_id = :school_id
     ORDER BY s.name COLLATE NOCASE, class_name COLLATE NOCASE'
);
$assignStmt->execute(['school_id' => $schoolId]);
$assignments = [];
foreach ($assignStmt->fetchAll() as $row) {
    $assignments[(int)$row['teacher_id']][] = $row;
}

foreach ($teachers as &$teacher) {
    $teacher['assignments'] = $assignments[(int)$teacher['id']] ?? [];
}
unset($teacher);

json_response(['ok' => true, 'teachers' => $teachers]);
