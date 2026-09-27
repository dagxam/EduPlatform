<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/materials/_copy.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$targetSchoolId = require_active_school($user, true);
$data = read_json_body();
$itemId = (int)($data['item_id'] ?? 0);
if ($itemId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбран материал библиотеки.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT li.*, a.*, li.id AS library_item_id,
            li.source_school_id AS library_source_school_id,
            li.source_assignment_id AS library_source_assignment_id
     FROM library_items li
     JOIN assignments a ON a.id = li.source_assignment_id
     WHERE li.id = :id AND li.status = "published"
     LIMIT 1'
);
$stmt->execute(['id' => $itemId]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'Опубликованный материал не найден.'], 404);
}

$sourceSchoolId = (int)$row['library_source_school_id'];
if ($sourceSchoolId === $targetSchoolId) {
    json_response(['ok' => false, 'error' => 'Это материал вашей школы — импорт не требуется.'], 409);
}

$stmt = $pdo->prepare(
    'SELECT target_assignment_id FROM library_imports
     WHERE library_item_id = :item_id AND target_school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['item_id' => $itemId, 'school_id' => $targetSchoolId]);
$existingId = (int)($stmt->fetchColumn() ?: 0);
if ($existingId > 0) {
    json_response([
        'ok' => true,
        'already_imported' => true,
        'assignment_id' => $existingId,
        'message' => 'Этот материал уже импортирован в вашу школу.',
    ]);
}

$assignment = $row;
$assignment['id'] = (int)$row['library_source_assignment_id'];
$assignment['school_id'] = $sourceSchoolId;

$targetOwnerId = material_target_owner_id($pdo, $targetSchoolId, (int)$user['id']);
$copiedFiles = [];

$pdo->beginTransaction();
try {
    if (!empty($row['subject_id'])) {
        $stmt = $pdo->prepare(
            'INSERT INTO school_subjects (school_id, subject_id, is_active)
             VALUES (:school_id, :subject_id, 1)
             ON CONFLICT(school_id, subject_id) DO UPDATE SET is_active = 1'
        );
        $stmt->execute([
            'school_id' => $targetSchoolId,
            'subject_id' => (int)$row['subject_id'],
        ]);
    }

    $result = material_copy_assignment(
        $pdo,
        $assignment,
        $sourceSchoolId,
        $targetSchoolId,
        $targetOwnerId,
        (int)($row['submitted_by'] ?: $user['id']),
        $copiedFiles
    );

    $targetAssignmentId = (int)$result['target_id'];

    $stmt = $pdo->prepare(
        'INSERT INTO library_imports
         (library_item_id, target_school_id, target_assignment_id, imported_by)
         VALUES (:item_id, :school_id, :assignment_id, :user_id)
         ON CONFLICT(library_item_id, target_school_id) DO UPDATE SET
           target_assignment_id = excluded.target_assignment_id,
           imported_by = excluded.imported_by'
    );
    $stmt->execute([
        'item_id' => $itemId,
        'school_id' => $targetSchoolId,
        'assignment_id' => $targetAssignmentId,
        'user_id' => (int)$user['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($copiedFiles as $path) @unlink($path);
    throw $e;
}

audit_event('library_item_imported', 'library_item', $itemId, [
    'source_school_id' => $sourceSchoolId,
    'target_assignment_id' => $targetAssignmentId,
], $targetSchoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment_id' => $targetAssignmentId,
    'copied' => (bool)$result['copied'],
    'message' => 'Материал добавлен в вашу школу как независимый черновик.',
], 201);
