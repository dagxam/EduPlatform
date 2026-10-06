<?php
declare(strict_types=1);

function result_attempt_for_staff(PDO $pdo, array $user, int $schoolId, int $attemptId): array
{
    $stmt = $pdo->prepare(
        'SELECT at.*, a.school_id, a.teacher_id, a.title AS assignment_title, a.subject_id,
                s.name AS subject_name,
                u.first_name AS student_first_name, u.last_name AS student_last_name,
                COALESCE(c.display_name, c.name) AS class_name
         FROM attempts at
         JOIN assignments a ON a.id = at.assignment_id
         JOIN users u ON u.id = at.student_id
         LEFT JOIN subjects s ON s.id = a.subject_id
         LEFT JOIN class_students cs ON cs.student_id = at.student_id
         LEFT JOIN classes c ON c.id = cs.class_id
         WHERE at.id = :attempt_id AND a.school_id = :school_id
         LIMIT 1'
    );
    $stmt->execute([
        'attempt_id' => $attemptId,
        'school_id' => $schoolId,
    ]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        json_response(['ok' => false, 'error' => 'Результат не найден в выбранной школе.'], 404);
    }

    if (!can_manage_school($user, $schoolId) && (int)$attempt['teacher_id'] !== (int)$user['id']) {
        json_response(['ok' => false, 'error' => 'Нет доступа к результату этого задания.'], 403);
    }

    if ((string)$attempt['status'] === 'in_progress') {
        json_response(['ok' => false, 'error' => 'Попытка ещё не завершена.'], 409);
    }

    return $attempt;
}

function result_has_unpublished_draft(array $row): bool
{
    if ($row['manual_score'] === null) return false;
    $manualAt = trim((string)($row['manual_updated_at'] ?? ''));
    $publishedAt = trim((string)($row['result_published_at'] ?? ''));
    return $publishedAt === '' || ($manualAt !== '' && $manualAt > $publishedAt);
}

function result_display_payload(array $row): array
{
    $published = $row['published_score'] !== null;
    $percent = (float)($published ? $row['published_percent'] : ($row['percent'] ?? 0));
    $grade = $published && in_array((string)($row['published_grade'] ?? ''), ['2', '3', '4', '5'], true)
        ? (string)$row['published_grade']
        : (in_array((string)($row['grade'] ?? ''), ['2', '3', '4', '5'], true)
            ? (string)$row['grade']
            : grade_from_percent($percent));

    return [
        'score' => (float)($published ? $row['published_score'] : ($row['score'] ?? 0)),
        'max_score' => (float)($row['max_score'] ?? 0),
        'percent' => $percent,
        'grade' => $grade,
        'comment' => (string)($published ? ($row['published_comment'] ?? '') : ''),
        'published_override' => $published,
        'revision' => (int)($row['result_revision'] ?? 0),
        'published_at' => $row['result_published_at'] ?? null,
    ];
}
