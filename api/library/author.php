<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
require_active_school($user, false);

$authorId = (int)($_GET['author_id'] ?? 0);
if ($authorId < 1) {
    json_response(['ok' => false, 'error' => 'Автор не выбран.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, middle_name, avatar_name
     FROM users
     WHERE id = :id AND role IN ("admin", "teacher") AND is_active = 1
     LIMIT 1'
);
$stmt->execute(['id' => $authorId]);
$author = $stmt->fetch();
if (!$author) {
    json_response(['ok' => false, 'error' => 'Автор не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS materials_count,
            COALESCE(SUM((
              SELECT COUNT(*) FROM library_imports imp WHERE imp.library_item_id = li.id
            )), 0) AS usage_count,
            COALESCE(SUM((
              SELECT COUNT(*) FROM library_reactions lr WHERE lr.library_item_id = li.id AND lr.liked = 1
            )), 0) AS likes_count,
            ROUND(AVG((
              SELECT AVG(lr.rating) FROM library_reactions lr
              WHERE lr.library_item_id = li.id AND lr.rating IS NOT NULL
            )), 1) AS rating_average
     FROM library_items li
     WHERE li.submitted_by = :author_id AND li.status = "published"'
);
$stmt->execute(['author_id' => $authorId]);
$stats = $stmt->fetch() ?: [];

$subjects = $pdo->prepare(
    'SELECT s.name, COUNT(*) AS materials_count
     FROM library_items li
     LEFT JOIN subjects s ON s.id = li.subject_id
     WHERE li.submitted_by = :author_id AND li.status = "published"
     GROUP BY s.id, s.name
     ORDER BY materials_count DESC, s.name
     LIMIT 8'
);
$subjects->execute(['author_id' => $authorId]);

$materials = $pdo->prepare(
    'SELECT li.id, li.title_snapshot, li.questions_count_snapshot, li.published_at,
            s.name AS subject_name,
            (SELECT COUNT(*) FROM library_imports imp WHERE imp.library_item_id = li.id) AS usage_count,
            (SELECT COUNT(*) FROM library_reactions lr WHERE lr.library_item_id = li.id AND lr.liked = 1) AS likes_count,
            (SELECT ROUND(AVG(lr.rating), 1) FROM library_reactions lr WHERE lr.library_item_id = li.id AND lr.rating IS NOT NULL) AS rating_average
     FROM library_items li
     LEFT JOIN subjects s ON s.id = li.subject_id
     WHERE li.submitted_by = :author_id AND li.status = "published"
     ORDER BY li.published_at DESC, li.id DESC
     LIMIT 12'
);
$materials->execute(['author_id' => $authorId]);

json_response([
    'ok' => true,
    'author' => [
        'id' => (int)$author['id'],
        'first_name' => (string)$author['first_name'],
        'last_name' => (string)$author['last_name'],
        'middle_name' => (string)($author['middle_name'] ?? ''),
        'avatar_name' => $author['avatar_name'],
    ],
    'stats' => [
        'materials_count' => (int)($stats['materials_count'] ?? 0),
        'usage_count' => (int)($stats['usage_count'] ?? 0),
        'likes_count' => (int)($stats['likes_count'] ?? 0),
        'rating_average' => $stats['rating_average'] !== null ? (float)$stats['rating_average'] : null,
    ],
    'subjects' => $subjects->fetchAll(),
    'materials' => $materials->fetchAll(),
]);
