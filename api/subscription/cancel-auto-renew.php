<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin','teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
}
$pdo=app_db();
$stmt=$pdo->prepare(
    'UPDATE user_subscriptions
     SET auto_renew = 0, provider_payment_method_id = NULL, updated_at = CURRENT_TIMESTAMP
     WHERE user_id = :user_id'
);
$stmt->execute(['user_id'=>(int)$user['id']]);
audit_event('subscription_auto_renew_disabled','user',(int)$user['id'],[],current_school_id(),(int)$user['id']);
json_response(['ok'=>true,'message'=>'Автопродление отключено, сохранённый способ оплаты исключён из последующих списаний UROVIA. Текущий оплаченный период сохраняется.']);
