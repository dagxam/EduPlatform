<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();
$manager = can_manage_school($user, $schoolId);
$isMysql = db_driver($pdo) === 'mysql';
$plusSevenDaysSql = $isMysql
    ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY)'
    : "datetime(CURRENT_TIMESTAMP, '+7 days')";
$minusSevenDaysSql = $isMysql
    ? 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)'
    : "datetime(CURRENT_TIMESTAMP, '-7 days')";
$numericGradeSql = "COALESCE(at.published_grade, at.grade) IN ('2', '3', '4', '5')";

if ($manager) {
    $stmt = $pdo->prepare(
        'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.academic_year
         FROM classes c
         WHERE c.school_id = :school_id
         ORDER BY COALESCE(c.display_name, c.name)'
    );
    $stmt->execute(['school_id' => $schoolId]);
} else {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, COALESCE(c.display_name, c.name) AS name, c.academic_year
         FROM teacher_classes tc
         JOIN classes c ON c.id = tc.class_id
         WHERE tc.school_id = :school_id AND tc.teacher_id = :teacher_id
         ORDER BY COALESCE(c.display_name, c.name)'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
    ]);
}
$classes = $stmt->fetchAll();
$classIds = array_map('intval', array_column($classes, 'id'));

$year = (int)date('Y');
$month = (int)date('n');
$defaultAcademicYear = $month >= 8
    ? $year . ' / ' . ($year + 1)
    : ($year - 1) . ' / ' . $year;
$academicYear = $defaultAcademicYear . ' учебный год';
foreach ($classes as $class) {
    if (!empty($class['academic_year'])) {
        $academicYear = trim((string)$class['academic_year']) . ' учебный год';
        break;
    }
}

if (!$classIds) {
    json_response([
        'ok' => true,
        'stats' => [
            'students' => 0,
            'classes' => 0,
            'active_assignments' => 0,
            'due_this_week' => 0,
            'submitted' => 0,
            'submitted_last_7_days' => 0,
            'average_percent' => null,
            'average_grade' => null,
        ],
        'academic_year' => $academicYear,
        'active_assignments' => [],
        'classes' => [],
    ]);
}

$placeholders = implode(',', array_fill(0, count($classIds), '?'));

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT cs.student_id)
     FROM class_students cs
     WHERE cs.class_id IN ($placeholders)"
);
$stmt->execute($classIds);
$studentsCount = (int)$stmt->fetchColumn();

$activeSql =
    "SELECT COUNT(DISTINCT a.id)
     FROM assignments a
     JOIN assignment_classes ac ON ac.assignment_id = a.id
     WHERE a.school_id = ?
       AND ac.class_id IN ($placeholders)
       AND a.status = 'published'
       AND COALESCE(a.workflow_status, 'assigned') <> 'completed'
       AND (a.starts_at IS NULL OR a.starts_at <= CURRENT_TIMESTAMP)
       AND (a.due_at IS NULL OR a.due_at >= CURRENT_TIMESTAMP)";
$stmt = $pdo->prepare($activeSql);
$stmt->execute(array_merge([$schoolId], $classIds));
$activeAssignmentsCount = (int)$stmt->fetchColumn();

$weekSql =
    "SELECT COUNT(DISTINCT a.id)
     FROM assignments a
     JOIN assignment_classes ac ON ac.assignment_id = a.id
     WHERE a.school_id = ?
       AND ac.class_id IN ($placeholders)
       AND a.status = 'published'
       AND a.due_at IS NOT NULL
       AND a.due_at >= CURRENT_TIMESTAMP
       AND a.due_at < {$plusSevenDaysSql}";
$stmt = $pdo->prepare($weekSql);
$stmt->execute(array_merge([$schoolId], $classIds));
$dueThisWeek = (int)$stmt->fetchColumn();

