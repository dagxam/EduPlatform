<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, true);
$data = read_json_body();
$itemId = (int)($data['item_id'] ?? 0);
$action = trim((string)($data['action'] ?? ''));

if ($itemId < 1 || !in_array($action, ['approve', 'reject', 'withdraw'], true)) {
    json_response(['ok' => false, 'error' => 'Некорректное действие.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT * FROM library_items
     WHERE id = :id AND source_school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['id' => $itemId, 'school_id' => $schoolId]);
$item = $stmt->fetch();
if (!$item) {
    json_response(['ok' => false, 'error' => 'Публикация не найдена в выбранной школе.'], 404);
}

$status = match ($action) {
    'approve' => 'published',
    'reject' => 'rejected',
    default => 'withdrawn',
};

$stmt = $pdo->prepare(
    'UPDATE library_items
     SET status = :status,
         approved_by = :approved_by,
         published_at = CASE WHEN :status2 = "published" THEN CURRENT_TIMESTAMP ELSE NULL END,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'status' => $status,
    'status2' => $status,
    'approved_by' => (int)$user['id'],
    'id' => $itemId,
]);

audit_event('library_item_' . $status, 'library_item', $itemId, [
    'assignment_id' => (int)$item['source_assignment_id'],
], $schoolId, (int)$user['id']);

json_response(['ok' => true, 'status' => $status]);
