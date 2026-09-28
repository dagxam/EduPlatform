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

$studentSql =
    "SELECT u.id,
            u.first_name,
            u.last_name,
            COALESCE(c.display_name, c.name) AS class_name,
            COUNT(*) AS works_count,
            AVG(COALESCE(at.published_percent, at.percent)) AS average_percent
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
      HAVING COUNT(*) >= 3
      ORDER BY average_percent DESC, works_count DESC, u.last_name, u.first_name
      LIMIT 3";
$stmt = $pdo->prepare($studentSql);
$stmt->execute($studentParams);
$studentRows = $stmt->fetchAll();

$studentLeaders = array_map(static function(array $row): array {
    $percent = round((float)($row['average_percent'] ?? 0), 1);
    return [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['last_name'] . ' ' . (string)$row['first_name']),
        'class_name' => (string)($row['class_name'] ?? ''),
        'works_count' => (int)$row['works_count'],
        'average_percent' => $percent,
        'average_grade' => grade_from_percent($percent),
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

    $assignmentSql =
        "SELECT COUNT(DISTINCT a.id)
         FROM assignments a
         JOIN assignment_classes ac ON ac.assignment_id = a.id
         WHERE a.school_id = ?
           AND ac.class_id = ?
           AND a.status IN ('published', 'closed')";
    $assignmentParams = [$schoolId, $classId];
    if (!$manager) {
        $assignmentSql .= ' AND a.teacher_id = ?';
        $assignmentParams[] = $teacherId;
    }
    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);
    $assignmentsCount = (int)$assignmentStmt->fetchColumn();

    $attemptSql =
        "SELECT COUNT(*) AS completed,
                AVG(COALESCE(at.published_percent, at.percent)) AS average_percent
         FROM attempts at
         JOIN assignments a ON a.id = at.assignment_id
         JOIN assignment_classes ac ON ac.assignment_id = a.id AND ac.class_id = ?
         JOIN class_students cs ON cs.student_id = at.student_id AND cs.class_id = ?
         WHERE a.school_id = ?
           AND at.status IN ('submitted', 'needs_review')
           AND {$latestAttemptSql}";
    $attemptParams = [$classId, $classId, $schoolId];
    if (!$manager) {
        $attemptSql .= ' AND a.teacher_id = ?';
        $attemptParams[] = $teacherId;
    }
    $attemptStmt = $pdo->prepare($attemptSql);
    $attemptStmt->execute($attemptParams);
    $stats = $attemptStmt->fetch() ?: [];

    $completed = (int)($stats['completed'] ?? 0);
    $expected = $studentsCount * $assignmentsCount;
    $averagePercent = $stats['average_percent'] !== null
        ? round((float)$stats['average_percent'], 1)
        : null;
    $completionPercent = $expected > 0
        ? min(100.0, round(($completed / $expected) * 100, 1))
        : 0.0;

    if ($completed < 3 || $averagePercent === null) {
        continue;
    }

    $rating = round(($averagePercent * 0.8) + ($completionPercent * 0.2), 2);
    $classLeaders[] = [
        'id' => $classId,
        'name' => (string)$classRow['name'],
        'students_count' => $studentsCount,
        'assignments_count' => $assignmentsCount,
        'completed' => $completed,
        'expected' => $expected,
        'average_percent' => $averagePercent,
        'average_grade' => grade_from_percent($averagePercent),
        'completion_percent' => $completionPercent,
        'rating' => $rating,
    ];
}

usort($classLeaders, static function(array $a, array $b): int {
    $ratingCompare = ($b['rating'] <=> $a['rating']);
    if ($ratingCompare !== 0) return $ratingCompare;
    $averageCompare = ($b['average_percent'] <=> $a['average_percent']);
    if ($averageCompare !== 0) return $averageCompare;
    return strcmp((string)$a['name'], (string)$b['name']);
});
$classLeaders = array_slice($classLeaders, 0, 3);

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

        $expectedStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM assignment_classes ac
             JOIN assignments a ON a.id = ac.assignment_id
             JOIN classes c ON c.id = ac.class_id
             JOIN class_students cs ON cs.class_id = c.id
             WHERE a.school_id = ?
               AND c.school_id = ?
               AND a.status IN ('published', 'closed')"
        );
        $expectedStmt->execute([$candidateSchoolId, $candidateSchoolId]);
        $expected = (int)$expectedStmt->fetchColumn();

        $completedStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT at.id) AS completed,
                    AVG(COALESCE(at.published_percent, at.percent)) AS average_percent
             FROM attempts at
             JOIN assignments a ON a.id = at.assignment_id
             JOIN assignment_classes ac ON ac.assignment_id = a.id
             JOIN class_students cs
               ON cs.student_id = at.student_id
              AND cs.class_id = ac.class_id
             JOIN classes c ON c.id = ac.class_id
             WHERE a.school_id = ?
               AND c.school_id = ?
               AND at.status IN ('submitted', 'needs_review')
               AND {$latestAttemptSql}"
        );
        $completedStmt->execute([$candidateSchoolId, $candidateSchoolId]);
        $schoolStats = $completedStmt->fetch() ?: [];

        $completed = (int)($schoolStats['completed'] ?? 0);
        $averagePercent = $schoolStats['average_percent'] !== null
            ? round((float)$schoolStats['average_percent'], 1)
            : null;
        $completionPercent = $expected > 0
            ? min(100.0, round(($completed / $expected) * 100, 1))
            : 0.0;

        if ($completed < 5 || $averagePercent === null) {
            continue;
        }

        $rating = round(($averagePercent * 0.8) + ($completionPercent * 0.2), 2);
        $schoolLeaders[] = [
            'id' => $candidateSchoolId,
            'name' => (string)$schoolRow['name'],
            'students_count' => $schoolStudents,
            'completed' => $completed,
            'expected' => $expected,
            'average_percent' => $averagePercent,
            'average_grade' => grade_from_percent($averagePercent),
            'completion_percent' => $completionPercent,
            'rating' => $rating,
        ];
    }

    usort($schoolLeaders, static function(array $a, array $b): int {
        $ratingCompare = ($b['rating'] <=> $a['rating']);
        if ($ratingCompare !== 0) return $ratingCompare;
        return ($b['average_percent'] <=> $a['average_percent']);
    });
    $schoolLeaders = array_slice($schoolLeaders, 0, 3);
}

json_response([
    'ok' => true,
    'rules' => [
        'student_min_works' => 3,
        'class_min_completed' => 3,
        'school_min_completed' => 5,
        'rating_formula' => '80% средний результат + 20% выполнение',
    ],
    'students' => $studentLeaders,
    'classes' => $classLeaders,
    'schools' => $schoolLeaders,
    'school_ranking_available' => is_platform_admin($user),
]);
