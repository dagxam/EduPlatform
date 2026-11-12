<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'bootstrap' => file_get_contents($root . '/api/bootstrap.php'),
    'checkout' => file_get_contents($root . '/api/subscription/create-checkout.php'),
    'webhook' => file_get_contents($root . '/api/subscription/yookassa-webhook.php'),
    'renew' => file_get_contents($root . '/api/subscription/renew-due.php'),
    'cancel' => file_get_contents($root . '/api/subscription/cancel-auto-renew.php'),
    'status' => file_get_contents($root . '/api/subscription/status.php'),
    'legal_get' => file_get_contents($root . '/api/admin/legal/get.php'),
    'legal_set' => file_get_contents($root . '/api/admin/legal/set.php'),
    'yk_get' => file_get_contents($root . '/api/admin/yookassa/get.php'),
    'yk_set' => file_get_contents($root . '/api/admin/yookassa/set.php'),
    'sqlite' => file_get_contents($root . '/database/schema.sql'),
    'mysql' => file_get_contents($root . '/database/schema.mysql.sql'),
    'index' => file_get_contents($root . '/index.html'),
    'login' => file_get_contents($root . '/login.html'),
    'app' => file_get_contents($root . '/app.js'),
    'requisites' => file_get_contents($root . '/requisites.php'),
    'pricing' => file_get_contents($root . '/pricing.php'),
    'offer' => file_get_contents($root . '/offer.php'),
    'payment_info' => file_get_contents($root . '/payment-info.php'),
    'payments_list' => file_get_contents($root . '/api/admin/payments/list.php'),
    'receipt_mark' => file_get_contents($root . '/api/admin/payments/mark-receipt.php'),
    'payment_remove' => file_get_contents($root . '/api/admin/payments/remove.php'),
    'payment_restore' => file_get_contents($root . '/api/admin/payments/restore.php'),
];

foreach ($files as $name => $content) {
    if ($content === false) {
        fwrite(STDERR, "YooKassa smoke failed: cannot read {$name}.\n");
        exit(1);
    }
}

