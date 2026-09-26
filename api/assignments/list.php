<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$params = ['school_id' => $schoolId];
$conditions = ['a.school_id = :school_id'];

if (($user['role'] ?? '') === 'teacher') {
    $conditions[] = 'a.teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
} elseif (!can_manage_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = app_db()->prepare(
    "SELECT a.id, a.title, a.type, a.status, a.max_attempts, a.time_limit_minutes,
            a.focus_policy, a.created_at,
            s.name AS subject_name,
            ai.source_format, ai.parse_status,
            GROUP_CONCAT(COALESCE(c.display_name, c.name), ', ') AS class_names,
            COUNT(DISTINCT at.id) AS attempts_count,
            u.first_name AS teacher_first_name,
            u.last_name AS teacher_last_name
     FROM assignments a
     LEFT JOIN users u ON u.id = a.teacher_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN assignment_imports ai ON ai.assignment_id = a.id
     LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
     LEFT JOIN classes c ON c.id = ac.class_id
     LEFT JOIN attempts at ON at.assignment_id = a.id AND at.status <> 'in_progress'
     $where
     GROUP BY a.id
     ORDER BY a.id DESC"
);
$stmt->execute($params);

json_response(['ok' => true, 'assignments' => $stmt->fetchAll()]);
