<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';

$user = require_user(['student']);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT at.id AS attempt_id, at.assignment_id, at.submitted_at, at.variant_label,
            at.termination_reason, at.score, at.max_score, at.percent, at.grade,
            at.published_score, at.published_percent, at.published_grade,
            at.published_comment, at.result_published_at, at.result_revision,
            a.title AS assignment_title,
            s.name AS subject_name
     FROM attempts at
     JOIN assignments a ON a.id = at.assignment_id
     LEFT JOIN subjects s ON s.id = a.subject_id
     WHERE at.student_id = :student_id
       AND at.status <> "in_progress"
     ORDER BY COALESCE(at.submitted_at, at.started_at) DESC, at.id DESC'
);
$stmt->execute(['student_id' => (int)$user['id']]);

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $adjusted = $row['published_score'] !== null;
    $items[] = [
        'attempt_id' => (int)$row['attempt_id'],
        'assignment_id' => (int)$row['assignment_id'],
        'assignment_title' => (string)$row['assignment_title'],
        'subject_name' => (string)($row['subject_name'] ?? ''),
        'submitted_at' => $row['submitted_at'],
        'termination_reason' => (string)($row['termination_reason'] ?? ''),
        'closed_by_browser' => in_array((string)($row['termination_reason'] ?? ''), ['page_hidden', 'page_closed', 'browser_closed'], true),
        'variant_label' => (string)($row['variant_label'] ?? ''),
        'score' => (float)($adjusted ? $row['published_score'] : ($row['score'] ?? 0)),
        'max_score' => (float)($row['max_score'] ?? 0),
        'percent' => (float)($adjusted ? $row['published_percent'] : ($row['percent'] ?? 0)),
        'grade' => (string)($adjusted ? $row['published_grade'] : ($row['grade'] ?? '')),
        'comment' => (string)($adjusted ? ($row['published_comment'] ?? '') : ''),
        'adjusted' => $adjusted,
        'revision' => (int)($row['result_revision'] ?? 0),
        'published_at' => $row['result_published_at'],
    ];
}

json_response(['ok' => true, 'items' => $items]);
