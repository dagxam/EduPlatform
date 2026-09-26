<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, true);
$data = read_json_body();
$teacherId = (int)($data['teacher_id'] ?? 0);

if ($teacherId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран учитель.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.is_active,
            s.name AS school_name
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     JOIN schools s ON s.id = su.school_id
     WHERE su.school_id = :school_id
       AND su.user_id = :teacher_id
       AND su.can_teach = 1
       AND su.is_active = 1
       AND u.is_active = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $schoolId,
    'teacher_id' => $teacherId,
]);
$teacher = $stmt->fetch();

if (!$teacher) {
    json_response(['ok' => false, 'error' => 'Учитель не найден в выбранной школе.'], 404);
}
if (!filter_var((string)$teacher['email'], FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'У учителя не указан корректный email.'], 422);
}

$loginName = generate_staff_login($pdo);
$temporaryPassword = generate_temporary_password();
$loginUrl = uvoria_app_url() . '/login.html';

$subject = 'Доступ к UVORIA — ' . (string)$teacher['school_name'];
$body = "Здравствуйте, " . (string)$teacher['first_name'] . "!\n\n"
    . "Для вас создан доступ к образовательной платформе UVORIA.\n\n"
    . "Школа: " . (string)$teacher['school_name'] . "\n"
    . "Логин: " . $loginName . "\n"
    . "Временный пароль: " . $temporaryPassword . "\n"
    . "Вход: " . $loginUrl . "\n\n"
    . "После первого входа система попросит установить новый пароль.\n"
    . "Не передавайте эти данные другим людям.\n\n"
    . "UVORIA";

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE users
         SET login_name = :login_name,
             password_hash = :password_hash,
             must_change_password = 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'login_name' => $loginName,
        'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
        'id' => $teacherId,
    ]);

    if (!send_uvoria_email((string)$teacher['email'], $subject, $body)) {
        $pdo->rollBack();
        json_response([
            'ok' => false,
            'error' => 'Почтовый сервер не принял письмо. Данные доступа не изменены. Проверьте почту PHP/SMTP на хостинге.',
            'code' => 'MAIL_SEND_FAILED',
        ], 502);
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET credentials_sent_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute(['id' => $teacherId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

audit_event('teacher_credentials_sent', 'user', $teacherId, [
    'email' => (string)$teacher['email'],
    'login_rotated' => true,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'teacher_id' => $teacherId,
    'email' => (string)$teacher['email'],
    'login_name' => $loginName,
    'credentials_sent' => true,
]);
