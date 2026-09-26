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

json_response([
    'ok' => true,
    'authenticated' => $user !== null,
    'user' => $user,
    'active_school_id' => $activeSchoolId,
]);
