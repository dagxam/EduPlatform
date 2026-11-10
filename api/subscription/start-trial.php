<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$pdo = app_db();
$userId = (int)$user['id'];

$stmt = $pdo->prepare(
    'SELECT plan_code, status, trial_ends_at, ends_at
     FROM user_subscriptions
     WHERE user_id = :user_id
     LIMIT 1'
);
$stmt->execute(['user_id' => $userId]);
$current = $stmt->fetch();

if ($current && !empty($current['trial_ends_at'])) {
    json_response(['ok' => false, 'error' => 'Пробный период PRO уже использован.'], 409);
}
if ($current && in_array((string)$current['status'], ['trial', 'active'], true)) {
    json_response(['ok' => false, 'error' => 'У вас уже активен платный или пробный тариф.'], 409);
}

$trialEndsAt = gmdate('Y-m-d H:i:s', time() + 14 * 86400);
$stmt = $pdo->prepare(
    'INSERT INTO user_subscriptions
     (user_id, plan_code, status, starts_at, trial_ends_at, auto_renew, billing_period, created_at, updated_at)
     VALUES (:user_id, "pro", "trial", CURRENT_TIMESTAMP, :trial_ends_at, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['user_id'],
        ['plan_code', 'status', 'starts_at', 'trial_ends_at', 'auto_renew', 'billing_period', 'updated_at']
    )
);
$stmt->execute([
    'user_id' => $userId,
    'trial_ends_at' => $trialEndsAt,
]);

audit_event('subscription_trial_started', 'user', $userId, [
    'plan_code' => 'pro',
    'trial_ends_at' => $trialEndsAt,
], current_school_id(), $userId);

json_response([
    'ok' => true,
    'message' => 'PRO активирован на 14 дней без банковской карты.',
    'trial_ends_at' => $trialEndsAt,
]);
