<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}
$data = read_json_body();
$classId = (int)($data['class_id'] ?? 0);
$open = !empty($data['registration_open']) ? 1 : 0;

$pdo = app_db();
$sql = 'SELECT 1 FROM classes WHERE id = :id';
$params = ['id' => $classId];
if ($user['role'] === 'teacher') {
    $sql .= ' AND teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$stmt = $pdo->prepare('UPDATE class_access SET registration_open = :open WHERE class_id = :class_id');
$stmt->execute(['open' => $open, 'class_id' => $classId]);

json_response(['ok' => true, 'registration_open' => $open]);
