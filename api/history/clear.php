<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

if ($manager) {
    $stmt = $pdo->prepare('DELETE FROM audit_log WHERE school_id = :school_id');
    $stmt->execute(['school_id' => $schoolId]);
} else {
    $stmt = $pdo->prepare(
        'DELETE FROM audit_log WHERE school_id = :school_id AND user_id = :user_id'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'user_id' => (int)$user['id'],
    ]);
}

json_response([
    'ok' => true,
    'deleted' => $stmt->rowCount(),
    'scope' => $manager ? 'school' : 'own',
]);
