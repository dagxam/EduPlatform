<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';

$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);

$pdo=app_db();
$stmt=$pdo->query(
    'SELECT p.id,p.user_id,p.plan_code,p.billing_period,p.amount_minor,p.currency,p.provider,
            p.provider_payment_id,p.customer_email,p.receipt_status,p.receipt_reference,
            p.status,p.created_at,p.paid_at,
            u.first_name,u.last_name,u.email AS account_email
     FROM payment_orders p
     JOIN users u ON u.id=p.user_id
     ORDER BY p.id DESC
     LIMIT 100'
);

json_response(['ok'=>true,'payments'=>$stmt->fetchAll()]);
