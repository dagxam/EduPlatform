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
$receiptEmail = trim((string)($data['receipt_email'] ?? ''));
$autoRenew = privacy_truthy($data['auto_renew'] ?? false);
$plans = subscription_plan_catalog();

if (!in_array($planCode, ['pro', 'premium'], true) || !isset($plans[$planCode])) {
    json_response(['ok' => false, 'error' => 'Недоступный тариф.'], 422);
}
if (!in_array($period, ['month', 'year'], true)) {
    json_response(['ok' => false, 'error' => 'Недоступный период оплаты.'], 422);
}
if ($receiptEmail === '' || !filter_var($receiptEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email для подтверждения оплаты и чека.'], 422);
}
if (!yookassa_is_configured()) {
    json_response([
        'ok' => false,
        'error' => 'ЮKassa ещё не подключена: добавьте shopId и секретный ключ после завершения регистрации магазина.',
        'code' => 'PAYMENT_PROVIDER_NOT_CONFIGURED',
    ], 503);
}

$plan = $plans[$planCode];
$amountMinor = $period === 'year' ? (int)$plan['yearly_price_minor'] : (int)$plan['monthly_price_minor'];
if ($amountMinor < 1) {
    json_response(['ok' => false, 'error' => 'Некорректная стоимость тарифа.'], 422);
}

$pdo = app_db();
$idempotenceKey = bin2hex(random_bytes(24));
$stmt = $pdo->prepare(
    'INSERT INTO payment_orders
     (user_id, plan_code, billing_period, amount_minor, currency, provider, customer_email, status, idempotence_key, created_at)
     VALUES (:user_id, :plan_code, :billing_period, :amount_minor, "RUB", "yookassa", :customer_email, "created", :idempotence_key, CURRENT_TIMESTAMP)'
);
$stmt->execute([
    'user_id' => (int)$user['id'],
    'plan_code' => $planCode,
    'billing_period' => $period,
    'amount_minor' => $amountMinor,
    'customer_email' => $receiptEmail,
    'idempotence_key' => $idempotenceKey,
]);
$orderId = (int)$pdo->lastInsertId();

$amountValue = number_format($amountMinor / 100, 2, '.', '');
$description = 'UROVIA ' . (string)$plan['name'] . ' — ' . ($period === 'year' ? '1 год' : '1 месяц');

$payload = [
    'amount' => ['value' => $amountValue, 'currency' => 'RUB'],
    'capture' => true,
    'confirmation' => [
        'type' => 'redirect',
        'return_url' => uvoria_app_url() . '/payment-return.html?order_id=' . $orderId,
    ],
    'description' => $description,
    'save_payment_method' => $autoRenew,
    'metadata' => [
        'order_id' => (string)$orderId,
        'user_id' => (string)(int)$user['id'],
        'plan_code' => $planCode,
        'billing_period' => $period,
        'receipt_email' => $receiptEmail,
    ],
];

try {
    $payment = yookassa_request('POST', 'payments', $payload, $idempotenceKey);
} catch (Throwable $error) {
    $pdo->prepare('UPDATE payment_orders SET status = "failed" WHERE id = :id')->execute(['id' => $orderId]);
    json_response(['ok' => false, 'error' => $error->getMessage()], 502);
}

$paymentId = trim((string)($payment['id'] ?? ''));
$checkoutUrl = trim((string)($payment['confirmation']['confirmation_url'] ?? ''));
$status = (string)($payment['status'] ?? 'pending');
if ($paymentId === '' || $checkoutUrl === '') {
    $pdo->prepare('UPDATE payment_orders SET status = "failed" WHERE id = :id')->execute(['id' => $orderId]);
    json_response(['ok' => false, 'error' => 'ЮKassa не вернула ссылку на оплату.'], 502);
}

$localStatus = in_array($status, ['pending', 'waiting_for_capture'], true) ? 'pending' : 'created';
$pdo->prepare(
    'UPDATE payment_orders
     SET provider_payment_id = :payment_id, status = :status, checkout_url = :checkout_url
     WHERE id = :id'
)->execute([
    'payment_id' => $paymentId,
    'status' => $localStatus,
    'checkout_url' => $checkoutUrl,
    'id' => $orderId,
]);

audit_event('subscription_checkout_created', 'payment_order', $orderId, [
    'plan_code' => $planCode,
    'billing_period' => $period,
    'amount_minor' => $amountMinor,
    'auto_renew_requested' => $autoRenew,
], current_school_id(), (int)$user['id']);

json_response([
    'ok' => true,
    'order_id' => $orderId,
    'checkout_url' => $checkoutUrl,
    'provider' => 'yookassa',
]);
