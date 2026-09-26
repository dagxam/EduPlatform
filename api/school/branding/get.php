<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin', 'teacher', 'student']);
$schoolId = current_school_id();

if ($schoolId === null && ($user['role'] ?? '') === 'student') {
    $stmt = app_db()->prepare(
        'SELECT c.school_id
         FROM class_students cs
         JOIN classes c ON c.id = cs.class_id
         WHERE cs.student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute(['student_id' => (int)$user['id']]);
    $schoolId = (int)($stmt->fetchColumn() ?: 0);
    if ($schoolId > 0) {
        $_SESSION['active_school_id'] = $schoolId;
    }
}

if ($schoolId === null || $schoolId < 1) {
    json_response([
        'ok' => true,
        'school_id' => null,
        'branding' => [
            'theme_color' => '#1d68f0',
            'favicon_data' => null,
        ],
        'can_edit' => false,
    ]);
}

if (($user['role'] ?? '') !== 'student' && !can_access_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
}

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'branding' => school_branding($schoolId),
    'can_edit' => is_platform_admin($user),
]);
