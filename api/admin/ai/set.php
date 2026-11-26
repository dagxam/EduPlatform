<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Доступ только главному администратору UROVIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$apiKey=trim((string)($data['api_key']??''));
$current=ai_runtime_config();
$provider=(string)($data['provider']??($current['active_provider']??'groq'));
if(!in_array($provider,['groq','openrouter'],true))json_response(['ok'=>false,'error'=>'Неизвестный провайдер.'],422);
$model=$provider==='openrouter'?'openrouter/free':trim((string)($data['model']??'openai/gpt-oss-20b'));
if($provider==='groq'&&(!preg_match('~^[a-zA-Z0-9._-]+(?:/[a-zA-Z0-9._-]+)*$~D',$model)||strlen($model)>100||strlen($model)<2))json_response(['ok'=>false,'error'=>'Укажите корректное название модели.'],422);
if($apiKey!==''&&(strlen($apiKey)>512||preg_match('/[\r\n]/',$apiKey)))json_response(['ok'=>false,'error'=>'Некорректный формат ключа.'],422);
$oldGroq=ai_config_for_provider('groq',$current);
$config=['provider'=>'groq','api_key'=>($current['provider']??'')==='groq'?(string)($current['api_key']??''):'',
 'model'=>$oldGroq['model'],'openrouter_api_key'=>(string)($current['openrouter_api_key']??''),'active_provider'=>$provider];
if($provider==='groq'){if($apiKey!=='')$config['api_key']=$apiKey;$config['model']=$model;}
else if($apiKey!=='')$config['openrouter_api_key']=$apiKey;
if(ai_config_for_provider($provider,$config)['api_key']==='')json_response(['ok'=>false,'error'=>'Введите API-ключ выбранного провайдера.'],422);

$path = ai_config_path();
$dir = dirname($path);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'Не удалось подготовить закрытое хранилище.'], 500);
}
$php = "<?php\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($path, $php, LOCK_EX) === false) {
    json_response(['ok' => false, 'error' => 'Не удалось сохранить конфигурацию ИИ.'], 500);
}
@chmod($path, 0600);

audit_event('ai_config_updated', 'system', 1, ['provider' => $provider, 'model' => $model], null, (int)$user['id']);

json_response([
    'ok' => true,
    'message' => 'Настройки сохранены. Проверьте онлайн и PAPER. Ключи хранятся в закрытом storage.',
]);
