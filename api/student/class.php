<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$code = strtoupper(trim((string)($data['code'] ?? '')));
if ($code === '') {
    json_response(['ok' => false, 'error' => 'Введите код класса.'], 422);
}

$stmt = app_db()->prepare(
    'SELECT c.id, c.name, ca.registration_open
     FROM class_access ca
     JOIN classes c ON c.id = ca.class_id
     WHERE ca.join_code = :code
     LIMIT 1'
);
$stmt->execute(['code' => $code]);
$class = $stmt->fetch();

if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс с таким кодом не найден.'], 404);
}

$stmt = app_db()->prepare(
    'SELECT u.id, u.first_name, u.last_name,
            CASE WHEN cs.activated_at IS NULL THEN 0 ELSE 1 END AS activated
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id AND u.is_active = 1
     ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE'
);
$stmt->execute(['class_id' => (int)$class['id']]);

json_response([
    'ok' => true,
    'class' => [
        'id' => (int)$class['id'],
        'name' => $class['name'],
        'registration_open' => (int)$class['registration_open'],
    ],
    'students' => $stmt->fetchAll(),
]);
