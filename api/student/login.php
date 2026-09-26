<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$code = strtoupper(trim((string)($data['code'] ?? '')));
$studentId = (int)($data['student_id'] ?? 0);
$pin = trim((string)($data['pin'] ?? ''));

if ($code === '' || $studentId < 1 || !preg_match('/^\d{4,6}$/', $pin)) {
    json_response(['ok' => false, 'error' => 'Введите код класса, выберите себя и укажите PIN из 4–6 цифр.'], 422);
}

$throttleId = $code . ':' . $studentId;
throttle_check('student_login', $throttleId, 5, 900);

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.is_active,
            c.id AS class_id, c.name AS class_name,
            CASE
              WHEN ca.registration_open = 1
               AND ca.registration_expires_at IS NOT NULL
               AND ca.registration_expires_at > CURRENT_TIMESTAMP
              THEN 1 ELSE 0
            END AS registration_open,
            ca.registration_expires_at,
            cs.pin_hash, cs.activated_at
     FROM class_access ca
     JOIN classes c ON c.id = ca.class_id
     JOIN class_students cs ON cs.class_id = c.id
     JOIN users u ON u.id = cs.student_id
     WHERE ca.join_code = :code AND u.id = :student_id
     LIMIT 1'
);
$stmt->execute(['code' => $code, 'student_id' => $studentId]);
$student = $stmt->fetch();

if (!$student || !(int)$student['is_active']) {
    throttle_failure('student_login', $throttleId, 5, 900, 900);
    usleep(250000);
    json_response(['ok' => false, 'error' => 'Ученик не найден в этом классе.'], 404);
}

$firstLogin = $student['activated_at'] === null;

if ($firstLogin) {
    if (!(int)$student['registration_open']) {
        json_response([
            'ok' => false,
            'error' => 'Первичная регистрация сейчас закрыта. Учитель должен открыть её на 20 минут.',
        ], 403);
    }

    $stmt = $pdo->prepare(
        'UPDATE class_students
         SET pin_hash = :pin_hash, activated_at = CURRENT_TIMESTAMP
         WHERE student_id = :student_id AND activated_at IS NULL'
    );
    $stmt->execute([
        'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
        'student_id' => $studentId,
    ]);

    if ($stmt->rowCount() !== 1) {
        json_response(['ok' => false, 'error' => 'Этот ученик уже активирован. Обновите список и войдите со своим PIN.'], 409);
    }
} elseif (!$student['pin_hash'] || !password_verify($pin, $student['pin_hash'])) {
    throttle_failure('student_login', $throttleId, 5, 900, 900);
    audit_event('login_failed', 'user', $studentId, ['kind' => 'student_pin']);
    usleep(250000);
    json_response(['ok' => false, 'error' => 'Неверный PIN.'], 401);
}

throttle_clear('student_login', $throttleId);
session_regenerate_id(true);
$_SESSION['user_id'] = $studentId;

audit_event(
    $firstLogin ? 'student_activated' : 'login_succeeded',
    'user',
    $studentId,
    ['kind' => 'student_pin', 'class_id' => (int)$student['class_id']],
    null,
    $studentId
);

json_response([
    'ok' => true,
    'first_login' => $firstLogin,
    'user' => current_user(),
]);
