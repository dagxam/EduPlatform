<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
require_once dirname(__DIR__,2).'/connection/_helpers.php';
connection_require_platform_admin();
if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$pdo=app_db();
$status=(string)($_GET['status']??'all');
if(!in_array($status,array_merge(['all'],connection_request_statuses()),true))json_response(['ok'=>false,'error'=>'Некорректный фильтр.'],422);
$page=max(1,min(10000,(int)($_GET['page']??1)));$limit=25;
$counts=array_fill_keys(connection_request_statuses(),0);
foreach($pdo->query('SELECT status,COUNT(*) AS total FROM connection_requests GROUP BY status')->fetchAll() as $row){if(isset($counts[$row['status']]))$counts[$row['status']]=(int)$row['total'];}
$total=$status==='all'?array_sum($counts):$counts[$status];
$pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;
$where=$status==='all'?'':' WHERE status=:status';
$q=$pdo->prepare('SELECT id,kind,full_name,school_name,locality,email,phone,message,status,admin_note,consent_version,created_at,updated_at FROM connection_requests'.$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);
$q->execute($status==='all'?[]:['status'=>$status]);
json_response(['ok'=>true,'items'=>$q->fetchAll(),'counts'=>$counts,'page'=>$page,'pages'=>$pages,'total'=>$total]);
