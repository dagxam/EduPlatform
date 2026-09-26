<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

require_user(['admin', 'teacher']);
$stmt = app_db()->query('SELECT id, name FROM subjects ORDER BY name COLLATE NOCASE');
json_response(['ok' => true, 'subjects' => $stmt->fetchAll()]);
