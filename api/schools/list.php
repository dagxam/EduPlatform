<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();

if (is_platform_admin($user)) {
    $stmt = $pdo->query(
        'SELECT s.id, s.name, s.city, s.slug, s.status,
                ua.id AS admin_id, ua.first_name AS admin_first_name,
                ua.last_name AS admin_last_name, ua.email AS admin_email
         FROM schools s
         LEFT JOIN school_users su
           ON su.school_id = s.id AND su.role = "school_admin" AND su.is_active = 1
         LEFT JOIN users ua ON ua.id = su.user_id
         WHERE s.status = "active"
         ORDER BY s.name COLLATE NOCASE'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.city, s.slug, s.status,
                me.role AS membership_role,
                ua.id AS admin_id, ua.first_name AS admin_first_name,
                ua.last_name AS admin_last_name, ua.email AS admin_email
         FROM school_users me
         JOIN schools s ON s.id = me.school_id
         LEFT JOIN school_users su
           ON su.school_id = s.id AND su.role = "school_admin" AND su.is_active = 1
         LEFT JOIN users ua ON ua.id = su.user_id
         WHERE me.user_id = :user_id
           AND me.is_active = 1
           AND s.status = "active"
         GROUP BY s.id
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
}

$schools = $stmt->fetchAll();
$activeSchoolId = current_school_id();
if ($activeSchoolId === null && count($schools) === 1 && !is_platform_admin($user)) {
    $activeSchoolId = (int)$schools[0]['id'];
    $_SESSION['active_school_id'] = $activeSchoolId;
}

json_response([
    'ok' => true,
    'active_school_id' => $activeSchoolId,
    'is_platform_admin' => is_platform_admin($user),
    'schools' => $schools,
]);
