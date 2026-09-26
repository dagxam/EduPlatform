<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
$city = trim((string)($data['city'] ?? ''));

if ($name === '') {
    json_response(['ok' => false, 'error' => 'Укажите название школы.'], 422);
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
        'INSERT INTO school_users (school_id, user_id, role)
         VALUES (:school_id, :user_id, :role)'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => (int)$user['id'],
        'role' => 'owner',
    ]);

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO school_subjects (school_id, subject_id, is_active)
         SELECT :school_id, id, 1 FROM subjects'
    );
    $stmt->execute(['school_id' => $schoolId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$_SESSION['active_school_id'] = $schoolId;
audit_event('school_created', 'school', $schoolId, ['name' => $name], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'school' => [
        'id' => $schoolId,
        'name' => $name,
        'city' => $city,
        'slug' => $slug,
        'membership_role' => 'owner',
    ],
    'active_school_id' => $schoolId,
], 201);
