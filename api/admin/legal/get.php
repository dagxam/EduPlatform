<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
json_response(['ok'=>true,'profile'=>site_legal_profile(app_db())]);
