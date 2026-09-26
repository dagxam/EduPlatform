<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, false);
if (!can_access_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
}

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
if ($name === '') {
    json_response(['ok' => false, 'error' => 'Укажите название предмета.'], 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
    json_response(['ok' => false, 'error' => 'Название предмета слишком длинное.'], 422);
}

$pdo = app_db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE name = :name COLLATE NOCASE LIMIT 1');
    $stmt->execute(['name' => $name]);
    $subjectId = (int)($stmt->fetchColumn() ?: 0);

    if ($subjectId < 1) {
        $stmt = $pdo->prepare('INSERT INTO subjects (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        $subjectId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO school_subjects (school_id, subject_id, is_active)
         VALUES (:school_id, :subject_id, 1)
         ON CONFLICT(school_id, subject_id) DO UPDATE SET is_active = 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'subject_id' => $subjectId]);

    if (can_teach_school($user, $schoolId)) {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO teacher_subjects (school_id, teacher_id, subject_id)
             VALUES (:school_id, :teacher_id, :subject_id)'
        );
        $stmt->execute([
            'school_id' => $schoolId,
            'teacher_id' => (int)$user['id'],
            'subject_id' => $subjectId,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('school_subject_added', 'subject', $subjectId, [
    'name' => $name,
    'created_by_role' => (string)($user['role'] ?? ''),
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'subject' => ['id' => $subjectId, 'name' => $name],
], 201);
