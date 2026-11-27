<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/_helpers.php';
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
throttle_check('connection_request','',5,3600);
throttle_failure('connection_request','',5,3600,3600);
$data=read_json_body(16384);
if(!empty($data['website']))json_response(['ok'=>true,'message'=>'Заявка принята.']);
try{
    $requestId=connection_request_create(app_db(),$data);
    audit_event('privacy_consent_granted','connection_request',$requestId,privacy_audit_metadata('consent'),null,null);
    json_response(['ok'=>true,'message'=>'Заявка принята. Администратор свяжется с вами по указанным контактам после рассмотрения.'],201);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
