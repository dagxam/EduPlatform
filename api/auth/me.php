<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = current_user();
$activeSchoolId = null;

if ($user !== null && in_array($user['role'], ['admin', 'teacher'], true) && !is_platform_admin($user)) {
    $activeSchoolId = ensure_staff_school_context($user);
} elseif ($user !== null && ($user['role'] ?? '') === 'student') {
    $activeSchoolId = current_school_id();

    if ($activeSchoolId === null) {
        $stmt = app_db()->prepare(
            'SELECT c.school_id
             FROM class_students cs
             JOIN classes c ON c.id = cs.class_id
             WHERE cs.student_id = :student_id
             LIMIT 1'
        );
        $stmt->execute(['student_id' => (int)$user['id']]);
        $resolvedSchoolId = (int)($stmt->fetchColumn() ?: 0);
        if ($resolvedSchoolId > 0) {
            $activeSchoolId = $resolvedSchoolId;
            $_SESSION['active_school_id'] = $resolvedSchoolId;
        }
    }
} elseif ($user !== null) {
    $activeSchoolId = current_school_id();
}

if ($user !== null && $activeSchoolId !== null && !is_platform_admin($user) && ($user['role'] ?? '') !== 'student') {
    $user['school_role'] = school_membership_role((int)$user['id'], $activeSchoolId);
    $user['can_teach'] = can_teach_school($user, $activeSchoolId);
    $user['can_manage_school'] = can_manage_school($user, $activeSchoolId);
} elseif ($user !== null) {
    $user['school_role'] = ($user['role'] ?? '') === 'student' ? 'student' : null;
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
    'branding' => $activeSchoolId !== null
        ? school_branding($activeSchoolId)
        : ['theme_color' => '#1d68f0'],
]);
