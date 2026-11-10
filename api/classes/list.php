<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();
$manager = can_manage_school($user, $schoolId);

$gradeScale = school_grade_scale($pdo, $schoolId);
$grade5Min = (int)$gradeScale['grade_5_min'];
$grade4Min = (int)$gradeScale['grade_4_min'];
$grade3Min = (int)$gradeScale['grade_3_min'];

$baseSelect =
    'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.academic_year, c.school_id,
            ca.join_code,
            CASE
              WHEN COALESCE(ca.registration_open, 0) = 1
               AND ca.registration_expires_at IS NOT NULL
               AND ca.registration_expires_at > CURRENT_TIMESTAMP
              THEN 1 ELSE 0
            END AS registration_open,
            ca.registration_expires_at,
            COUNT(DISTINCT cs.student_id) AS students_count,
            COUNT(DISTINCT CASE WHEN a.status = "published"
                                  AND COALESCE(a.workflow_status, "assigned") <> "completed"
                                THEN a.id END) AS active_assignments,
            COUNT(DISTINCT CASE WHEN at.status IN ("submitted", "needs_review")
                                  AND DATE(at.submitted_at) = CURRENT_DATE
                                THEN at.student_id END) AS submitted_today,
            AVG(CASE WHEN at.status IN ("submitted", "needs_review")
                     THEN COALESCE(
                       NULLIF(at.published_grade, ""),
                       NULLIF(at.grade, ""),
                       CASE
                         WHEN COALESCE(at.published_percent, at.percent) >= ' . $grade5Min . ' THEN 5
                         WHEN COALESCE(at.published_percent, at.percent) >= ' . $grade4Min . ' THEN 4
                         WHEN COALESCE(at.published_percent, at.percent) >= ' . $grade3Min . ' THEN 3
                         ELSE 2
                       END
                     )
                     ELSE NULL END) AS average_grade,
            AVG(CASE WHEN at.status IN ("submitted", "needs_review")
                     THEN COALESCE(at.published_percent, at.percent)
                     ELSE NULL END) AS average_percent';

$joins =
    ' FROM classes c
      LEFT JOIN class_access ca ON ca.class_id = c.id
      LEFT JOIN class_students cs ON cs.class_id = c.id
      LEFT JOIN assignment_classes ac ON ac.class_id = c.id
      LEFT JOIN assignments a ON a.id = ac.assignment_id AND a.school_id = :school_id_assignments
      LEFT JOIN attempts at ON at.assignment_id = a.id AND at.student_id = cs.student_id';

if (($user['role'] ?? '') === 'teacher' && !$manager) {
    $sql = $baseSelect . ',
            GROUP_CONCAT(DISTINCT s.name) AS subject_names'
        . $joins .
        ' JOIN teacher_classes tc
            ON tc.class_id = c.id
           AND tc.school_id = :school_id_teacher
           AND tc.teacher_id = :teacher_id
          LEFT JOIN subjects s ON s.id = tc.subject_id
          WHERE c.school_id = :school_id
          GROUP BY c.id
          ORDER BY COALESCE(c.display_name, c.name)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'school_id_assignments' => $schoolId,
        'school_id_teacher' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
    ]);
} else {
    if (!$manager) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }

    $sql = $baseSelect . ',
            GROUP_CONCAT(DISTINCT s.name) AS subject_names'
        . $joins .
        ' LEFT JOIN teacher_classes tc
            ON tc.class_id = c.id
           AND tc.school_id = :school_id_teacher
          LEFT JOIN subjects s ON s.id = tc.subject_id
          WHERE c.school_id = :school_id
          GROUP BY c.id
          ORDER BY COALESCE(c.display_name, c.name)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'school_id_assignments' => $schoolId,
        'school_id_teacher' => $schoolId,
        'school_id' => $schoolId,
    ]);
}

$classes = $stmt->fetchAll();
foreach ($classes as &$class) {
    $class['id'] = (int)$class['id'];
    $class['students_count'] = (int)($class['students_count'] ?? 0);
    $class['active_assignments'] = (int)($class['active_assignments'] ?? 0);
    $class['submitted_today'] = (int)($class['submitted_today'] ?? 0);
    $class['average_grade'] = $class['average_grade'] !== null ? round((float)$class['average_grade'], 1) : null;
    $class['average_percent'] = $class['average_percent'] !== null ? round((float)$class['average_percent'], 1) : null;
    $class['subject_names'] = array_values(array_filter(array_map('trim', explode(',', (string)($class['subject_names'] ?? '')))));
}
unset($class);

json_response(['ok' => true, 'classes' => $classes]);
