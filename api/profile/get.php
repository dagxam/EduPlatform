<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$targetId = max(1, (int)($_GET['user_id'] ?? $user['id']));
$self = $targetId === (int)$user['id'];
$schoolId = current_school_id();
$canEdit = $self;

if (!$self) {
    if ($schoolId === null || !can_manage_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет доступа к профилю сотрудника.'], 403);
    }
    $memberStmt = $pdo->prepare(
        'SELECT role, can_teach FROM school_users
         WHERE school_id = :school_id AND user_id = :user_id AND is_active = 1
         LIMIT 1'
    );
    $memberStmt->execute(['school_id' => $schoolId, 'user_id' => $targetId]);
    $member = $memberStmt->fetch();
    if (!$member) {
        json_response(['ok' => false, 'error' => 'Сотрудник не найден в выбранной школе.'], 404);
    }
    $canEdit = is_platform_admin($user) || (string)$member['role'] === 'teacher';
}

$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, middle_name, phone, avatar_name,
            email, login_name, role, is_platform_admin, must_change_password,
            credentials_sent_at, created_at
     FROM users
     WHERE id = :id AND role IN ("admin", "teacher") AND is_active = 1
     LIMIT 1'
);
$stmt->execute(['id' => $targetId]);
$profile = $stmt->fetch();
if (!$profile) {
    json_response(['ok' => false, 'error' => 'Профиль сотрудника не найден.'], 404);
}

$membershipsStmt = $pdo->prepare(
    'SELECT s.id, s.name, s.city, su.role, su.can_teach
     FROM school_users su
     JOIN schools s ON s.id = su.school_id
     WHERE su.user_id = :user_id AND su.is_active = 1 AND s.status = "active"
     ORDER BY s.name'
);
$membershipsStmt->execute(['user_id' => $targetId]);
$schools = $membershipsStmt->fetchAll();

$subjects = [];
$classes = [];
if ($schoolId !== null) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT s.id, s.name
         FROM teacher_subjects ts
         JOIN subjects s ON s.id = ts.subject_id
         WHERE ts.school_id = :school_id AND ts.teacher_id = :teacher_id
         ORDER BY s.name'
    );
    $stmt->execute(['school_id' => $schoolId, 'teacher_id' => $targetId]);
    $subjects = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, COALESCE(c.display_name, c.name) AS name,
                c.academic_year, s.name AS subject_name
         FROM teacher_classes tc
         JOIN classes c ON c.id = tc.class_id
         JOIN subjects s ON s.id = tc.subject_id
         WHERE tc.school_id = :school_id AND tc.teacher_id = :teacher_id
         ORDER BY name, s.name'
    );
    $stmt->execute(['school_id' => $schoolId, 'teacher_id' => $targetId]);
    $classes = $stmt->fetchAll();
}

$activeMembership = null;
if ($schoolId !== null) {
    foreach ($schools as $school) {
        if ((int)$school['id'] === $schoolId) {
            $activeMembership = $school;
            break;
        }
    }
}

$profile['avatar_url'] = !empty($profile['avatar_name'])
    ? './api/profile/avatar.php?user_id=' . $targetId . '&v=' . rawurlencode((string)$profile['avatar_name'])
    : null;
unset($profile['avatar_name']);

json_response([
    'ok' => true,
    'profile' => $profile,
    'active_school' => $activeMembership,
    'schools' => $schools,
    'subjects' => $subjects,
    'classes' => $classes,
    'permissions' => [
        'self' => $self,
        'can_edit_basic' => $canEdit,
        'can_manage_access' => !$self && $schoolId !== null && can_manage_school($user, $schoolId),
        'platform_admin' => is_platform_admin($user),
    ],
]);
