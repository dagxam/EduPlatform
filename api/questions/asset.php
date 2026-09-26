<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher', 'student']);
$assetId = (int)($_GET['id'] ?? 0);
if ($assetId < 1) {
    json_response(['ok' => false, 'error' => 'Изображение не указано.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT qa.id, qa.stored_name, qa.mime_type,
            a.id AS assignment_id, a.teacher_id, a.school_id, a.status
     FROM question_assets qa
     JOIN questions q ON q.id = qa.question_id
     JOIN assignments a ON a.id = q.assignment_id
     WHERE qa.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $assetId]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['ok' => false, 'error' => 'Изображение не найдено.'], 404);
}

$allowed = false;
if (($user['role'] ?? '') === 'student') {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM assignment_classes ac
         JOIN class_students cs ON cs.class_id = ac.class_id
         WHERE ac.assignment_id = :assignment_id
           AND cs.student_id = :student_id
           AND EXISTS (
             SELECT 1 FROM assignments a
             WHERE a.id = :assignment_id_status AND a.status = "published"
           )
         LIMIT 1'
    );
    $stmt->execute([
        'assignment_id' => (int)$row['assignment_id'],
        'student_id' => (int)$user['id'],
        'assignment_id_status' => (int)$row['assignment_id'],
    ]);
    $allowed = (bool)$stmt->fetchColumn();
} else {
    $schoolId = current_school_id();
    if ($schoolId !== null && (int)$row['school_id'] === $schoolId) {
        $allowed = can_manage_school($user, $schoolId) || (int)$row['teacher_id'] === (int)$user['id'];
    }
}

if (!$allowed) {
    json_response(['ok' => false, 'error' => 'Нет доступа к изображению.'], 403);
}

$storedName = basename((string)$row['stored_name']);
$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets' . DIRECTORY_SEPARATOR . $storedName;
if (!is_file($path)) {
    json_response(['ok' => false, 'error' => 'Файл изображения отсутствует.'], 404);
}

$mime = (string)($row['mime_type'] ?: 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline');
readfile($path);
exit;
