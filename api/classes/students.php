<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$classId = (int)($_GET['class_id'] ?? 0);
if ($classId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан класс.'], 422);
}

$pdo = app_db();
$schoolId = current_school_id();
$conditions = ['id = :id'];
$params = ['id' => $classId];

if ($schoolId !== null) {
    if (!can_access_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
    }
    $conditions[] = 'school_id = :school_id';
    $params['school_id'] = $schoolId;
} else {
    $conditions[] = 'school_id IS NULL';
}

if ($user['role'] === 'teacher') {
    $conditions[] = 'teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}

$stmt = $pdo->prepare('SELECT 1 FROM classes WHERE ' . implode(' AND ', $conditions));
$stmt->execute($params);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.is_active,
            CASE WHEN cs.activated_at IS NULL THEN 0 ELSE 1 END AS activated
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id
     ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE'
);
$stmt->execute(['class_id' => $classId]);

json_response(['ok' => true, 'students' => $stmt->fetchAll()]);
