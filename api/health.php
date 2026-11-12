<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    $pdo = app_db();
    $pdo->query('SELECT 1');
    json_response([
        'ok' => true,
        'service' => 'UROVIA API',
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Ошибка подключения к базе данных.',
    ], 500);
}
