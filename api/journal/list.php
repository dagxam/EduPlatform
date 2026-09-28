<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$period = (int)($_GET['period'] ?? 90);
if (!in_array($period, [0, 30, 90, 180], true)) {
    $period = 90;
}

if ($classId < 1 || $subjectId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите класс и предмет.'], 422);
}

if ($manager) {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM classes c
         JOIN school_subjects ss ON ss.school_id = c.school_id
         WHERE c.id = ?
           AND c.school_id = ?
           AND ss.subject_id = ?
           AND ss.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$classId, $schoolId, $subjectId]);
} else {
    if (!can_teach_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Для этого аккаунта не включена роль учителя.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_classes tc
         JOIN classes c ON c.id = tc.class_id
         JOIN school_subjects ss
           ON ss.school_id = tc.school_id
          AND ss.subject_id = tc.subject_id
          AND ss.is_active = 1
         WHERE tc.school_id = ?
           AND tc.teacher_id = ?
           AND tc.class_id = ?
           AND tc.subject_id = ?
           AND c.school_id = ?
         LIMIT 1'
    );
    $stmt->execute([$schoolId, (int)$user['id'], $classId, $subjectId, $schoolId]);
}
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Нет доступа к выбранному классу и предмету.'], 403);
}

$classStmt = $pdo->prepare(
    'SELECT id, COALESCE(display_name, name) AS name, academic_year
     FROM classes
     WHERE id = ? AND school_id = ?
     LIMIT 1'
);
$classStmt->execute([$classId, $schoolId]);
$class = $classStmt->fetch();
if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$subjectStmt = $pdo->prepare(
    'SELECT s.id, s.name
     FROM school_subjects ss
     JOIN subjects s ON s.id = ss.subject_id
     WHERE ss.school_id = ? AND ss.subject_id = ? AND ss.is_active = 1
     LIMIT 1'
);
$subjectStmt->execute([$schoolId, $subjectId]);
$subject = $subjectStmt->fetch();
if (!$subject) {
    json_response(['ok' => false, 'error' => 'Предмет не найден.'], 404);
}

$studentStmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = ? AND u.is_active = 1
     ORDER BY u.last_name, u.first_name, u.id'
);
$studentStmt->execute([$classId]);
$students = $studentStmt->fetchAll();

$assignmentSql =
    'SELECT a.id, a.title, a.created_at, a.due_at, a.status, a.workflow_status
     FROM assignments a
     JOIN assignment_classes ac ON ac.assignment_id = a.id
     WHERE a.school_id = ?
       AND ac.class_id = ?
       AND a.subject_id = ?
       AND a.status IN ("published", "closed")';
$assignmentParams = [$schoolId, $classId, $subjectId];

if ($period > 0) {
    $cutoff = date('Y-m-d H:i:s', time() - ($period * 86400));
    $assignmentSql .=
        ' AND (
            COALESCE(a.due_at, a.created_at) >= ?
            OR EXISTS (
                SELECT 1
                FROM attempts recent_at
                JOIN class_students recent_cs ON recent_cs.student_id = recent_at.student_id
                WHERE recent_at.assignment_id = a.id
                  AND recent_cs.class_id = ?
                  AND recent_at.status <> "in_progress"
                  AND COALESCE(recent_at.submitted_at, recent_at.started_at) >= ?
            )
        )';
    $assignmentParams[] = $cutoff;
    $assignmentParams[] = $classId;
    $assignmentParams[] = $cutoff;
}

$assignmentSql .= ' ORDER BY COALESCE(a.due_at, a.created_at) DESC, a.id DESC LIMIT 12';
$assignmentStmt = $pdo->prepare($assignmentSql);
$assignmentStmt->execute($assignmentParams);
$assignments = $assignmentStmt->fetchAll();

$assignmentIds = array_map('intval', array_column($assignments, 'id'));
$studentIds = array_map('intval', array_column($students, 'id'));
$attemptMap = [];

