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
$stmt=$pdo->prepare('SELECT id,archived_at,status FROM payment_orders WHERE id=:id LIMIT 1');
$stmt->execute(['id'=>$id]);
$order=$stmt->fetch();
if(!$order) json_response(['ok'=>false,'error'=>'Платёж не найден.'],404);

if(empty($order['archived_at'])){
    json_response(['ok'=>true,'message'=>'Запись уже находится в основном журнале.']);
}

$pdo->prepare('UPDATE payment_orders SET archived_at=NULL WHERE id=:id')->execute(['id'=>$id]);
audit_event('payment_order_restored','payment_order',$id,[
    'status'=>(string)($order['status']??''),
],null,(int)$user['id']);

json_response(['ok'=>true,'message'=>'Платёж возвращён из архива.']);
