<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
$c=yookassa_config();
json_response([
  'ok'=>true,
  'config'=>[
    'shop_id'=>$c['shop_id'],
    'secret_configured'=>$c['secret_key']!=='',
    'test_mode'=>(bool)$c['test_mode'],
    'recurring_enabled'=>(bool)($c['recurring_enabled']??false),
    'webhook_url'=>uvoria_app_url().'/api/subscription/yookassa-webhook.php',
    'return_url'=>uvoria_app_url().'/payment-return.html',
    'cron_configured'=>$c['cron_secret']!==''
  ]
]);
