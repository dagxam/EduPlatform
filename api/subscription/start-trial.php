<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

json_response([
    'ok' => false,
    'error' => 'Пробный доступ отключён. Выберите Базовый, PRO или Premium.',
    'code' => 'TRIAL_DISABLED',
], 410);
