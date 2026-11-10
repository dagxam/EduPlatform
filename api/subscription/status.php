<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = current_school_id();
$state = subscription_effective_state($pdo, $user);
$usage = subscription_usage_snapshot($pdo, $user, $schoolId);
$plans = subscription_plan_catalog();

$providerConfigured = trim((string)(getenv('YOO_KASSA_SHOP_ID') ?: '')) !== ''
    && trim((string)(getenv('YOO_KASSA_SECRET_KEY') ?: '')) !== '';

json_response([
    'ok' => true,
    'current' => $state,
    'usage' => $usage,
    'plans' => array_values($plans),
    'trial_available' => !in_array($state['status'], ['trial', 'active'], true)
        && empty($state['trial_ends_at']),
    'payment' => [
        'provider' => 'yookassa',
        'configured' => $providerConfigured,
        'live_checkout_enabled' => false,
        'message' => $providerConfigured
            ? 'Платёжный провайдер настроен. Боевой checkout будет включён отдельным этапом после проверки webhook и чеков.'
            : 'Для приёма оплаты нужно добавить реквизиты ЮKassa в переменные окружения.',
    ],
    'stage' => 'preview',
]);
