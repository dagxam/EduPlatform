<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();

if ($user['role'] === 'admin') {
    $stmt = $pdo->query(
        'SELECT s.id, s.name, s.city, s.slug, s.status,
                COALESCE(su.role, "platform_admin") AS membership_role
         FROM schools s
         LEFT JOIN school_users su ON su.school_id = s.id AND su.user_id = ' . (int)$user['id'] . '
         WHERE s.status = "active"
         ORDER BY s.name COLLATE NOCASE'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.city, s.slug, s.status, su.role AS membership_role
         FROM school_users su
         JOIN schools s ON s.id = su.school_id
         WHERE su.user_id = :user_id AND su.is_active = 1 AND s.status = "active"
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
}

json_response([
    'ok' => true,
    'active_school_id' => current_school_id(),
    'schools' => $stmt->fetchAll(),
]);
