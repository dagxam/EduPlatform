<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_copy.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, true);
$data = read_json_body();
$transferId = (int)($data['transfer_id'] ?? 0);
$action = trim((string)($data['action'] ?? ''));

if ($transferId < 1 || !in_array($action, ['accept', 'reject'], true)) {
    json_response(['ok' => false, 'error' => 'Не выбрано действие с материалом.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT t.*, source.name AS source_school_name, subj.name AS subject_name
     FROM school_material_transfers t
     JOIN schools source ON source.id = t.source_school_id
     JOIN subjects subj ON subj.id = t.subject_id
     WHERE t.id = :id AND t.target_school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'id' => $transferId,
    'school_id' => $schoolId,
]);
$transfer = $stmt->fetch();
if (!$transfer) {
    json_response(['ok' => false, 'error' => 'Входящий материал не найден.'], 404);
}
if ((string)$transfer['status'] !== 'pending') {
    json_response(['ok' => false, 'error' => 'Этот пакет уже обработан.'], 409);
}

if ($action === 'reject') {
    $stmt = $pdo->prepare(
        'UPDATE school_material_transfers
         SET status = "rejected",
             resolved_at = CURRENT_TIMESTAMP,
             resolved_by = :user_id
         WHERE id = :id AND status = "pending"'
    );
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'id' => $transferId,
    ]);

    audit_event('school_material_transfer_rejected', 'material_transfer', $transferId, [
        'source_school_id' => (int)$transfer['source_school_id'],
        'subject_id' => (int)$transfer['subject_id'],
    ], $schoolId, (int)$user['id']);

    json_response([
        'ok' => true,
        'status' => 'rejected',
        'transfer_id' => $transferId,
    ]);
}

$stmt = $pdo->prepare(
    'SELECT m.assignment_id, a.*
     FROM school_material_transfer_assignments m
     JOIN assignments a ON a.id = m.assignment_id
     WHERE m.transfer_id = :transfer_id
       AND a.school_id = :source_school_id
       AND a.subject_id = :subject_id
     ORDER BY m.position, m.assignment_id'
);
$stmt->execute([
    'transfer_id' => $transferId,
    'source_school_id' => (int)$transfer['source_school_id'],
    'subject_id' => (int)$transfer['subject_id'],
]);
$assignments = $stmt->fetchAll();

$targetOwnerId = material_target_owner_id($pdo, $schoolId, (int)$user['id']);
$copiedFiles = [];
$copied = [];
$skipped = [];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO school_subjects (school_id, subject_id, is_active)
         VALUES (:school_id, :subject_id, 1)'
        . db_upsert_clause($pdo, ['school_id', 'subject_id'], ['is_active'])
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'subject_id' => (int)$transfer['subject_id'],
    ]);

    foreach ($assignments as $assignment) {
        $result = material_copy_assignment(
            $pdo,
            $assignment,
            (int)$transfer['source_school_id'],
            $schoolId,
            $targetOwnerId,
            (int)($transfer['sender_user_id'] ?: $user['id']),
            $copiedFiles
        );
        if ($result['copied']) $copied[] = $result;
        else $skipped[] = $result;
    }

    $stmt = $pdo->prepare(
        'UPDATE school_material_transfers
         SET status = "accepted",
             resolved_at = CURRENT_TIMESTAMP,
             resolved_by = :user_id
         WHERE id = :id AND status = "pending"'
    );
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'id' => $transferId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Пакет уже был обработан другим администратором.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($copiedFiles as $path) {
        @unlink($path);
    }
    throw $e;
}

audit_event('school_material_transfer_accepted', 'material_transfer', $transferId, [
    'source_school_id' => (int)$transfer['source_school_id'],
    'subject_id' => (int)$transfer['subject_id'],
    'copied_count' => count($copied),
    'skipped_count' => count($skipped),
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'status' => 'accepted',
    'transfer_id' => $transferId,
    'subject' => [
        'id' => (int)$transfer['subject_id'],
        'name' => (string)$transfer['subject_name'],
    ],
    'copied_assignments' => $copied,
    'skipped_assignments' => $skipped,
]);
