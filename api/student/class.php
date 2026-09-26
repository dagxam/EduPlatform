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

throttle_check('class_lookup', 'lookup', 20, 60);

$stmt = app_db()->prepare(
    'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.school_id,
            CASE
              WHEN ca.registration_open = 1
               AND ca.registration_expires_at IS NOT NULL
               AND ca.registration_expires_at > CURRENT_TIMESTAMP
              THEN 1 ELSE 0
            END AS registration_open,
            ca.registration_expires_at
     FROM class_access ca
     JOIN classes c ON c.id = ca.class_id
     WHERE ca.join_code = :code
     LIMIT 1'
);
$stmt->execute(['code' => $code]);
$class = $stmt->fetch();

if (!$class) {
    throttle_failure('class_lookup', 'lookup', 20, 60, 300);
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
        'registration_expires_at' => $class['registration_expires_at'],
    ],
    'students' => $stmt->fetchAll(),
    'branding' => (int)($class['school_id'] ?? 0) > 0
        ? school_branding((int)$class['school_id'])
        : ['theme_color' => '#1d68f0'],
]);
