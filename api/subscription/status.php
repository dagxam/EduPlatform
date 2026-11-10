<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$pdo = app_db();
$schoolId = current_school_id();
$state = subscription_effective_state($pdo, $user);
$usage = subscription_usage_snapshot($pdo, $user, $schoolId);
$plans = subscription_plan_catalog();

$providerConfigured = yookassa_is_configured();
$config = yookassa_config();

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
        'live_checkout_enabled' => $providerConfigured,
        'test_mode' => (bool)$config['test_mode'],
        'recurring_enabled' => (bool)($config['recurring_enabled'] ?? false),
        'webhook_url' => uvoria_app_url() . '/api/subscription/yookassa-webhook.php',
        'message' => $providerConfigured
            ? ((bool)$config['test_mode'] ? 'ЮKassa подключена в тестовом режиме.' : 'ЮKassa подключена.')
            : 'Для приёма оплаты добавьте shopId и секретный ключ ЮKassa на сервере.',
    ],
    'stage' => $providerConfigured ? 'connected' : 'awaiting_credentials',
]);
