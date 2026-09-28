<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$sql =
    'SELECT at.id, at.assignment_id, at.student_id, at.submitted_at, at.status, at.variant_label,
            at.score, at.max_score, at.percent, at.grade,
            at.manual_score, at.manual_percent, at.manual_grade, at.manual_comment,
            at.manual_updated_at, at.manual_updated_by,
            at.published_score, at.published_percent, at.published_grade, at.published_comment,
            at.result_published_at, at.result_published_by, at.result_revision,
            a.title AS assignment_title, a.teacher_id, a.subject_id,
            s.name AS subject_name,
            u.first_name AS student_first_name, u.last_name AS student_last_name,
            COALESCE(c.display_name, c.name) AS class_name, c.id AS class_id
     FROM attempts at
     JOIN assignments a ON a.id = at.assignment_id
     JOIN users u ON u.id = at.student_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     LEFT JOIN class_students cs ON cs.student_id = at.student_id
     LEFT JOIN classes c ON c.id = cs.class_id
     WHERE a.school_id = :school_id
       AND at.status <> "in_progress"';

$params = ['school_id' => $schoolId];
if (!$manager) {
    $sql .= ' AND a.teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}
$sql .= ' ORDER BY COALESCE(at.submitted_at, at.started_at) DESC, at.id DESC LIMIT 1000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = [];
$classes = [];
$assignments = [];

foreach ($rows as $row) {
    $display = result_display_payload($row);
    $hasDraft = result_has_unpublished_draft($row);

    $item = [
        'attempt_id' => (int)$row['id'],
        'assignment_id' => (int)$row['assignment_id'],
        'assignment_title' => (string)$row['assignment_title'],
        'subject_name' => (string)($row['subject_name'] ?? ''),
        'student_id' => (int)$row['student_id'],
        'student_first_name' => (string)($row['student_first_name'] ?? ''),
        'student_last_name' => (string)($row['student_last_name'] ?? ''),
        'class_id' => $row['class_id'] !== null ? (int)$row['class_id'] : null,
        'class_name' => (string)($row['class_name'] ?? ''),
        'submitted_at' => $row['submitted_at'],
        'status' => (string)$row['status'],
        'variant_label' => (string)($row['variant_label'] ?? ''),
        'automatic' => [
            'score' => (float)($row['score'] ?? 0),
            'max_score' => (float)($row['max_score'] ?? 0),
            'percent' => (float)($row['percent'] ?? 0),
            'grade' => (string)($row['grade'] ?? ''),
        ],
        'draft' => $row['manual_score'] !== null ? [
            'score' => (float)$row['manual_score'],
            'percent' => (float)($row['manual_percent'] ?? 0),
            'grade' => (string)($row['manual_grade'] ?? ''),
            'comment' => (string)($row['manual_comment'] ?? ''),
            'updated_at' => $row['manual_updated_at'],
        ] : null,
        'display' => $display,
        'has_unpublished_draft' => $hasDraft,
    ];
    $items[] = $item;

    if ($item['class_id']) {
        $classes[(string)$item['class_id']] = [
            'id' => $item['class_id'],
            'name' => $item['class_name'],
        ];
    }
    $assignments[(string)$item['assignment_id']] = [
        'id' => $item['assignment_id'],
        'title' => $item['assignment_title'],
    ];
}

usort($classes, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
usort($assignments, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));

json_response([
    'ok' => true,
    'items' => $items,
    'filters' => [
        'classes' => array_values($classes),
        'assignments' => array_values($assignments),
    ],
    'can_manage_all' => $manager,
]);
