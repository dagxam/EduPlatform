<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Только главный администратор UROVIA может управлять подписками сотрудников.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$targetUserId = (int)($data['user_id'] ?? 0);
$planCode = (string)($data['plan_code'] ?? '');
$duration = (string)($data['duration'] ?? '30');
$note = trim((string)($data['note'] ?? ''));
$schoolId = require_active_school($user, false);

if ($targetUserId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран сотрудник.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.role
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.user_id = :user_id
       AND su.is_active = 1
       AND u.is_active = 1
       AND u.role <> "student"
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'user_id' => $targetUserId,
]);
$target = $stmt->fetch();
if (!$target) {
    json_response(['ok' => false, 'error' => 'Сотрудник не найден в выбранной школе.'], 404);
}

if ($planCode === 'basic') {
    $pdo->prepare('DELETE FROM subscription_admin_grants WHERE user_id = :user_id')
        ->execute(['user_id' => $targetUserId]);

    audit_event('subscription_admin_grant_removed', 'user', $targetUserId, [
        'school_id' => $schoolId,
    ], $schoolId, (int)$user['id']);

    json_response([
        'ok' => true,
        'message' => 'Ручной PRO/Premium-доступ снят. Будет использоваться обычная подписка пользователя или Базовый тариф.',
    ]);
}

if (!in_array($planCode, ['pro', 'premium'], true)) {
    json_response(['ok' => false, 'error' => 'Можно назначить только PRO или Premium.'], 422);
}

$endsAt = null;
$durationLabels = [
    '30' => '30 дней',
    '90' => '90 дней',
    '180' => '180 дней',
    '365' => '1 год',
    'unlimited' => 'бессрочно',
];
if (!isset($durationLabels[$duration])) {
    json_response(['ok' => false, 'error' => 'Некорректный срок подписки.'], 422);
}
if ($duration !== 'unlimited') {
    $days = (int)$duration;
    $endsAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
}

$stmt = $pdo->prepare(
    'INSERT INTO subscription_admin_grants
     (user_id, plan_code, starts_at, ends_at, note, assigned_by, created_at, updated_at)
     VALUES (:user_id, :plan_code, CURRENT_TIMESTAMP, :ends_at, :note, :assigned_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['user_id'],
        ['plan_code', 'starts_at', 'ends_at', 'note', 'assigned_by', 'updated_at']
    )
);
$stmt->execute([
    'user_id' => $targetUserId,
    'plan_code' => $planCode,
    'ends_at' => $endsAt,
    'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
    'assigned_by' => (int)$user['id'],
]);

audit_event('subscription_admin_grant_set', 'user', $targetUserId, [
    'school_id' => $schoolId,
    'plan_code' => $planCode,
    'duration' => $duration,
    'ends_at' => $endsAt,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'message' => ($planCode === 'premium' ? 'Premium' : 'PRO') . ' назначен на ' . $durationLabels[$duration] . '.',
    'plan_code' => $planCode,
    'ends_at' => $endsAt,
]);