if ($assignmentIds && $studentIds) {
    $assignmentMarks = implode(',', array_fill(0, count($assignmentIds), '?'));
    $studentMarks = implode(',', array_fill(0, count($studentIds), '?'));
    $attemptSql =
        "SELECT at.id, at.assignment_id, at.student_id, at.submitted_at, at.started_at,
                at.termination_reason, at.status,
                at.score, at.max_score, at.percent,
                at.published_score, at.published_percent, at.result_published_at
         FROM attempts at
         WHERE at.assignment_id IN ($assignmentMarks)
           AND at.student_id IN ($studentMarks)
           AND at.status <> 'in_progress'
         ORDER BY at.student_id, at.assignment_id,
                  COALESCE(at.submitted_at, at.started_at) DESC, at.id DESC";
    $attemptStmt = $pdo->prepare($attemptSql);
    $attemptStmt->execute(array_merge($assignmentIds, $studentIds));

    foreach ($attemptStmt->fetchAll() as $attempt) {
        $studentId = (int)$attempt['student_id'];
        $assignmentId = (int)$attempt['assignment_id'];
        $key = $studentId . ':' . $assignmentId;
        if (isset($attemptMap[$key])) {
            continue;
        }

        $adjusted = $attempt['published_score'] !== null;
        $percent = (float)($adjusted ? $attempt['published_percent'] : ($attempt['percent'] ?? 0));
        $score = (float)($adjusted ? $attempt['published_score'] : ($attempt['score'] ?? 0));
        $attemptMap[$key] = [
            'attempt_id' => (int)$attempt['id'],
            'score' => $score,
            'max_score' => (float)($attempt['max_score'] ?? 0),
            'percent' => $percent,
            'grade' => grade_from_percent($percent),
            'submitted_at' => $attempt['submitted_at'] ?? $attempt['started_at'],
            'adjusted' => $adjusted,
            'closed_by_browser' => in_array(
                (string)($attempt['termination_reason'] ?? ''),
                ['page_hidden', 'page_closed', 'browser_closed'],
                true
            ),
        ];
    }
}

$rows = [];
$completedCells = 0;
$percentSum = 0.0;
$gradeSum = 0.0;

foreach ($students as $student) {
    $studentId = (int)$student['id'];
    $cells = [];
    $studentPercents = [];
    $studentGrades = [];

    foreach ($assignmentIds as $assignmentId) {
        $cell = $attemptMap[$studentId . ':' . $assignmentId] ?? null;
        $cells[(string)$assignmentId] = $cell;
        if ($cell !== null) {
            $completedCells++;
            $percentSum += (float)$cell['percent'];
            $gradeSum += (float)$cell['grade'];
            $studentPercents[] = (float)$cell['percent'];
            $studentGrades[] = (float)$cell['grade'];
        }
    }

    $rows[] = [
        'student_id' => $studentId,
        'student_name' => trim((string)$student['last_name'] . ' ' . (string)$student['first_name']),
        'cells' => $cells,
        'completed' => count($studentPercents),
        'average_percent' => $studentPercents
            ? round(array_sum($studentPercents) / count($studentPercents), 1)
            : null,
        'average_grade' => $studentGrades
            ? round(array_sum($studentGrades) / count($studentGrades), 1)
            : null,
    ];
}

$expectedCells = count($students) * count($assignments);
$completionPercent = $expectedCells > 0
    ? round(($completedCells / $expectedCells) * 100, 1)
    : 0.0;

json_response([
    'ok' => true,
    'class' => [
        'id' => (int)$class['id'],
        'name' => (string)$class['name'],
        'academic_year' => (string)($class['academic_year'] ?? ''),
    ],
    'subject' => [
        'id' => (int)$subject['id'],
        'name' => (string)$subject['name'],
    ],
    'period' => $period,
    'assignments' => array_map(static fn(array $assignment): array => [
        'id' => (int)$assignment['id'],
        'title' => (string)$assignment['title'],
        'due_at' => $assignment['due_at'],
        'created_at' => $assignment['created_at'],
        'status' => (string)$assignment['status'],
        'workflow_status' => (string)($assignment['workflow_status'] ?? ''),
    ], $assignments),
    'students' => $rows,
    'stats' => [
        'students' => count($students),
        'assignments' => count($assignments),
        'completed' => $completedCells,
        'expected' => $expectedCells,
        'completion_percent' => $completionPercent,
        'average_percent' => $completedCells > 0 ? round($percentSum / $completedCells, 1) : null,
        'average_grade' => $completedCells > 0 ? round($gradeSum / $completedCells, 1) : null,
    ],
]);
