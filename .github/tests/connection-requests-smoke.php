<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
require_once dirname(__DIR__,2).'/api/connection/_helpers.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
connection_requests_schema($pdo);
$data=['kind'=>'teacher','full_name'=>'Тестовый Учитель','school_name'=>'Школа № 1','locality'=>'Махачкала','email'=>'test@example.test','consent'=>true];
$id=connection_request_create($pdo,$data);
$row=$pdo->query('SELECT * FROM connection_requests')->fetch();
if($id!==1||$row['status']!=='new'||$row['consent_version']!=='2026-11-27')throw new RuntimeException('Create request failed');
foreach([['consent'=>false],['email'=>'invalid'],['kind'=>'student'],['full_name'=>str_repeat('a',151)],['school_name'=>''],['phone'=>'<script>']] as $bad){
 try{connection_request_create($pdo,array_replace($data,$bad));throw new RuntimeException('Invalid request accepted');}catch(InvalidArgumentException $e){}
}
if(!connection_request_update($pdo,$id,'connected','Связались')||$pdo->query('SELECT status FROM connection_requests')->fetchColumn()!=='connected')throw new RuntimeException('Update failed');
try{connection_request_update($pdo,$id,'invalid','');throw new RuntimeException('Invalid status accepted');}catch(InvalidArgumentException $e){}
$root=dirname(__DIR__,2);
foreach(['list','update'] as $p)if(!str_contains(file_get_contents($root.'/api/admin/connection/'.$p.'.php'),'connection_require_platform_admin()'))throw new RuntimeException('Admin gate missing');
if(!str_contains(file_get_contents($root.'/api/connection/submit.php'),"throttle_check('connection_request'"))throw new RuntimeException('Throttle missing');
if(!str_contains(file_get_contents($root.'/login.html'),'id="connectForm"')||!str_contains(file_get_contents($root.'/index.html'),'id="connection-requests"'))throw new RuntimeException('UI missing');
echo "Connection requests: validation, consent, SQLite storage, statuses and admin gates OK\n";
