<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$schoolId = (int)($data['school_id'] ?? 0);

if ($schoolId === 0) {
    unset($_SESSION['active_school_id']);
    json_response(['ok' => true, 'active_school_id' => null]);
}

if (!can_access_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет доступа к этой школе.'], 403);
}

$_SESSION['active_school_id'] = $schoolId;
audit_event('school_selected', 'school', $schoolId, [], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'active_school_id' => $schoolId]);
