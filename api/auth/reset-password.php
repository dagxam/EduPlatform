<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$token = trim((string)($data['token'] ?? ''));
$newPassword = (string)($data['new_password'] ?? '');
$confirmPassword = (string)($data['confirm_password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    json_response(['ok' => false, 'error' => 'Ссылка восстановления недействительна или устарела.'], 422);
}

$length = function_exists('mb_strlen') ? mb_strlen($newPassword) : strlen($newPassword);
if ($length < 8) {
    json_response(['ok' => false, 'error' => 'Новый пароль должен содержать минимум 8 символов.'], 422);
}
if ($newPassword !== $confirmPassword) {
    json_response(['ok' => false, 'error' => 'Пароли не совпадают.'], 422);
}

$pdo = app_db();
$now = time();
$tokenHash = hash('sha256', strtolower($token));

$stmt = $pdo->prepare(
    'SELECT prt.id AS token_id, prt.user_id, u.email, u.login_name, u.is_active, u.role, u.is_platform_admin
     FROM password_reset_tokens prt
     JOIN users u ON u.id = prt.user_id
     WHERE prt.token_hash = :token_hash
       AND prt.used_at IS NULL
       AND prt.expires_at >= :now
       AND u.is_active = 1
       AND u.role IN ("admin", "teacher")
     LIMIT 1'
);
$stmt->execute([
    'token_hash' => $tokenHash,
    'now' => $now,
]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['ok' => false, 'error' => 'Ссылка восстановления недействительна или устарела. Запросите новую.'], 410);
}

$userId = (int)$row['user_id'];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             must_change_password = 0,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);

    $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = :used_at
         WHERE user_id = :user_id AND used_at IS NULL'
    )->execute([
        'used_at' => $now,
        'user_id' => $userId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

throttle_clear('staff_login', normalize_email((string)$row['email']));
if (!empty($row['login_name'])) {
    $login = function_exists('mb_strtolower')
        ? mb_strtolower((string)$row['login_name'])
        : strtolower((string)$row['login_name']);
    throttle_clear('staff_login', $login);
}

$schoolId = null;
if ((int)($row['is_platform_admin'] ?? 0) !== 1) {
    $schoolStmt = $pdo->prepare(
        'SELECT school_id FROM school_users
         WHERE user_id = :user_id AND is_active = 1
         ORDER BY created_at ASC, school_id ASC
         LIMIT 1'
    );
    $schoolStmt->execute(['user_id' => $userId]);
    $schoolIdValue = (int)($schoolStmt->fetchColumn() ?: 0);
    $schoolId = $schoolIdValue > 0 ? $schoolIdValue : null;
}

audit_event('password_recovery_completed', 'user', $userId, [
    'method' => 'email_token',
], $schoolId, $userId);

json_response([
    'ok' => true,
    'message' => 'Пароль изменён. Теперь можно войти с новым паролем.',
]);
