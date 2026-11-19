<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Изменять автора материала может только главный администратор UROVIA.'], 403);
}

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId < 1) {
    json_response(['ok' => false, 'error' => 'Материал не выбран.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT li.id, li.source_school_id, li.submitted_by, li.title_snapshot,
            sch.name AS school_name
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
    'SELECT u.id, u.first_name, u.last_name, u.middle_name, u.role,
            su.role AS school_role
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.is_active = 1
       AND su.can_teach = 1
       AND u.is_active = 1
       AND u.role IN ("admin", "teacher")
     ORDER BY u.last_name, u.first_name, u.middle_name'
);
$stmt->execute(['school_id' => (int)$item['source_school_id']]);

json_response([
    'ok' => true,
    'item' => [
        'id' => (int)$item['id'],
        'title' => (string)$item['title_snapshot'],
        'school_id' => (int)$item['source_school_id'],
        'school_name' => (string)$item['school_name'],
        'author_id' => $item['submitted_by'] !== null ? (int)$item['submitted_by'] : null,
    ],
    'authors' => array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'first_name' => (string)$row['first_name'],
            'last_name' => (string)$row['last_name'],
            'middle_name' => (string)($row['middle_name'] ?? ''),
            'role' => (string)$row['role'],
            'school_role' => (string)($row['school_role'] ?? ''),
        ];
    }, $stmt->fetchAll()),
]);
