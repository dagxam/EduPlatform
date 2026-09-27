<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$pdo = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT assignment_review_required
         FROM schools
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $schoolId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Школа не найдена.'], 404);
    }

    json_response([
        'ok' => true,
        'settings' => [
            'assignment_review_required' => (int)$row['assignment_review_required'] === 1,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$required = !empty($data['assignment_review_required']) ? 1 : 0;

$stmt = $pdo->prepare(
    'UPDATE schools
     SET assignment_review_required = :required,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'required' => $required,
    'id' => $schoolId,
]);

audit_event('school_assignment_review_setting_changed', 'school', $schoolId, [
    'assignment_review_required' => (bool)$required,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'settings' => [
        'assignment_review_required' => (bool)$required,
    ],
]);
