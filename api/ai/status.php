<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$state = subscription_effective_state($pdo, $user);
$usage = subscription_usage_snapshot($pdo, $user, $schoolId);
$limit = is_platform_admin($user) ? null : (int)($state['plan']['ai_monthly_limit'] ?? 0);
$used = (int)($usage['ai_generations'] ?? 0);

json_response([
    'ok' => true,
    'configured' => ai_is_configured(),
    'plan_code' => (string)($state['plan_code'] ?? ''),
    'limit' => $limit,
    'used' => $used,
    'remaining' => $limit === null ? null : max(0, $limit - $used),
    'available' => is_platform_admin($user) || $limit > 0,
]);
