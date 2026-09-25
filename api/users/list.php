<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

require_user(['admin']);

$stmt = app_db()->query(
    'SELECT id, first_name, last_name, email, role, class_name, is_active, created_at
     FROM users
     ORDER BY CASE role WHEN "admin" THEN 1 WHEN "teacher" THEN 2 ELSE 3 END,
              last_name COLLATE NOCASE,
              first_name COLLATE NOCASE'
);

json_response([
    'ok' => true,
    'users' => $stmt->fetchAll(),
]);