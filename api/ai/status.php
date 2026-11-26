<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$state = subscription_effective_state($pdo, $user);
$usage = subscription_usage_snapshot($pdo, $user, $schoolId);
$planCode = (string)($state['plan_code'] ?? '');
$isPlatformAdmin = is_platform_admin($user);
$available = ai_feature_enabled() && ($isPlatformAdmin || (!empty($state['access_active']) && in_array($planCode, ['pro', 'premium'], true)));
$limit = $isPlatformAdmin ? null : (int)($state['plan']['ai_monthly_limit'] ?? 0);
$used = (int)($usage['ai_generations'] ?? 0);

json_response([
    'ok' => true,
    'configured' => ai_is_configured(),
    'provider' => ai_config()['provider'],
    'model' => (string)ai_config()['model'],
    'plan_code' => $planCode,
    'used' => $used,
    'limit' => $limit,
    'remaining' => $limit === null ? null : max(0, $limit - $used),
    'available' => $available,
    'paper_available' => $available && !empty($state['plan']['features']['paper_tests']),
    'paid_feature' => ai_feature_enabled(),
]);
