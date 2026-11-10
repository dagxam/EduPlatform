<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';

$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);

$d=read_json_body();
$id=(int)($d['payment_order_id']??0);
$status=(string)($d['receipt_status']??'');
$reference=trim((string)($d['receipt_reference']??''));

if($id<1) json_response(['ok'=>false,'error'=>'Платёж не выбран.'],422);
if(!in_array($status,['pending','issued','not_required'],true)) json_response(['ok'=>false,'error'=>'Некорректный статус чека.'],422);
if(strlen($reference)>500) json_response(['ok'=>false,'error'=>'Ссылка или номер чека слишком длинные.'],422);

$pdo=app_db();
$stmt=$pdo->prepare('SELECT id,status,customer_email FROM payment_orders WHERE id=:id LIMIT 1');
$stmt->execute(['id'=>$id]);
$order=$stmt->fetch();
if(!$order) json_response(['ok'=>false,'error'=>'Платёж не найден.'],404);

$pdo->prepare(
    'UPDATE payment_orders
     SET receipt_status=:receipt_status, receipt_reference=:receipt_reference
     WHERE id=:id'
)->execute([
    'receipt_status'=>$status,
    'receipt_reference'=>$reference!==''?$reference:null,
    'id'=>$id
]);

audit_event('payment_receipt_status_updated','payment_order',$id,[
    'receipt_status'=>$status,
    'has_reference'=>$reference!=='',
],null,(int)$user['id']);

json_response(['ok'=>true,'message'=>'Статус чека обновлён.']);
