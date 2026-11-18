<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Доступ только главному администратору UROVIA.'], 403);
}

$config = ai_config();
json_response([
    'ok' => true,
    'config' => [
        'model' => (string)$config['model'],
        'api_key_configured' => (string)$config['api_key'] !== '',
        'provider' => 'openai',
        'endpoint' => 'https://api.openai.com/v1/responses',
    ],
]);
