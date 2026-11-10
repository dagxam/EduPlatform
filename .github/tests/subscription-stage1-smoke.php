<?php
declare(strict_types=1);

$files = [
    'bootstrap' => file_get_contents(__DIR__ . '/../../api/bootstrap.php'),
    'index' => file_get_contents(__DIR__ . '/../../index.html'),
    'app' => file_get_contents(__DIR__ . '/../../app.js'),
    'sqlite' => file_get_contents(__DIR__ . '/../../database/schema.sql'),
    'mysql' => file_get_contents(__DIR__ . '/../../database/schema.mysql.sql'),
    'status' => file_get_contents(__DIR__ . '/../../api/subscription/status.php'),
    'trial' => file_get_contents(__DIR__ . '/../../api/subscription/start-trial.php'),
    'checkout' => file_get_contents(__DIR__ . '/../../api/subscription/create-checkout.php'),
];

foreach ($files as $name => $content) {
    if ($content === false) {
        fwrite(STDERR, "Subscription smoke failed: cannot read {$name}.\n");
        exit(1);
    }
}

$checks = [
    [$files['sqlite'], 'CREATE TABLE IF NOT EXISTS user_subscriptions', 'SQLite subscription table'],
    [$files['sqlite'], 'CREATE TABLE IF NOT EXISTS payment_orders', 'SQLite payment orders'],
    [$files['mysql'], 'CREATE TABLE IF NOT EXISTS user_subscriptions', 'MySQL subscription table'],
    [$files['mysql'], 'CREATE TABLE IF NOT EXISTS payment_orders', 'MySQL payment orders'],
    [$files['bootstrap'], 'function subscription_plan_catalog()', 'plan catalog helper'],
    [$files['bootstrap'], "'basic' => [", 'Basic plan'],
    [$files['bootstrap'], "'pro' => [", 'PRO plan'],
    [$files['bootstrap'], "'premium' => [", 'Premium plan'],
    [$files['bootstrap'], "'enforcement_enabled' => false", 'safe non-enforcement stage'],
    [$files['trial'], '14 * 86400', '14 day PRO trial'],
    [$files['checkout'], 'PAYMENT_CHECKOUT_PREVIEW_ONLY', 'checkout preview guard'],
    [$files['index'], 'id="subscription"', 'subscription view'],
    [$files['index'], 'id="subscriptionPaymentModal"', 'payment form modal'],
    [$files['app'], 'async function loadSubscriptionPage()', 'subscription page loader'],
    [$files['app'], 'async function startProTrial()', 'trial UI action'],
];

foreach ($checks as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Subscription smoke failed: {$label}.\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($files['sqlite']);
foreach (['user_subscriptions', 'subscription_usage_monthly', 'payment_orders'] as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :name");
    $stmt->execute(['name' => $table]);
    if ((int)$stmt->fetchColumn() !== 1) {
        fwrite(STDERR, "Subscription smoke failed: {$table} missing after SQLite schema load.\n");
        exit(1);
    }
}

echo "Subscription stage 1 smoke OK\n";
