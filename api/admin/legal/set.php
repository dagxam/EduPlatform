<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$d=read_json_body();
$type=(string)($d['entity_type']??'self_employed');
if(!in_array($type,['self_employed','ip','company'],true)) json_response(['ok'=>false,'error'=>'Некорректный тип владельца.'],422);
$inn=preg_replace('/\D+/','',(string)($d['inn']??''))??'';
if($inn!=='' && !in_array(strlen($inn),[10,12],true)) json_response(['ok'=>false,'error'=>'ИНН должен содержать 10 или 12 цифр.'],422);
$email=trim((string)($d['email']??''));
$support=trim((string)($d['support_email']??''));
foreach([$email,$support] as $v){ if($v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) json_response(['ok'=>false,'error'=>'Проверьте email.'],422); }
$pdo=app_db();
$stmt=$pdo->prepare('INSERT INTO site_legal_profile
(id,entity_type,legal_name,inn,ogrn,legal_address,postal_address,phone,email,support_email,tax_note,updated_by,updated_at)
VALUES (1,:entity_type,:legal_name,:inn,:ogrn,:legal_address,:postal_address,:phone,:email,:support_email,:tax_note,:updated_by,CURRENT_TIMESTAMP)'
.db_upsert_clause($pdo,['id'],['entity_type','legal_name','inn','ogrn','legal_address','postal_address','phone','email','support_email','tax_note','updated_by','updated_at']));
$stmt->execute([
'entity_type'=>$type,
'legal_name'=>trim((string)($d['legal_name']??''))?:null,
'inn'=>$inn?:null,
'ogrn'=>trim((string)($d['ogrn']??''))?:null,
'legal_address'=>trim((string)($d['legal_address']??''))?:null,
'postal_address'=>trim((string)($d['postal_address']??''))?:null,
'phone'=>trim((string)($d['phone']??''))?:null,
'email'=>$email?:null,
'support_email'=>$support?:null,
'tax_note'=>trim((string)($d['tax_note']??''))?:null,
'updated_by'=>(int)$user['id'],
]);
audit_event('site_legal_profile_updated','site',1,[],null,(int)$user['id']);
json_response(['ok'=>true,'profile'=>site_legal_profile($pdo),'message'=>'Реквизиты сохранены.']);
