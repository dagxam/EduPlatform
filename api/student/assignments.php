<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['student']);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.description, a.type, a.status, a.max_attempts,
            a.time_limit_minutes, a.focus_policy, a.starts_at, a.due_at,
            a.variant_count, a.shuffle_questions, a.shuffle_options, a.shuffle_structured,
            s.name AS subject_name,
            COALESCE(c.display_name, c.name) AS class_name,
            COUNT(DISTINCT q.id) AS questions_count,
            MAX(CASE WHEN at.status = "in_progress" THEN at.id END) AS active_attempt_id,
            MAX(CASE WHEN at.status = "in_progress" THEN at.variant_label END) AS active_variant_label,
            COUNT(DISTINCT CASE WHEN at.status <> "in_progress" THEN at.id END) AS completed_attempts,
            MAX(CASE WHEN at.status <> "in_progress" THEN COALESCE(at.published_percent, at.percent) END) AS last_percent,
            MAX(CASE WHEN at.status <> "in_progress" THEN COALESCE(at.published_grade, at.grade) END) AS last_grade
     FROM class_students cs
     JOIN classes c ON c.id = cs.class_id
     JOIN assignment_classes ac ON ac.class_id = c.id
     JOIN assignments a ON a.id = ac.assignment_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN questions q ON q.assignment_id = a.id
     LEFT JOIN attempts at ON at.assignment_id = a.id AND at.student_id = cs.student_id
     WHERE cs.student_id = :student_id
       AND a.status = "published"
       AND (a.starts_at IS NULL OR a.starts_at <= CURRENT_TIMESTAMP)
     GROUP BY a.id
     ORDER BY
       CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END,
       a.due_at,
       a.id DESC'
);
$stmt->execute(['student_id' => (int)$user['id']]);
$assignments = $stmt->fetchAll();

foreach ($assignments as &$assignment) {
    $assignment['id'] = (int)$assignment['id'];
    $assignment['max_attempts'] = (int)$assignment['max_attempts'];
    $assignment['time_limit_minutes'] = $assignment['time_limit_minutes'] !== null
        ? (int)$assignment['time_limit_minutes']
        : null;
    $assignment['variant_count'] = max(1, min(4, (int)($assignment['variant_count'] ?? 1)));
    $assignment['questions_count'] = (int)$assignment['questions_count'];
    $assignment['active_attempt_id'] = $assignment['active_attempt_id'] !== null
        ? (int)$assignment['active_attempt_id']
        : null;
    $assignment['completed_attempts'] = (int)($assignment['completed_attempts'] ?? 0);
    $assignment['last_percent'] = $assignment['last_percent'] !== null
        ? (float)$assignment['last_percent']
        : null;
}
unset($assignment);

$stmt = $pdo->prepare(
    'SELECT c.id, COALESCE(c.display_name, c.name) AS name
     FROM class_students cs
     JOIN classes c ON c.id = cs.class_id
     WHERE cs.student_id = :student_id
     LIMIT 1'
);
$stmt->execute(['student_id' => (int)$user['id']]);
$class = $stmt->fetch();

json_response([
    'ok' => true,
    'class' => $class ?: null,
    'assignments' => $assignments,
]);
