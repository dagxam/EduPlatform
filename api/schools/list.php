<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();

if (is_platform_admin($user)) {
    $stmt = $pdo->query(
        'SELECT s.id, s.name, s.city, s.slug, s.status, s.theme_color,
                COUNT(DISTINCT CASE WHEN su.role = "school_admin" AND su.is_active = 1 THEN su.user_id END) AS admin_count
         FROM schools s
         LEFT JOIN school_users su ON su.school_id = s.id
         WHERE s.status = "active"
         GROUP BY s.id
         ORDER BY s.name COLLATE NOCASE'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.city, s.slug, s.status,
                me.role AS membership_role,
                COUNT(DISTINCT CASE WHEN su.role = "school_admin" AND su.is_active = 1 THEN su.user_id END) AS admin_count
         FROM school_users me
         JOIN schools s ON s.id = me.school_id
         LEFT JOIN school_users su ON su.school_id = s.id
         WHERE me.user_id = :user_id
           AND me.is_active = 1
           AND s.status = "active"
         GROUP BY s.id, me.role
         ORDER BY s.name COLLATE NOCASE'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
}

$schools = $stmt->fetchAll();

if (!is_platform_admin($user)) {
    ensure_staff_school_context($user);
}
$activeSchoolId = current_school_id();

json_response([
    'ok' => true,
    'active_school_id' => $activeSchoolId,
    'is_platform_admin' => is_platform_admin($user),
    'schools' => $schools,
]);
