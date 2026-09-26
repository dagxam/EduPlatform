<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$params = [];
$where = '';
if ($user['role'] === 'teacher') {
    $where = 'WHERE c.teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}

$stmt = app_db()->prepare(
    "SELECT c.id, c.name, c.academic_year, c.teacher_id,
            ca.join_code,
            CASE
              WHEN COALESCE(ca.registration_open, 0) = 1
               AND ca.registration_expires_at IS NOT NULL
               AND ca.registration_expires_at > CURRENT_TIMESTAMP
              THEN 1 ELSE 0
            END AS registration_open,
            ca.registration_expires_at,
            COUNT(cs.student_id) AS students_count
     FROM classes c
     LEFT JOIN class_access ca ON ca.class_id = c.id
     LEFT JOIN class_students cs ON cs.class_id = c.id
     $where
     GROUP BY c.id
     ORDER BY c.name COLLATE NOCASE"
);
$stmt->execute($params);

json_response(['ok' => true, 'classes' => $stmt->fetchAll()]);
