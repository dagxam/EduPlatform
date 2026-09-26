<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Школы может создавать только администратор UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
$city = trim((string)($data['city'] ?? ''));
$adminFirstName = trim((string)($data['admin_first_name'] ?? ''));
$adminLastName = trim((string)($data['admin_last_name'] ?? ''));
$adminEmail = normalize_email((string)($data['admin_email'] ?? ''));
$adminPassword = (string)($data['admin_password'] ?? '');

if ($name === '') {
    json_response(['ok' => false, 'error' => 'Укажите название школы.'], 422);
}
if ($adminFirstName === '' || $adminLastName === '') {
    json_response(['ok' => false, 'error' => 'Укажите имя и фамилию администратора школы.'], 422);
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email администратора школы.'], 422);
}
$passwordLength = function_exists('mb_strlen') ? mb_strlen($adminPassword) : strlen($adminPassword);
if ($passwordLength < 8) {
    json_response(['ok' => false, 'error' => 'Пароль администратора должен содержать минимум 8 символов.'], 422);
}

$pdo = app_db();
$pdo->beginTransaction();

try {
    $slug = 'school-' . bin2hex(random_bytes(4));

    $stmt = $pdo->prepare(
        'INSERT INTO schools (name, slug, city, created_by)
         VALUES (:name, :slug, :city, :created_by)'
    );
    $stmt->execute([
        'name' => $name,
        'slug' => $slug,
        'city' => $city !== '' ? $city : null,
        'created_by' => (int)$user['id'],
    ]);
    $schoolId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, role, is_platform_admin)
         VALUES (:first_name, :last_name, :email, :password_hash, "admin", 0)'
    );
    $stmt->execute([
        'first_name' => $adminFirstName,
        'last_name' => $adminLastName,
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
    ]);
    $schoolAdminId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO school_users (school_id, user_id, role, can_teach)
         VALUES (:school_id, :user_id, "school_admin", 0)'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => $schoolAdminId,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Пользователь с таким email уже существует.'], 409);
    }
    throw $e;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$_SESSION['active_school_id'] = $schoolId;
audit_event('school_created', 'school', $schoolId, [
    'name' => $name,
    'school_admin_id' => $schoolAdminId,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'school' => [
        'id' => $schoolId,
        'name' => $name,
        'city' => $city,
        'slug' => $slug,
        'admin' => [
            'id' => $schoolAdminId,
            'first_name' => $adminFirstName,
            'last_name' => $adminLastName,
            'email' => $adminEmail,
        ],
    ],
    'active_school_id' => $schoolId,
], 201);
