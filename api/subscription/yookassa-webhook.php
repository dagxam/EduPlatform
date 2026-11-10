<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false],405);
$raw=file_get_contents('php://input');
$event=json_decode((string)$raw,true);
if(!is_array($event) || empty($event['object']['id'])) json_response(['ok'=>true]);
$paymentId=(string)$event['object']['id'];
try{$payment=yookassa_request('GET','payments/'.rawurlencode($paymentId));}catch(Throwable $e){error_log('[UROVIA] YooKassa webhook verify failed: '.$e->getMessage());json_response(['ok'=>false],503);}
$metadata=$payment['metadata']??[];
$orderId=(int)($metadata['order_id']??0);
if($orderId<1) json_response(['ok'=>true]);
$pdo=app_db();
$stmt=$pdo->prepare('SELECT * FROM payment_orders WHERE id=:id LIMIT 1');
$stmt->execute(['id'=>$orderId]); $order=$stmt->fetch();
if(!$order || (string)($order['provider_payment_id']??'')!==$paymentId) json_response(['ok'=>true]);
$expected=number_format(((int)$order['amount_minor'])/100,2,'.','');
if((string)($payment['amount']['value']??'')!==$expected || (string)($payment['amount']['currency']??'')!=='RUB') json_response(['ok'=>false],400);
$status=(string)($payment['status']??'');
if($status==='succeeded'){
 if((string)($order['status']??'')==='succeeded') json_response(['ok'=>true]);
 $pdo->beginTransaction();
 try{
  $pdo->prepare('UPDATE payment_orders SET status="succeeded", paid_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$orderId]);
  subscription_activate_from_payment($pdo,$order,$payment);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
} elseif($status==='canceled'){
 $pdo->prepare('UPDATE payment_orders SET status="cancelled" WHERE id=:id')->execute(['id'=>$orderId]);
}
json_response(['ok'=>true]);
