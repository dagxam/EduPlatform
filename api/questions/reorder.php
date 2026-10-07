<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$ids = array_values(array_unique(array_filter(
    array_map('intval', is_array($data['question_ids'] ?? null) ? $data['question_ids'] : []),
    static fn(int $id): bool => $id > 0
)));

$pdo = app_db();
$assignment = question_editor_assignment($pdo, $user, $assignmentId, true);

$stmt = $pdo->prepare('SELECT id FROM questions WHERE assignment_id = :assignment_id AND is_active = 1 ORDER BY position, id');
$stmt->execute(['assignment_id' => $assignmentId]);
$existing = array_map('intval', array_column($stmt->fetchAll(), 'id'));

$sortedA = $existing; $sortedB = $ids;
sort($sortedA); sort($sortedB);
if ($sortedA !== $sortedB) {
    json_response(['ok' => false, 'error' => 'Список вопросов для сортировки изменился. Обновите конструктор.'], 409);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE questions SET position = :position WHERE id = :id AND is_active = 1');
    foreach ($ids as $index => $id) {
        $stmt->execute(['position' => $index + 1, 'id' => $id]);
    }
    $pdo->prepare('UPDATE assignments SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $assignmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('questions_reordered', 'assignment', $assignmentId, ['question_ids' => $ids], (int)$assignment['school_id'], (int)$user['id']);
json_response(['ok' => true]);
