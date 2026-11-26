<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Доступ только главному администратору UROVIA.'], 403);
}
ai_require_enabled();

$config = ai_config();
json_response([
    'ok' => true,
    'config' => [
        'model' => (string)$config['model'],
        'api_key_configured' => (string)$config['api_key'] !== '',
        'provider' => $config['provider'],
        'endpoint' => $config['api_base'].'/chat/completions',
        'providers' => array_map(static fn($name)=>['provider'=>$name,'model'=>ai_config_for_provider($name)['model'],'api_key_configured'=>ai_config_for_provider($name)['api_key']!==''],['groq','openrouter']),
    ],
]);
