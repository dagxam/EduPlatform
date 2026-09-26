<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();

if (($user['role'] ?? '') === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT s.id, s.name
         FROM teacher_subjects ts
         JOIN subjects s ON s.id = ts.subject_id
         WHERE ts.school_id = :school_id AND ts.teacher_id = :teacher_id
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
    ]);
} else {
    if (!can_manage_school($user, $schoolId) && !is_platform_admin($user)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name
         FROM school_subjects ss
         JOIN subjects s ON s.id = ss.subject_id
         WHERE ss.school_id = :school_id AND ss.is_active = 1
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute(['school_id' => $schoolId]);
}

json_response(['ok' => true, 'subjects' => $stmt->fetchAll()]);
