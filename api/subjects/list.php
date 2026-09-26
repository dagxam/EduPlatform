<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = current_school_id();

if ($schoolId !== null) {
    if (!can_access_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
    }
    $stmt = app_db()->prepare(
        'SELECT s.id, s.name
         FROM school_subjects ss
         JOIN subjects s ON s.id = ss.subject_id
         WHERE ss.school_id = :school_id AND ss.is_active = 1
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute(['school_id' => $schoolId]);
} else {
    $stmt = app_db()->query('SELECT id, name FROM subjects ORDER BY name COLLATE NOCASE');
}

json_response(['ok' => true, 'subjects' => $stmt->fetchAll()]);
