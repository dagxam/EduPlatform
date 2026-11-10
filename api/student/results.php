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
    $displayPercent = (float)($adjusted ? $row['published_percent'] : ($row['percent'] ?? 0));
    $items[] = [
        'attempt_id' => (int)$row['attempt_id'],
        'assignment_id' => (int)$row['assignment_id'],
        'assignment_title' => (string)$row['assignment_title'],
        'subject_name' => (string)($row['subject_name'] ?? ''),
        'submitted_at' => $row['submitted_at'],
        'termination_reason' => (string)($row['termination_reason'] ?? ''),
        'closed_by_browser' => in_array((string)($row['termination_reason'] ?? ''), ['page_hidden', 'page_closed', 'browser_closed', 'window_blur'], true),
        'variant_label' => (string)($row['variant_label'] ?? ''),
        'score' => (float)($adjusted ? $row['published_score'] : ($row['score'] ?? 0)),
        'max_score' => (float)($row['max_score'] ?? 0),
        'percent' => $displayPercent,
        'grade' => $adjusted && in_array((string)($row['published_grade'] ?? ''), ['2', '3', '4', '5'], true)
            ? (string)$row['published_grade']
            : (in_array((string)($row['grade'] ?? ''), ['2', '3', '4', '5'], true)
                ? (string)$row['grade']
                : grade_from_percent($displayPercent)),
        'comment' => (string)($adjusted ? ($row['published_comment'] ?? '') : ''),
        'adjusted' => $adjusted,
        'revision' => (int)($row['result_revision'] ?? 0),
        'published_at' => $row['result_published_at'],
    ];
}

$subjectProgress = [];
$subjectBuckets = [];
foreach ($items as $item) {
    $subject = trim((string)($item['subject_name'] ?? ''));
    if ($subject === '') $subject = 'Без предмета';
    $subjectBuckets[$subject][] = (float)$item['percent'];
}
foreach ($subjectBuckets as $subject => $values) {
    $subjectProgress[] = [
        'subject_name' => $subject,
        'average_percent' => round(array_sum($values) / max(1, count($values)), 1),
        'works_count' => count($values),
    ];
}
usort($subjectProgress, static fn(array $a, array $b): int => strcmp($a['subject_name'], $b['subject_name']));

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$currentStart = $now->modify('-30 days');
$previousStart = $now->modify('-60 days');
$current = [];
$previous = [];
foreach ($items as $item) {
    $raw = (string)($item['submitted_at'] ?? '');
    if ($raw === '') continue;
    try {
        $date = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
    } catch (Throwable) {
        continue;
    }
    if ($date >= $currentStart) {
        $current[] = (float)$item['percent'];
    } elseif ($date >= $previousStart) {
        $previous[] = (float)$item['percent'];
    }
}
$currentAverage = $current ? array_sum($current) / count($current) : null;
$previousAverage = $previous ? array_sum($previous) / count($previous) : null;
$trend = ($currentAverage !== null && $previousAverage !== null)
    ? round($currentAverage - $previousAverage, 1)
    : null;

json_response([
    'ok' => true,
    'items' => $items,
    'subject_progress' => $subjectProgress,
    'trend_30_days' => $trend,
    'current_30_days_average' => $currentAverage !== null ? round($currentAverage, 1) : null,
]);
