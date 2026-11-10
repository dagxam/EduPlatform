<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$user=require_user(['admin','teacher']);
$id=(int)($_GET['order_id']??0);
$stmt=app_db()->prepare('SELECT id,plan_code,billing_period,amount_minor,currency,status,created_at,paid_at FROM payment_orders WHERE id=:id AND user_id=:user_id LIMIT 1');
$stmt->execute(['id'=>$id,'user_id'=>(int)$user['id']]);
$row=$stmt->fetch();
if(!$row) json_response(['ok'=>false,'error'=>'Платёж не найден.'],404);
json_response(['ok'=>true,'order'=>$row]);
