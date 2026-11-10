<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
if (!can_manage_school($user, $schoolId) && !can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$itemId = (int)($data['item_id'] ?? 0);
if ($itemId < 1) {
    json_response(['ok' => false, 'error' => 'Материал не выбран.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare('SELECT id FROM library_items WHERE id = :id AND status = "published" LIMIT 1');
$stmt->execute(['id' => $itemId]);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Материал библиотеки не найден.'], 404);
}

$userId = (int)$user['id'];
$currentStmt = $pdo->prepare(
    'SELECT liked, rating FROM library_reactions
     WHERE library_item_id = :item_id AND user_id = :user_id
     LIMIT 1'
);
$currentStmt->execute(['item_id' => $itemId, 'user_id' => $userId]);
$current = $currentStmt->fetch() ?: ['liked' => 0, 'rating' => null];

$liked = array_key_exists('liked', $data)
    ? ((bool)$data['liked'] ? 1 : 0)
    : (int)$current['liked'];
$rating = array_key_exists('rating', $data)
    ? (($data['rating'] === null || $data['rating'] === '') ? null : (int)$data['rating'])
    : ($current['rating'] !== null ? (int)$current['rating'] : null);

if ($rating !== null && ($rating < 1 || $rating > 5)) {
    json_response(['ok' => false, 'error' => 'Оценка должна быть от 1 до 5.'], 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO library_reactions
     (library_item_id, user_id, liked, rating, created_at, updated_at)
     VALUES (:item_id, :user_id, :liked, :rating, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['library_item_id', 'user_id'],
        ['liked', 'rating', 'updated_at']
    )
);
$stmt->execute([
    'item_id' => $itemId,
    'user_id' => $userId,
    'liked' => $liked,
    'rating' => $rating,
]);

$stats = $pdo->prepare(
    'SELECT
       SUM(CASE WHEN liked = 1 THEN 1 ELSE 0 END) AS likes_count,
       ROUND(AVG(rating), 1) AS rating_average,
       SUM(CASE WHEN rating IS NOT NULL THEN 1 ELSE 0 END) AS ratings_count
     FROM library_reactions
     WHERE library_item_id = :item_id'
);
$stats->execute(['item_id' => $itemId]);
$row = $stats->fetch() ?: [];

audit_event('library_item_reacted', 'library_item', $itemId, [
    'liked' => $liked,
    'rating' => $rating,
], $schoolId, $userId);

json_response([
    'ok' => true,
    'liked' => $liked,
    'rating' => $rating,
    'likes_count' => (int)($row['likes_count'] ?? 0),
    'rating_average' => $row['rating_average'] !== null ? (float)$row['rating_average'] : null,
    'ratings_count' => (int)($row['ratings_count'] ?? 0),
]);
