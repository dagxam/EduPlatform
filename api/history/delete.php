<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$historyId = (int)($data['history_id'] ?? 0);
if ($historyId < 1) {
    json_response(['ok' => false, 'error' => 'Не указана запись истории.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$sql = 'DELETE FROM audit_log WHERE id = :id AND school_id = :school_id';
$params = ['id' => $historyId, 'school_id' => $schoolId];
if (!$manager) {
    $sql .= ' AND user_id = :user_id';
    $params['user_id'] = (int)$user['id'];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
if ($stmt->rowCount() !== 1) {
    json_response(['ok' => false, 'error' => 'Запись не найдена или недоступна для удаления.'], 404);
}

json_response(['ok' => true]);
