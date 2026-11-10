<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$planCode = (string)($data['plan_code'] ?? '');
$period = (string)($data['billing_period'] ?? '');
$plans = subscription_plan_catalog();

if (!in_array($planCode, ['pro', 'premium'], true) || !isset($plans[$planCode])) {
    json_response(['ok' => false, 'error' => 'Недоступный тариф.'], 422);
}
if (!in_array($period, ['month', 'year'], true)) {
    json_response(['ok' => false, 'error' => 'Недоступный период оплаты.'], 422);
}

$plan = $plans[$planCode];
$amountMinor = $period === 'year'
    ? (int)$plan['yearly_price_minor']
    : (int)$plan['monthly_price_minor'];

if ($amountMinor < 1) {
    json_response(['ok' => false, 'error' => 'Некорректная стоимость тарифа.'], 422);
}

$shopId = trim((string)(getenv('YOO_KASSA_SHOP_ID') ?: ''));
$secret = trim((string)(getenv('YOO_KASSA_SECRET_KEY') ?: ''));

if ($shopId === '' || $secret === '') {
    json_response([
        'ok' => false,
        'error' => 'Онлайн-оплата пока не подключена. Тарифная система уже готова, но реквизиты платёжного провайдера ещё не настроены.',
        'code' => 'PAYMENT_PROVIDER_NOT_CONFIGURED',
    ], 503);
}

// Stage 1 intentionally stops before contacting the payment provider.
// This prevents accidental real charges until webhook, receipts and refunds
// have been verified in the provider's test environment.
json_response([
    'ok' => false,
    'error' => 'Платёжный модуль находится в безопасном тестовом режиме. Реальные списания ещё не включены.',
    'code' => 'PAYMENT_CHECKOUT_PREVIEW_ONLY',
    'plan_code' => $planCode,
    'billing_period' => $period,
    'amount_minor' => $amountMinor,
    'currency' => 'RUB',
], 409);
