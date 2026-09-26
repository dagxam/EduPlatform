<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();

if (($user['role'] ?? '') === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.academic_year, c.school_id,
                ca.join_code,
                CASE
                  WHEN COALESCE(ca.registration_open, 0) = 1
                   AND ca.registration_expires_at IS NOT NULL
                   AND ca.registration_expires_at > CURRENT_TIMESTAMP
                  THEN 1 ELSE 0
                END AS registration_open,
                ca.registration_expires_at,
                COUNT(DISTINCT cs.student_id) AS students_count
         FROM teacher_classes tc
         JOIN classes c ON c.id = tc.class_id
         LEFT JOIN class_access ca ON ca.class_id = c.id
         LEFT JOIN class_students cs ON cs.class_id = c.id
         WHERE tc.school_id = :school_id AND tc.teacher_id = :teacher_id
         GROUP BY c.id
         ORDER BY COALESCE(c.display_name, c.name) COLLATE NOCASE'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
    ]);
} else {
    if (!can_manage_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.academic_year, c.school_id,
                ca.join_code,
                CASE
                  WHEN COALESCE(ca.registration_open, 0) = 1
                   AND ca.registration_expires_at IS NOT NULL
                   AND ca.registration_expires_at > CURRENT_TIMESTAMP
                  THEN 1 ELSE 0
                END AS registration_open,
                ca.registration_expires_at,
                COUNT(DISTINCT cs.student_id) AS students_count
         FROM classes c
         LEFT JOIN class_access ca ON ca.class_id = c.id
         LEFT JOIN class_students cs ON cs.class_id = c.id
         WHERE c.school_id = :school_id
         GROUP BY c.id
         ORDER BY COALESCE(c.display_name, c.name) COLLATE NOCASE'
    );
    $stmt->execute(['school_id' => $schoolId]);
}

json_response(['ok' => true, 'classes' => $stmt->fetchAll()]);
