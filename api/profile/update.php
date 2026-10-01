<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
require_privacy_confirmation(
    $data,
    'privacy_basis_confirmed',
    'Подтвердите законное основание обработки данных профиля.'
);
$targetId = max(1, (int)($data['user_id'] ?? $user['id']));
$self = $targetId === (int)$user['id'];
$pdo = app_db();
$schoolId = current_school_id();

if (!$self) {
    if ($schoolId === null || !can_manage_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет прав на изменение профиля.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT role FROM school_users
         WHERE school_id = :school_id AND user_id = :user_id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'user_id' => $targetId]);
    $memberRole = $stmt->fetchColumn();
    if ($memberRole === false || (!is_platform_admin($user) && (string)$memberRole !== 'teacher')) {
        json_response(['ok' => false, 'error' => 'Этот профиль может изменить только главный администратор UVORIA.'], 403);
    }
}

$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$middleName = trim((string)($data['middle_name'] ?? ''));
$email = normalize_email((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));

if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите имя, фамилию и корректный email.'], 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($middleName) : strlen($middleName)) > 100) {
    json_response(['ok' => false, 'error' => 'Отчество слишком длинное.'], 422);
}
if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) {
    json_response(['ok' => false, 'error' => 'Проверьте формат телефона.'], 422);
}

try {
    $stmt = $pdo->prepare(
        'UPDATE users
         SET first_name = :first_name,
             last_name = :last_name,
             middle_name = :middle_name,
             email = :email,
             phone = :phone,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND role IN ("admin", "teacher")'
    );
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'id' => $targetId,
    ]);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Этот email уже используется другим аккаунтом.'], 409);
    }
    throw $e;
}

audit_event('staff_profile_updated', 'user', $targetId, [
    'self_edit' => $self,
] + privacy_audit_metadata($self ? 'consent' : 'lawful_basis_confirmed'), $schoolId, (int)$user['id']);

json_response(['ok' => true]);
