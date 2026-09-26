<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$attemptId = (int)($data['attempt_id'] ?? 0);
$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);

if ($attempt['status'] !== 'in_progress') {
    json_response(['ok' => true, 'active' => false, 'status' => $attempt['status']]);
}

$pdo->prepare('UPDATE attempts SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id')
    ->execute(['id' => $attemptId]);

json_response(['ok' => true, 'active' => true]);
