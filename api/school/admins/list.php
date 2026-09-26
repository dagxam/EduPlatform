<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);

$stmt = app_db()->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.is_active,
            su.created_at, su.can_teach
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.role = "school_admin"
       AND su.is_active = 1
     ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE'
);
$stmt->execute(['school_id' => $schoolId]);

json_response([
    'ok' => true,
    'admins' => $stmt->fetchAll(),
    'can_edit_admin_accounts' => is_platform_admin($user),
]);
