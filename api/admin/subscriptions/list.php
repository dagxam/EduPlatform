<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Только главный администратор UROVIA может управлять подписками сотрудников.'], 403);
}

$schoolId = require_active_school($user, false);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.role, u.is_platform_admin,
            su.role AS school_role, su.can_teach,
            g.plan_code AS grant_plan_code, g.starts_at AS grant_starts_at, g.ends_at AS grant_ends_at,
            g.note AS grant_note, g.assigned_by
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     LEFT JOIN subscription_admin_grants g ON g.user_id = u.id
     WHERE su.school_id = :school_id
       AND su.is_active = 1
       AND u.is_active = 1
       AND u.role <> "student"
     ORDER BY
       CASE su.role WHEN "owner" THEN 1 WHEN "school_admin" THEN 2 ELSE 3 END,
       u.last_name, u.first_name'
);
$stmt->execute(['school_id' => $schoolId]);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $target = [
        'id' => (int)$row['id'],
        'role' => (string)$row['role'],
        'is_platform_admin' => (int)$row['is_platform_admin'],
    ];
    $effective = subscription_effective_state($pdo, $target);
    $row['effective_plan_code'] = $effective['plan_code'];
    $row['effective_plan_name'] = $effective['plan']['name'] ?? $effective['plan_code'];
    $row['effective_status'] = $effective['status'];
    $row['effective_ends_at'] = $effective['ends_at'] ?? null;
}
unset($row);

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'staff' => $rows,
]);
