<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$classId = (int)($_GET['class_id'] ?? 0);
if ($classId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан класс.'], 422);
}

$pdo = app_db();
if ($user['role'] === 'teacher') {
    $stmt = $pdo->prepare('SELECT 1 FROM classes WHERE id = :id AND teacher_id = :teacher_id');
    $stmt->execute(['id' => $classId, 'teacher_id' => (int)$user['id']]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
    }
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
