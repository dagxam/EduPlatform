<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$query = trim((string)($_GET['q'] ?? ''));
$entityType = trim((string)($_GET['entity_type'] ?? ''));

$allowedEntityTypes = [
    '',
    'assignment',
    'question',
    'subject',
    'class',
    'student',
    'teacher',
    'user',
    'school',
    'material',
];
if (!in_array($entityType, $allowedEntityTypes, true)) {
    $entityType = '';
}

$where = ['a.school_id = :school_id'];
$params = ['school_id' => $schoolId];

if (!$manager) {
    $where[] = 'a.user_id = :user_id';
    $params['user_id'] = (int)$user['id'];
}

if ($entityType !== '') {
    if ($entityType === 'material') {
        $where[] = '(a.entity_type = "material" OR a.entity_type = "subject" OR a.event_type LIKE "material_%")';
    } else {
        $where[] = 'a.entity_type = :entity_type';
        $params['entity_type'] = $entityType;
    }
}

if ($query !== '') {
    $where[] = '(
        a.event_type LIKE :query
        OR a.entity_type LIKE :query
        OR CAST(a.entity_id AS TEXT) LIKE :query
        OR COALESCE(u.first_name, "") LIKE :query
        OR COALESCE(u.last_name, "") LIKE :query
        OR COALESCE(u.login_name, "") LIKE :query
        OR COALESCE(u.email, "") LIKE :query
    )';
    $params['query'] = '%' . $query . '%';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE ' . $whereSql
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql =
    'SELECT
        a.id,
        a.event_type,
        a.entity_type,
        a.entity_id,
        a.metadata_json,
        a.created_at,
        a.user_id,
        u.first_name,
        u.last_name,
        u.login_name,
        u.role
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE ' . $whereSql . '
     ORDER BY a.id DESC
     LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

function history_safe_metadata(mixed $value): mixed
{
    if (!is_array($value)) return $value;

    $safe = [];
    foreach ($value as $key => $item) {
        $name = strtolower((string)$key);
        if (
            str_contains($name, 'password') ||
            str_contains($name, 'token') ||
            str_contains($name, 'secret') ||
            str_contains($name, 'pin')
        ) {
            $safe[$key] = '[скрыто]';
            continue;
        }
        $safe[$key] = history_safe_metadata($item);
    }
    return $safe;
}

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $metadata = null;
    if (!empty($row['metadata_json'])) {
        $decoded = json_decode((string)$row['metadata_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $metadata = history_safe_metadata($decoded);
        }
    }

    $firstName = trim((string)($row['first_name'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $actorName = trim($lastName . ' ' . $firstName);
    if ($actorName === '') {
        $actorName = trim((string)($row['login_name'] ?? ''));
    }
    if ($actorName === '') {
        $actorName = 'Системное действие';
    }

    $items[] = [
        'id' => (int)$row['id'],
        'event_type' => (string)$row['event_type'],
        'entity_type' => $row['entity_type'] !== null ? (string)$row['entity_type'] : null,
        'entity_id' => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
        'metadata' => $metadata,
        'created_at' => (string)$row['created_at'],
        'actor' => [
            'id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'name' => $actorName,
            'role' => $row['role'] !== null ? (string)$row['role'] : null,
        ],
    ];
}

json_response([
    'ok' => true,
    'scope' => $manager ? 'school' : 'own',
    'items' => $items,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
