<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);
$teacherId = (int)$user['id'];

$latestAttemptSql =
    "at.id = (
        SELECT MAX(at2.id)
        FROM attempts at2
        WHERE at2.assignment_id = at.assignment_id
          AND at2.student_id = at.student_id
          AND at2.status <> 'in_progress'
    )";

$effectivePercentSql = 'COALESCE(at.published_percent, at.percent)';
$effectiveGradeSql =
    "COALESCE(
        NULLIF(at.published_grade, ''),
        CASE
          WHEN {$effectivePercentSql} >= 90 THEN 5
          WHEN {$effectivePercentSql} >= 75 THEN 4
          WHEN {$effectivePercentSql} >= 50 THEN 3
          ELSE 2
        END
      )";

$studentSql =
    "SELECT u.id,
            u.first_name,
            u.last_name,
            COALESCE(c.display_name, c.name) AS class_name,
            COUNT(*) AS works_count,
            AVG({$effectivePercentSql}) AS average_percent,
            AVG({$effectiveGradeSql}) AS average_grade
     FROM attempts at
     JOIN assignments a ON a.id = at.assignment_id
     JOIN users u ON u.id = at.student_id
     LEFT JOIN class_students cs ON cs.student_id = at.student_id
     LEFT JOIN classes c ON c.id = cs.class_id AND c.school_id = a.school_id
     WHERE a.school_id = ?
       AND at.status IN ('submitted', 'needs_review')
       AND {$latestAttemptSql}";
$studentParams = [$schoolId];
if (!$manager) {
    $studentSql .= ' AND a.teacher_id = ?';
    $studentParams[] = $teacherId;
}
$studentSql .=
    " GROUP BY u.id, u.first_name, u.last_name, c.id, c.display_name, c.name
      HAVING COUNT(*) >= 1
      ORDER BY average_grade DESC, average_percent DESC, works_count DESC, u.last_name, u.first_name
      LIMIT 5";
$stmt = $pdo->prepare($studentSql);
$stmt->execute($studentParams);
$studentRows = $stmt->fetchAll();

$studentLeaders = array_map(static function(array $row): array {
    $percent = round((float)($row['average_percent'] ?? 0), 1);
    $averageGrade = round((float)($row['average_grade'] ?? 0), 2);
    return [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['last_name'] . ' ' . (string)$row['first_name']),
        'class_name' => (string)($row['class_name'] ?? ''),
        'works_count' => (int)$row['works_count'],
        'average_percent' => $percent,
        'average_grade' => $averageGrade,
    ];
}, $studentRows);

if ($manager) {
    $classStmt = $pdo->prepare(
        'SELECT id, COALESCE(display_name, name) AS name
         FROM classes
         WHERE school_id = ?
         ORDER BY COALESCE(display_name, name)'
    );
    $classStmt->execute([$schoolId]);
} else {
    $classStmt = $pdo->prepare(
        'SELECT DISTINCT c.id, COALESCE(c.display_name, c.name) AS name
         FROM teacher_classes tc
         JOIN classes c ON c.id = tc.class_id
         WHERE tc.school_id = ? AND tc.teacher_id = ?
         ORDER BY COALESCE(c.display_name, c.name)'
    );
    $classStmt->execute([$schoolId, $teacherId]);
}
$classRows = $classStmt->fetchAll();
$classLeaders = [];

foreach ($classRows as $classRow) {
    $classId = (int)$classRow['id'];

    $studentCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM class_students cs
         JOIN users u ON u.id = cs.student_id
         WHERE cs.class_id = ? AND u.is_active = 1'
    );
    $studentCountStmt->execute([$classId]);
    $studentsCount = (int)$studentCountStmt->fetchColumn();

    $attemptSql =
        "SELECT COUNT(*) AS completed,
                AVG({$effectivePercentSql}) AS average_percent,
                AVG({$effectiveGradeSql}) AS average_grade
         FROM attempts at
         JOIN assignments a ON a.id = at.assignment_id
         JOIN class_students cs ON cs.student_id = at.student_id AND cs.class_id = ?
         WHERE a.school_id = ?
           AND at.status IN ('submitted', 'needs_review')
           AND {$latestAttemptSql}";
    $attemptParams = [$classId, $schoolId];
    if (!$manager) {
        $attemptSql .= ' AND a.teacher_id = ?';
        $attemptParams[] = $teacherId;
    }
    $attemptStmt = $pdo->prepare($attemptSql);
    $attemptStmt->execute($attemptParams);
    $stats = $attemptStmt->fetch() ?: [];

    $completed = (int)($stats['completed'] ?? 0);
    $averagePercent = $stats['average_percent'] !== null
        ? round((float)$stats['average_percent'], 1)
        : null;
    $averageGrade = $stats['average_grade'] !== null
        ? round((float)$stats['average_grade'], 2)
        : null;

    if ($completed < 1 || $averageGrade === null || $averagePercent === null) {
        continue;
    }

    $classLeaders[] = [
        'id' => $classId,
        'name' => (string)$classRow['name'],
        'students_count' => $studentsCount,
        'works_count' => $completed,
        'completed' => $completed,
        'average_percent' => $averagePercent,
        'average_grade' => $averageGrade,
    ];
}

