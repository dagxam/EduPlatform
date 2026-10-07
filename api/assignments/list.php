<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$params = ['school_id' => $schoolId];
$conditions = ['a.school_id = :school_id'];

if (!can_manage_school($user, $schoolId)) {
    if (!can_teach_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }

    $conditions[] = '(
        a.teacher_id = :teacher_id
        OR EXISTS (
            SELECT 1
            FROM teacher_subjects ts
            WHERE ts.school_id = a.school_id
              AND ts.teacher_id = :teacher_id_subject
              AND ts.subject_id = a.subject_id
        )
    )';
    $params['teacher_id'] = (int)$user['id'];
    $params['teacher_id_subject'] = (int)$user['id'];
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = app_db()->prepare(
    "SELECT a.id, a.teacher_id, a.subject_id, a.title, a.type, a.status, a.workflow_status,
            a.review_submitted_at, a.reviewed_at, a.reviewed_by, a.review_comment, a.completed_at,
            a.max_attempts, a.time_limit_minutes, a.starts_at, a.due_at, a.show_answers,
            a.focus_policy, a.variant_count, a.shuffle_questions, a.shuffle_options, a.shuffle_structured, a.created_at,
            s.name AS subject_name,
            ai.source_format, ai.parse_status, ai.parsed_question_count, ai.parser_message,
            COUNT(DISTINCT q.id) AS questions_count,
            GROUP_CONCAT(DISTINCT COALESCE(c.display_name, c.name)) AS class_names,
            GROUP_CONCAT(DISTINCT c.id) AS class_ids,
            COUNT(DISTINCT at.id) AS attempts_count,
            (SELECT COUNT(*) FROM attempts ax WHERE ax.assignment_id = a.id) AS all_attempts_count,
            u.first_name AS teacher_first_name,
            u.last_name AS teacher_last_name,
            a.source_school_id, a.source_assignment_id,
            src.name AS source_school_name,
            li.id AS library_item_id, li.status AS library_status
     FROM assignments a
     LEFT JOIN users u ON u.id = a.teacher_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN schools src ON src.id = a.source_school_id
     LEFT JOIN library_items li ON li.source_school_id = a.school_id AND li.source_assignment_id = a.id
     LEFT JOIN assignment_imports ai ON ai.assignment_id = a.id
     LEFT JOIN questions q ON q.assignment_id = a.id AND q.is_active = 1
     LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
     LEFT JOIN classes c ON c.id = ac.class_id
     LEFT JOIN attempts at ON at.assignment_id = a.id AND at.status <> 'in_progress'
     $where
     GROUP BY a.id
     ORDER BY a.id DESC"
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$classDetailsStmt = app_db()->prepare(
    'SELECT ac.assignment_id, ac.class_id, ac.time_limit_minutes,
            COALESCE(c.display_name, c.name) AS class_name
     FROM assignment_classes ac
     JOIN assignments a ON a.id = ac.assignment_id
     JOIN classes c ON c.id = ac.class_id
     WHERE a.school_id = :school_id
     ORDER BY ac.assignment_id DESC, COALESCE(c.display_name, c.name), c.id'
);
$classDetailsStmt->execute(['school_id' => $schoolId]);

$classDetailsByAssignment = [];
foreach ($classDetailsStmt->fetchAll() as $classRow) {
    $assignmentId = (int)$classRow['assignment_id'];
    if (!isset($classDetailsByAssignment[$assignmentId])) {
        $classDetailsByAssignment[$assignmentId] = [];
    }
    $classDetailsByAssignment[$assignmentId][] = [
        'class_id' => (int)$classRow['class_id'],
        'class_name' => (string)$classRow['class_name'],
        'time_limit_minutes' => $classRow['time_limit_minutes'] !== null
            ? (int)$classRow['time_limit_minutes']
            : null,
    ];
}

$studentDetailsStmt = app_db()->prepare(
    'SELECT ast.assignment_id, ast.student_id, ast.time_limit_minutes,
            u.first_name, u.last_name, u.middle_name,
            cs.class_id,
            COALESCE(c.display_name, c.name) AS class_name
     FROM assignment_students ast
     JOIN assignments a ON a.id = ast.assignment_id
     JOIN users u ON u.id = ast.student_id
     LEFT JOIN class_students cs ON cs.student_id = ast.student_id
     LEFT JOIN classes c ON c.id = cs.class_id
     WHERE a.school_id = :school_id
     ORDER BY ast.assignment_id DESC, COALESCE(c.display_name, c.name), u.last_name, u.first_name'
);
$studentDetailsStmt->execute(['school_id' => $schoolId]);

$studentDetailsByAssignment = [];
foreach ($studentDetailsStmt->fetchAll() as $studentRow) {
    $assignmentId = (int)$studentRow['assignment_id'];
    if (!isset($studentDetailsByAssignment[$assignmentId])) {
        $studentDetailsByAssignment[$assignmentId] = [];
    }
    $studentDetailsByAssignment[$assignmentId][] = [
        'student_id' => (int)$studentRow['student_id'],
        'student_first_name' => (string)($studentRow['first_name'] ?? ''),
        'student_last_name' => (string)($studentRow['last_name'] ?? ''),
        'student_middle_name' => (string)($studentRow['middle_name'] ?? ''),
        'class_id' => $studentRow['class_id'] !== null ? (int)$studentRow['class_id'] : null,
        'class_name' => (string)($studentRow['class_name'] ?? ''),
        'time_limit_minutes' => $studentRow['time_limit_minutes'] !== null
            ? (int)$studentRow['time_limit_minutes']
            : null,
    ];
}

foreach ($assignments as &$assignment) {
    $id = (int)$assignment['id'];
    $assignment['class_assignments'] = $classDetailsByAssignment[$id] ?? [];
    $assignment['student_assignments'] = $studentDetailsByAssignment[$id] ?? [];
}
unset($assignment);

$settingsStmt = app_db()->prepare(
    'SELECT assignment_review_required
     FROM schools
     WHERE id = :school_id
     LIMIT 1'
);
$settingsStmt->execute(['school_id' => $schoolId]);
$reviewRequired = (int)($settingsStmt->fetchColumn() ?: 0) === 1;

json_response([
    'ok' => true,
    'assignments' => $assignments,
    'workflow' => [
        'review_required' => $reviewRequired,
        'can_manage' => can_manage_school($user, $schoolId),
    ],
]);
