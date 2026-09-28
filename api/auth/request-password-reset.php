<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$identity = trim((string)($data['identity'] ?? ''));
$identity = function_exists('mb_strtolower') ? mb_strtolower($identity) : strtolower($identity);

if ($identity === '') {
    json_response(['ok' => false, 'error' => 'Введите email или логин.'], 422);
}

throttle_check('password_recovery', $identity, 5, 900);
throttle_failure('password_recovery', $identity, 5, 900, 900);

$pdo = app_db();
$pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at < :now OR used_at IS NOT NULL')
    ->execute(['now' => time()]);

$stmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, login_name, role, is_active, is_platform_admin
     FROM users
     WHERE (email = :identity OR login_name = :identity)
       AND role IN ("admin", "teacher")
     LIMIT 1'
);
$stmt->execute(['identity' => $identity]);
$user = $stmt->fetch();

if ($user && (int)$user['is_active'] === 1 && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = time();
    $expiresAt = $now + 1800;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = :used_at
             WHERE user_id = :user_id AND used_at IS NULL'
        )->execute([
            'used_at' => $now,
            'user_id' => (int)$user['id'],
        ]);

        $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        )->execute([
            'user_id' => (int)$user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        $resetUrl = uvoria_app_url() . '/login.html#reset=' . rawurlencode($token);
        $subject = 'Восстановление доступа к UROVIA';
        $name = trim((string)$user['first_name']);
        $body = "Здравствуйте" . ($name !== '' ? ', ' . $name : '') . "!\n\n"
            . "Для вашей учётной записи UROVIA запрошено восстановление доступа.\n\n"
            . "Установить новый пароль: " . $resetUrl . "\n\n"
            . "Ссылка действует 30 минут и может быть использована только один раз.\n"
            . "Если вы не запрашивали восстановление, просто проигнорируйте это письмо.\n\n"
            . "UROVIA";

        if (!send_uvoria_email((string)$user['email'], $subject, $body)) {
            throw new RuntimeException('MAIL_SEND_FAILED');
        }

        $pdo->commit();

        $schoolId = null;
        if ((int)($user['is_platform_admin'] ?? 0) !== 1) {
            $schoolStmt = $pdo->prepare(
                'SELECT school_id FROM school_users
                 WHERE user_id = :user_id AND is_active = 1
                 ORDER BY created_at ASC, school_id ASC
                 LIMIT 1'
            );
            $schoolStmt->execute(['user_id' => (int)$user['id']]);
            $schoolIdValue = (int)($schoolStmt->fetchColumn() ?: 0);
            $schoolId = $schoolIdValue > 0 ? $schoolIdValue : null;
        }

        audit_event('password_recovery_requested', 'user', (int)$user['id'], [
            'delivery' => 'email',
        ], $schoolId, (int)$user['id']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('UROVIA password recovery request failed: ' . $e->getMessage());
    }
}

json_response([
    'ok' => true,
    'message' => 'Если такая учётная запись существует, письмо со ссылкой для восстановления уже отправлено.',
]);
