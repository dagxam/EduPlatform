<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
require_once dirname(__DIR__,2).'/connection/_helpers.php';
$user=connection_require_platform_admin();
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$data=read_json_body(8192);$id=(int)($data['id']??0);
if($id<1)json_response(['ok'=>false,'error'=>'Обращение не указано.'],422);
$pdo=app_db();
if(($data['action']??'')==='delete'){
    $q=$pdo->prepare('DELETE FROM connection_requests WHERE id=?');$q->execute([$id]);
    if(!$q->rowCount())json_response(['ok'=>false,'error'=>'Обращение не найдено.'],404);
    audit_event('connection_request_deleted','connection_request',$id,[],null,(int)$user['id']);
    json_response(['ok'=>true]);
}
try{
    $status=connection_request_text($data,'status',20,true);$note=connection_request_text($data,'admin_note',1000);
    if(!connection_request_update($pdo,$id,$status,$note))json_response(['ok'=>false,'error'=>'Обращение не найдено.'],404);
    audit_event('connection_request_updated','connection_request',$id,['status'=>$status],null,(int)$user['id']);
    json_response(['ok'=>true]);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],422);}
