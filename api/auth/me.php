<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = current_user();
$activeSchoolId = null;

if ($user !== null && in_array($user['role'], ['admin', 'teacher'], true) && !is_platform_admin($user)) {
    $activeSchoolId = ensure_staff_school_context($user);
} elseif ($user !== null) {
    $activeSchoolId = current_school_id();
}

if ($user !== null && $activeSchoolId !== null && !is_platform_admin($user)) {
    $user['school_role'] = school_membership_role((int)$user['id'], $activeSchoolId);
    $user['can_teach'] = can_teach_school($user, $activeSchoolId);
    $user['can_manage_school'] = can_manage_school($user, $activeSchoolId);
} elseif ($user !== null) {
    $user['school_role'] = null;
    $user['can_teach'] = false;
    $user['can_manage_school'] = is_platform_admin($user);
}

if ($user !== null) {
    $user['can_manage_admins'] = is_platform_admin($user);
}

json_response([
    'ok' => true,
    'authenticated' => $user !== null,
    'user' => $user,
    'active_school_id' => $activeSchoolId,
]);
