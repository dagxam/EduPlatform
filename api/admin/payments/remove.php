<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';

$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);

$data=read_json_body();
$id=(int)($data['payment_order_id']??0);
if($id<1) json_response(['ok'=>false,'error'=>'Платёж не выбран.'],422);

$pdo=app_db();
$stmt=$pdo->prepare(
    'SELECT id,user_id,plan_code,billing_period,amount_minor,currency,provider,provider_payment_id,
            customer_email,receipt_status,receipt_reference,status,created_at,paid_at,archived_at
     FROM payment_orders
     WHERE id=:id
     LIMIT 1'
);
$stmt->execute(['id'=>$id]);
$order=$stmt->fetch();
if(!$order) json_response(['ok'=>false,'error'=>'Платёж не найден.'],404);

$status=(string)($order['status']??'');
$providerPaymentId=trim((string)($order['provider_payment_id']??''));
$paidAt=trim((string)($order['paid_at']??''));
$hardDeleteAllowed=
    in_array($status,['failed','cancelled'],true)
    || ($status==='created' && $providerPaymentId==='' && $paidAt==='');

$snapshot=[
    'status'=>$status,
    'plan_code'=>(string)($order['plan_code']??''),
    'amount_minor'=>(int)($order['amount_minor']??0),
    'provider'=>(string)($order['provider']??''),
    'provider_payment_id'=>$providerPaymentId!==''?$providerPaymentId:null,
    'receipt_status'=>(string)($order['receipt_status']??''),
    'had_receipt_reference'=>trim((string)($order['receipt_reference']??''))!=='',
];

if($hardDeleteAllowed){
    audit_event('payment_order_deleted','payment_order',$id,$snapshot,null,(int)$user['id']);
    $delete=$pdo->prepare('DELETE FROM payment_orders WHERE id=:id');
    $delete->execute(['id'=>$id]);
    json_response([
        'ok'=>true,
        'action'=>'deleted',
        'message'=>'Неуспешная или отменённая запись удалена из внутреннего журнала UROVIA.',
    ]);
}

if(!empty($order['archived_at'])){
    json_response([
        'ok'=>true,
        'action'=>'archived',
        'message'=>'Запись уже находится в архиве.',
    ]);
}

$update=$pdo->prepare('UPDATE payment_orders SET archived_at=CURRENT_TIMESTAMP WHERE id=:id');
$update->execute(['id'=>$id]);
audit_event('payment_order_archived','payment_order',$id,$snapshot,null,(int)$user['id']);

json_response([
    'ok'=>true,
    'action'=>'archived',
    'message'=>'Платёж скрыт в архиве. Финансовая история и данные чека сохранены.',
]);
