<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$count = (int) app_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

json_response([
    'ok' => true,
    'needs_setup' => $count === 0,
]);
