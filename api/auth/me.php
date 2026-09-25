<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = current_user();

json_response([
    'ok' => true,
    'authenticated' => $user !== null,
    'user' => $user,
]);
