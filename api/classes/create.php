<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
$academicYear = trim((string)($data['academic_year'] ?? ''));
if ($name === '') {
    json_response(['ok' => false, 'error' => 'Укажите название класса.'], 422);
}

function generate_join_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM class_access WHERE join_code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
    } while ($stmt->fetchColumn());
    return $code;
}

$pdo = app_db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO classes (name, teacher_id, academic_year)
         VALUES (:name, :teacher_id, :academic_year)'
    );
    $stmt->execute([
        'name' => $name,
        'teacher_id' => (int)$user['id'],
        'academic_year' => $academicYear !== '' ? $academicYear : null,
    ]);
    $classId = (int)$pdo->lastInsertId();
    $code = generate_join_code($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO class_access (class_id, join_code, registration_open, registration_expires_at)
         VALUES (:class_id, :join_code, 0, NULL)'
    );
    $stmt->execute(['class_id' => $classId, 'join_code' => $code]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Класс с таким названием уже существует.'], 409);
    }
    throw $e;
}

json_response([
    'ok' => true,
    'class' => [
        'id' => $classId,
        'name' => $name,
        'academic_year' => $academicYear,
        'join_code' => $code,
        'registration_open' => 0,
        'registration_expires_at' => null,
        'students_count' => 0,
    ],
], 201);
