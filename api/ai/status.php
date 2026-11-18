<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = require_active_school($user, false);
$state = subscription_effective_state($pdo, $user);
$usage = subscription_usage_snapshot($pdo, $user, $schoolId);

json_response([
    'ok' => true,
    'configured' => ai_is_configured(),
    'provider' => 'groq',
    'model' => (string)ai_config()['model'],
    'plan_code' => (string)($state['plan_code'] ?? ''),
    'used' => (int)($usage['ai_generations'] ?? 0),
    'available' => true,
    'free_access' => true,
]);
