<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$d=read_json_body();
$shopId=trim((string)($d['shop_id']??''));
$secret=trim((string)($d['secret_key']??''));
$testMode=privacy_truthy($d['test_mode']??false);
$recurringEnabled=privacy_truthy($d['recurring_enabled']??false);
$current=yookassa_runtime_config();
if($shopId==='') json_response(['ok'=>false,'error'=>'Укажите shopId ЮKassa.'],422);
if($secret==='' && empty($current['secret_key'])) json_response(['ok'=>false,'error'=>'Укажите секретный ключ ЮKassa.'],422);
if($secret==='') $secret=(string)$current['secret_key'];
$cronSecret=(string)($current['cron_secret']??'');
if($cronSecret==='') $cronSecret=bin2hex(random_bytes(24));
$config=[
  'shop_id'=>$shopId,
  'secret_key'=>$secret,
  'api_base'=>'https://api.yookassa.ru/v3',
  'test_mode'=>$testMode,
  'recurring_enabled'=>$recurringEnabled,
  'cron_secret'=>$cronSecret,
];
$path=yookassa_config_path();
$dir=dirname($path);
if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) json_response(['ok'=>false,'error'=>'Не удалось подготовить закрытое хранилище.'],500);
$php="<?php\nreturn ".var_export($config,true).";\n";
if(file_put_contents($path,$php,LOCK_EX)===false) json_response(['ok'=>false,'error'=>'Не удалось сохранить конфигурацию ЮKassa.'],500);
@chmod($path,0600);
audit_event('yookassa_config_updated','system',1,['shop_id'=>$shopId,'test_mode'=>$testMode,'recurring_enabled'=>$recurringEnabled],null,(int)$user['id']);
json_response(['ok'=>true,'message'=>'Настройки ЮKassa сохранены. Секретный ключ хранится только в закрытом storage и не попадает в GitHub.']);
