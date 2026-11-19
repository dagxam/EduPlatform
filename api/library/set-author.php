<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Изменять автора материала может только главный администратор UROVIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$itemId = (int)($data['item_id'] ?? 0);
$authorId = (int)($data['author_id'] ?? 0);
if ($itemId < 1 || $authorId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите материал и нового автора.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT li.id, li.source_school_id, li.source_assignment_id, li.submitted_by,
            li.title_snapshot, sch.name AS school_name
     FROM library_items li
     JOIN schools sch ON sch.id = li.source_school_id
     WHERE li.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch();
if (!$item) {
    json_response(['ok' => false, 'error' => 'Материал библиотеки не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.middle_name
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :user_id
       AND su.is_active = 1
       AND su.can_teach = 1
       AND u.is_active = 1
       AND u.role IN ("admin", "teacher")
     LIMIT 1'
);
$stmt->execute([
    'school_id' => (int)$item['source_school_id'],
    'user_id' => $authorId,
]);
$author = $stmt->fetch();
if (!$author) {
    json_response([
        'ok' => false,
        'error' => 'Выбранный пользователь не является действующим учителем этой школы.',
    ], 422);
}

$oldAuthorId = $item['submitted_by'] !== null ? (int)$item['submitted_by'] : null;
if ($oldAuthorId !== $authorId) {
    $stmt = $pdo->prepare(
        'UPDATE library_items
         SET submitted_by = :author_id,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'author_id' => $authorId,
        'id' => $itemId,
    ]);

    audit_event('library_author_changed', 'library_item', $itemId, [
        'assignment_id' => (int)$item['source_assignment_id'],
        'old_author_id' => $oldAuthorId,
        'new_author_id' => $authorId,
        'title' => (string)$item['title_snapshot'],
    ], (int)$item['source_school_id'], (int)$user['id']);
}

json_response([
    'ok' => true,
    'item_id' => $itemId,
    'author' => [
        'id' => (int)$author['id'],
        'first_name' => (string)$author['first_name'],
        'last_name' => (string)$author['last_name'],
        'middle_name' => (string)($author['middle_name'] ?? ''),
    ],
    'message' => 'Автор материала изменён.',
]);
