<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$params = [];
$where = '';
if ($user['role'] === 'teacher') {
    $where = 'WHERE a.teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}

$stmt = app_db()->prepare(
    "SELECT a.id, a.title, a.type, a.status, a.max_attempts, a.time_limit_minutes,
            a.focus_policy, a.created_at,
            s.name AS subject_name,
            GROUP_CONCAT(c.name, ', ') AS class_names,
            COUNT(DISTINCT at.id) AS attempts_count
     FROM assignments a
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
     LEFT JOIN classes c ON c.id = ac.class_id
     LEFT JOIN attempts at ON at.assignment_id = a.id AND at.status <> 'in_progress'
     $where
     GROUP BY a.id
     ORDER BY a.id DESC"
);
$stmt->execute($params);

json_response(['ok' => true, 'assignments' => $stmt->fetchAll()]);