$attemptSql =
    "SELECT COUNT(*) AS submitted_count,
            SUM(CASE WHEN at.submitted_at >= {$minusSevenDaysSql} THEN 1 ELSE 0 END) AS last_7_days,
            AVG(COALESCE(at.published_percent, at.percent)) AS avg_percent,
            AVG(CASE
              WHEN {$numericGradeSql}
              THEN CAST(COALESCE(at.published_grade, at.grade) AS DECIMAL(10,2))
              ELSE NULL
            END) AS avg_grade
     FROM attempts at
     JOIN assignments a ON a.id = at.assignment_id
     JOIN class_students cs ON cs.student_id = at.student_id
     WHERE a.school_id = ?
       AND cs.class_id IN ($placeholders)
       AND at.status IN ('submitted', 'needs_review')";
$stmt = $pdo->prepare($attemptSql);
$stmt->execute(array_merge([$schoolId], $classIds));
$attemptStats = $stmt->fetch() ?: [];

$tasksSql =
    "SELECT a.id, a.title, a.due_at, a.workflow_status,
            s.name AS subject_name,
            GROUP_CONCAT(DISTINCT COALESCE(c.display_name, c.name)) AS class_names,
            COUNT(DISTINCT cs.student_id) AS target_students,
            COUNT(DISTINCT CASE WHEN at.status IN ('submitted', 'needs_review') THEN at.student_id END) AS submitted_students
     FROM assignments a
     JOIN assignment_classes ac ON ac.assignment_id = a.id
     JOIN classes c ON c.id = ac.class_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN class_students cs ON cs.class_id = c.id
     LEFT JOIN attempts at ON at.assignment_id = a.id AND at.student_id = cs.student_id
     WHERE a.school_id = ?
       AND ac.class_id IN ($placeholders)
       AND a.status = 'published'
       AND COALESCE(a.workflow_status, 'assigned') <> 'completed'
       AND (a.starts_at IS NULL OR a.starts_at <= CURRENT_TIMESTAMP)
       AND (a.due_at IS NULL OR a.due_at >= CURRENT_TIMESTAMP)
     GROUP BY a.id
     ORDER BY (a.due_at IS NULL), a.due_at ASC, a.id DESC
     LIMIT 5";
$stmt = $pdo->prepare($tasksSql);
$stmt->execute(array_merge([$schoolId], $classIds));
$activeAssignments = $stmt->fetchAll();

$classSql =
    "SELECT c.id, COALESCE(c.display_name, c.name) AS name,
            COUNT(DISTINCT cs.student_id) AS students_count,
            AVG(CASE WHEN a.id IS NOT NULL AND at.status IN ('submitted', 'needs_review') THEN COALESCE(at.published_percent, at.percent) ELSE NULL END) AS average_percent
     FROM classes c
     LEFT JOIN class_students cs ON cs.class_id = c.id
     LEFT JOIN attempts at ON at.student_id = cs.student_id
     LEFT JOIN assignments a ON a.id = at.assignment_id AND a.school_id = ?
     WHERE c.id IN ($placeholders)
     GROUP BY c.id
     ORDER BY COALESCE(c.display_name, c.name)
     LIMIT 8";
$stmt = $pdo->prepare($classSql);
$stmt->execute(array_merge([$schoolId], $classIds));
$classCards = $stmt->fetchAll();

json_response([
    'ok' => true,
    'stats' => [
        'students' => $studentsCount,
        'classes' => count($classIds),
        'active_assignments' => $activeAssignmentsCount,
        'due_this_week' => $dueThisWeek,
        'submitted' => (int)($attemptStats['submitted_count'] ?? 0),
        'submitted_last_7_days' => (int)($attemptStats['last_7_days'] ?? 0),
        'average_percent' => $attemptStats['avg_percent'] !== null ? round((float)$attemptStats['avg_percent'], 1) : null,
        'average_grade' => $attemptStats['avg_grade'] !== null ? round((float)$attemptStats['avg_grade'], 1) : null,
    ],
    'academic_year' => $academicYear,
    'active_assignments' => $activeAssignments,
    'classes' => $classCards,
]);
