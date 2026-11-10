<?php
declare(strict_types=1);

$files = [
    'bootstrap' => file_get_contents(__DIR__ . '/../../api/bootstrap.php'),
    'index' => file_get_contents(__DIR__ . '/../../index.html'),
    'app' => file_get_contents(__DIR__ . '/../../app.js'),
    'sqlite' => file_get_contents(__DIR__ . '/../../database/schema.sql'),
    'mysql' => file_get_contents(__DIR__ . '/../../database/schema.mysql.sql'),
    'list' => file_get_contents(__DIR__ . '/../../api/admin/subscriptions/list.php'),
    'set' => file_get_contents(__DIR__ . '/../../api/admin/subscriptions/set.php'),
];

foreach ($files as $name => $content) {
    if ($content === false) {
        fwrite(STDERR, "Admin subscription grant smoke failed: cannot read {$name}.\n");
        exit(1);
    }
}

$checks = [
    [$files['sqlite'], 'CREATE TABLE IF NOT EXISTS subscription_admin_grants', 'SQLite admin grant table'],
    [$files['mysql'], 'CREATE TABLE IF NOT EXISTS subscription_admin_grants', 'MySQL admin grant table'],
    [$files['bootstrap'], "'status' => 'admin_grant'", 'effective admin grant status'],
    [$files['bootstrap'], "payment_provider' => 'manual_admin'", 'manual provider marker'],
    [$files['list'], 'is_platform_admin', 'platform admin list protection'],
    [$files['set'], 'subscription_admin_grant_set', 'grant audit event'],
    [$files['set'], 'subscription_admin_grant_removed', 'grant removal audit event'],
    [$files['index'], 'id="adminSubscriptionsBody"', 'admin subscription table'],
    [$files['index'], 'id="adminSubscriptionModal"', 'admin subscription modal'],
    [$files['app'], 'async function loadAdminSubscriptionGrants()', 'admin subscription loader'],
    [$files['app'], 'function openAdminSubscriptionGrant', 'admin subscription editor'],
];

foreach ($checks as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Admin subscription grant smoke failed: {$label}.\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($files['sqlite']);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='subscription_admin_grants'");
$stmt->execute();
if ((int)$stmt->fetchColumn() !== 1) {
    fwrite(STDERR, "Admin subscription grant smoke failed: table missing after schema load.\n");
    exit(1);
}

echo "Admin subscription grant smoke OK\n";