usort($classLeaders, static function(array $a, array $b): int {
    $gradeCompare = ($b['average_grade'] <=> $a['average_grade']);
    if ($gradeCompare !== 0) return $gradeCompare;
    $averageCompare = ($b['average_percent'] <=> $a['average_percent']);
    if ($averageCompare !== 0) return $averageCompare;
    $worksCompare = ($b['works_count'] <=> $a['works_count']);
    if ($worksCompare !== 0) return $worksCompare;
    return strcmp((string)$a['name'], (string)$b['name']);
});
$classLeaders = array_slice($classLeaders, 0, 5);

$schoolLeaders = [];
if (is_platform_admin($user)) {
    $schoolStmt = $pdo->query(
        "SELECT id, name
         FROM schools
         WHERE status = 'active'
         ORDER BY name"
    );
    foreach ($schoolStmt->fetchAll() as $schoolRow) {
        $candidateSchoolId = (int)$schoolRow['id'];

        $schoolStudentsStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT cs.student_id)
             FROM classes c
             JOIN class_students cs ON cs.class_id = c.id
             JOIN users u ON u.id = cs.student_id
             WHERE c.school_id = ? AND u.is_active = 1'
        );
        $schoolStudentsStmt->execute([$candidateSchoolId]);
        $schoolStudents = (int)$schoolStudentsStmt->fetchColumn();

        $completedStmt = $pdo->prepare(
            "SELECT COUNT(*) AS completed,
                    AVG({$effectivePercentSql}) AS average_percent,
                    AVG({$effectiveGradeSql}) AS average_grade
             FROM attempts at
             JOIN assignments a ON a.id = at.assignment_id
             WHERE a.school_id = ?
               AND at.status IN ('submitted', 'needs_review')
               AND {$latestAttemptSql}
               AND EXISTS (
                   SELECT 1
                   FROM class_students school_cs
                   JOIN classes school_c ON school_c.id = school_cs.class_id
                   WHERE school_cs.student_id = at.student_id
                     AND school_c.school_id = ?
               )"
        );
        $completedStmt->execute([$candidateSchoolId, $candidateSchoolId]);
        $schoolStats = $completedStmt->fetch() ?: [];

        $completed = (int)($schoolStats['completed'] ?? 0);
        $averagePercent = $schoolStats['average_percent'] !== null
            ? round((float)$schoolStats['average_percent'], 1)
            : null;
        $averageGrade = $schoolStats['average_grade'] !== null
            ? round((float)$schoolStats['average_grade'], 2)
            : null;

        if ($completed < 1 || $averageGrade === null || $averagePercent === null) {
            continue;
        }

        $schoolLeaders[] = [
            'id' => $candidateSchoolId,
            'name' => (string)$schoolRow['name'],
            'students_count' => $schoolStudents,
            'works_count' => $completed,
            'completed' => $completed,
            'average_percent' => $averagePercent,
            'average_grade' => $averageGrade,
        ];    }

    usort($schoolLeaders, static function(array $a, array $b): int {
        $gradeCompare = ($b['average_grade'] <=> $a['average_grade']);
        if ($gradeCompare !== 0) return $gradeCompare;
        $averageCompare = ($b['average_percent'] <=> $a['average_percent']);
        if ($averageCompare !== 0) return $averageCompare;
        return ($b['works_count'] <=> $a['works_count']);
    });
    $schoolLeaders = array_slice($schoolLeaders, 0, 5);
}

json_response([
    'ok' => true,
    'rules' => [
        'student_min_works' => 1,
        'class_min_completed' => 1,
        'school_min_completed' => 1,
        'leaders_limit' => 5,
        'rating_formula' => 'Средняя оценка по всем выполненным работам',
    ],
    'students' => $studentLeaders,
    'classes' => $classLeaders,
    'schools' => $schoolLeaders,
    'school_ranking_available' => is_platform_admin($user),
]);
