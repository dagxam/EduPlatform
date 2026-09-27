<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$sourceSchoolId = require_active_school($user, true);
$data = read_json_body();
$subjectId = (int)($data['subject_id'] ?? 0);
$targetSchoolId = (int)($data['target_school_id'] ?? 0);
$assignmentIds = is_array($data['assignment_ids'] ?? null) ? $data['assignment_ids'] : [];

$assignmentIds = array_values(array_unique(array_filter(
    array_map('intval', $assignmentIds),
    static fn(int $id): bool => $id > 0
)));

if ($subjectId < 1 || $targetSchoolId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите предмет и школу-получателя.'], 422);
}
if ($targetSchoolId === $sourceSchoolId) {
    json_response(['ok' => false, 'error' => 'Нельзя отправить предмет в ту же школу.'], 422);
}
if (count($assignmentIds) > 200) {
    json_response(['ok' => false, 'error' => 'За одну передачу можно отправить не более 200 заданий.'], 422);
}

$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT s.id, s.name
     FROM school_subjects ss
     JOIN subjects s ON s.id = ss.subject_id
     WHERE ss.school_id = :school_id
       AND ss.subject_id = :subject_id
       AND ss.is_active = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $sourceSchoolId,
    'subject_id' => $subjectId,
]);
$subject = $stmt->fetch();
if (!$subject) {
    json_response(['ok' => false, 'error' => 'Предмет не найден в выбранной школе.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT id, name
     FROM schools
     WHERE id = :id AND status = "active"
     LIMIT 1'
);
$stmt->execute(['id' => $targetSchoolId]);
$targetSchool = $stmt->fetch();
if (!$targetSchool) {
    json_response(['ok' => false, 'error' => 'Школа-получатель не найдена.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.is_active = 1
       AND u.is_active = 1
       AND su.role IN ("school_admin", "owner")'
);
$stmt->execute(['school_id' => $targetSchoolId]);
if ((int)$stmt->fetchColumn() < 1) {
    json_response([
        'ok' => false,
        'error' => 'У школы-получателя нет активного администратора. Сначала назначьте администратора.',
    ], 409);
}

$assignments = [];
if ($assignmentIds) {
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.type,
                ai.source_format,
                COUNT(DISTINCT q.id) AS questions_count
         FROM assignments a
         LEFT JOIN assignment_imports ai ON ai.assignment_id = a.id
         LEFT JOIN questions q ON q.assignment_id = a.id
         WHERE a.school_id = ?
           AND a.subject_id = ?
           AND a.id IN ($placeholders)
         GROUP BY a.id
         ORDER BY a.id"
    );
    $stmt->execute(array_merge([$sourceSchoolId, $subjectId], $assignmentIds));
    $assignments = $stmt->fetchAll();

    if (count($assignments) !== count($assignmentIds)) {
        json_response([
            'ok' => false,
            'error' => 'Одно или несколько заданий не относятся к выбранному предмету этой школы.',
        ], 422);
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO school_material_transfers
         (source_school_id, target_school_id, subject_id, sender_user_id, status)
         VALUES
         (:source_school_id, :target_school_id, :subject_id, :sender_user_id, "pending")'
    );
    $stmt->execute([
        'source_school_id' => $sourceSchoolId,
        'target_school_id' => $targetSchoolId,
        'subject_id' => $subjectId,
        'sender_user_id' => (int)$user['id'],
    ]);
    $transferId = (int)$pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO school_material_transfer_assignments
         (transfer_id, assignment_id, position, title_snapshot, type_snapshot,
          questions_count_snapshot, source_format_snapshot)
         VALUES
         (:transfer_id, :assignment_id, :position, :title_snapshot, :type_snapshot,
          :questions_count_snapshot, :source_format_snapshot)'
    );

    foreach ($assignments as $index => $assignment) {
        $insert->execute([
            'transfer_id' => $transferId,
            'assignment_id' => (int)$assignment['id'],
            'position' => $index + 1,
            'title_snapshot' => (string)$assignment['title'],
            'type_snapshot' => (string)$assignment['type'],
            'questions_count_snapshot' => (int)$assignment['questions_count'],
            'source_format_snapshot' => $assignment['source_format'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('school_material_transfer_sent', 'material_transfer', $transferId, [
    'target_school_id' => $targetSchoolId,
    'subject_id' => $subjectId,
    'assignment_ids' => $assignmentIds,
    'assignment_count' => count($assignments),
], $sourceSchoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'transfer' => [
        'id' => $transferId,
        'status' => 'pending',
        'assignment_count' => count($assignments),
    ],
    'subject' => [
        'id' => $subjectId,
        'name' => (string)$subject['name'],
    ],
    'target_school' => [
        'id' => $targetSchoolId,
        'name' => (string)$targetSchool['name'],
    ],
]);
