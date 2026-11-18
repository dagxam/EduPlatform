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
$apiKey = trim((string)($data['api_key'] ?? ''));
$model = trim((string)($data['model'] ?? 'openai/gpt-oss-20b'));
$current = ai_runtime_config();
$currentGroqKey = (string)($current['provider'] ?? '') === 'groq'
    ? trim((string)($current['api_key'] ?? ''))
    : '';

if ($apiKey === '' && $currentGroqKey === '' && trim((string)(getenv('GROQ_API_KEY') ?: '')) === '') {
    json_response(['ok' => false, 'error' => 'Укажите бесплатный API-ключ Groq.'], 422);
}
if ($apiKey === '') {
    $apiKey = $currentGroqKey;
}
if ($model === '' || !preg_match('/^[a-zA-Z0-9._-]{2,80}$/', $model)) {
    json_response(['ok' => false, 'error' => 'Укажите корректное название модели.'], 422);
}

$config = [
    'provider' => 'groq',
    'api_key' => $apiKey,
    'model' => $model,
];

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

audit_event('ai_config_updated', 'system', 1, ['provider' => 'groq', 'model' => $model], null, (int)$user['id']);

json_response([
    'ok' => true,
    'message' => 'Бесплатный ИИ UROVIA через Groq настроен. API-ключ хранится только в закрытом storage и не попадает в GitHub.',
]);