$checks = [
    [$files['bootstrap'], 'function yookassa_request(', 'YooKassa API client'],
    [$files['bootstrap'], 'CURLOPT_HTTPAUTH => CURLAUTH_BASIC', 'Basic Auth'],
    [$files['bootstrap'], 'Idempotence-Key:', 'idempotence header'],
    [$files['bootstrap'], 'function yookassa_config_path()', 'protected config path'],
    [$files['bootstrap'], 'function subscription_activate_from_payment(', 'payment activation helper'],
    [$files['bootstrap'], "'recurring_enabled' => array_key_exists('recurring_enabled', \$stored)", 'recurring defaults from protected config'],
    [$files['checkout'], "'save_payment_method' => \$autoRenew", 'explicit recurring consent'],
    [$files['checkout'], "\$autoRenewRequested && (bool)(\$config['recurring_enabled'] ?? false)", 'recurring requires provider permission'],
    [$files['checkout'], "\$payload['save_payment_method'] = false", 'one-time fallback when recurring is rejected'],
    [$files['checkout'], "'recurring_fallback' => \$recurringFallback", 'checkout reports recurring fallback'],
    [$files['checkout'], "'type' => 'redirect'", 'redirect checkout'],
    [$files['checkout'], "'return_url' => uvoria_app_url()", 'return URL'],
    [$files['checkout'], "'customer_email' => \$receiptEmail", 'payer email persistence'],
    [$files['webhook'], "yookassa_request('GET'", 'webhook provider verification'],
    [$files['webhook'], "==='succeeded') json_response(['ok'=>true])", 'duplicate succeeded webhook guard'],
    [$files['renew'], "'payment_method_id'", 'recurring payment method'],
    [$files['renew'], 'cron_secret', 'renewal worker protection'],
    [$files['renew'], "empty(\$config['recurring_enabled'])", 'renewal worker respects provider permission'],
    [$files['cancel'], 'auto_renew = 0', 'auto-renew cancellation'],
    [$files['sqlite'], 'CREATE TABLE IF NOT EXISTS site_legal_profile', 'SQLite legal profile'],
    [$files['sqlite'], 'customer_email TEXT', 'SQLite payer email'],
    [$files['mysql'], 'CREATE TABLE IF NOT EXISTS site_legal_profile', 'MySQL legal profile'],
    [$files['mysql'], 'customer_email VARCHAR(191)', 'MySQL payer email'],
    [$files['sqlite'], 'receipt_status TEXT', 'SQLite receipt tracking'],
    [$files['mysql'], 'receipt_status VARCHAR(32)', 'MySQL receipt tracking'],
    [$files['payments_list'], 'receipt_status', 'payment journal receipt status'],
    [$files['payments_list'], 'archived_at IS NULL', 'payment journal archive filter'],
    [$files['receipt_mark'], 'payment_receipt_status_updated', 'receipt audit event'],
    [$files['payment_remove'], 'payment_order_deleted', 'safe deletion audit event'],
    [$files['payment_remove'], 'payment_order_archived', 'financial record archive event'],
    [$files['payment_restore'], 'payment_order_restored', 'payment restore audit event'],
    [$files['app'], 'data-remove-payment', 'payment cleanup frontend action'],
    [$files['app'], 'data-restore-payment', 'payment restore frontend action'],
    [$files['index'], 'showArchivedYooKassaPayments', 'payment archive UI toggle'],
    [$files['index'], 'id="legalProfileForm"', 'legal profile UI'],
    [$files['index'], 'id="yookassaConfigForm"', 'YooKassa config UI'],
    [$files['index'], 'id="yookassaRecurringEnabled"', 'recurring provider permission toggle'],
    [$files['index'], 'id="subscriptionRecurringConsentText"', 'recurring availability checkout message'],
    [$files['app'], 'async function loadYooKassaAdminConfig()', 'YooKassa config frontend'],
    [$files['app'], 'recurringConsent.disabled = !recurringAvailable', 'disabled recurring checkout when unavailable'],
    [$files['app'], 'Boolean(subscriptionDataCache?.payment?.recurring_enabled)', 'frontend recurring permission enforcement'],
    [$files['login'], 'href="pricing.php"', 'public pricing link'],
    [$files['login'], 'href="offer.php"', 'public offer link'],
    [$files['login'], 'href="requisites.php"', 'public requisites link'],
    [$files['requisites'], 'Реквизиты и контакты', 'public requisites page'],
    [$files['pricing'], 'Тарифы и цены UROVIA', 'public pricing page'],
    [$files['offer'], 'Публичная оферта', 'public offer page'],
    [$files['offer'], 'Порядок оформления заказа', 'offer order flow'],
    [$files['offer'], 'Порядок получения услуги', 'offer digital delivery flow'],
    [$files['payment_info'], 'Оплата и получение услуги', 'public payment information page'],
    [$files['payment_info'], 'Как покупатель получает цифровую услугу', 'digital delivery information'],
    [$files['payment_info'], "\$plans['pro']['monthly_price_minor']", 'PRO public price source'],
    [$files['payment_info'], "\$plans['premium']['monthly_price_minor']", 'Premium public price source'],
    [$files['legal_set'], 'Укажите действующий контактный телефон', 'required public phone validation'],
    [$files['legal_set'], 'Укажите почтовый адрес', 'required public postal address validation'],
    [$files['requisites'], 'Ф.И.О. самозанятого', 'self-employed public name label'],
    [$files['requisites'], 'ИНН самозанятого', 'self-employed public INN label'],
];

foreach ($checks as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "YooKassa smoke failed: {$label}.\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($files['sqlite']);

foreach (['user_subscriptions', 'payment_orders', 'site_legal_profile', 'subscription_admin_grants'] as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
    $stmt->execute(['name' => $table]);
    if ((int)$stmt->fetchColumn() !== 1) {
        fwrite(STDERR, "YooKassa smoke failed: missing SQLite table {$table}.\n");
        exit(1);
    }
}

$columns = $pdo->query('PRAGMA table_info(payment_orders)')->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'name');
foreach (['customer_email','receipt_status','receipt_reference','archived_at'] as $requiredColumn) {
    if (!in_array($requiredColumn, $columnNames, true)) {
        fwrite(STDERR, "YooKassa smoke failed: payment_orders.{$requiredColumn} missing.\n");
        exit(1);
    }
}

echo "YooKassa integration smoke OK\n";
