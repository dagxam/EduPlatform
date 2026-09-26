<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Этот системный раздел доступен только администратору UVORIA.'], 403);
}

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