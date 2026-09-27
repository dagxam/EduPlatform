<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT t.id, t.status, t.created_at, t.resolved_at,
            t.source_school_id, source.name AS source_school_name,
            t.target_school_id,
            t.subject_id, subj.name AS subject_name,
            t.sender_user_id,
            sender.first_name AS sender_first_name,
            sender.last_name AS sender_last_name,
            resolver.first_name AS resolver_first_name,
            resolver.last_name AS resolver_last_name
     FROM school_material_transfers t
     JOIN schools source ON source.id = t.source_school_id
     JOIN subjects subj ON subj.id = t.subject_id
     LEFT JOIN users sender ON sender.id = t.sender_user_id
     LEFT JOIN users resolver ON resolver.id = t.resolved_by
     WHERE t.target_school_id = :school_id
     ORDER BY CASE t.status WHEN "pending" THEN 0 WHEN "accepted" THEN 1 ELSE 2 END,
              t.created_at DESC,
              t.id DESC'
);
$stmt->execute(['school_id' => $schoolId]);
$transfers = $stmt->fetchAll();

$assignmentStmt = $pdo->prepare(
    'SELECT assignment_id, position, title_snapshot, type_snapshot,
            questions_count_snapshot, source_format_snapshot
     FROM school_material_transfer_assignments
     WHERE transfer_id = :transfer_id
     ORDER BY position, assignment_id'
);

foreach ($transfers as &$transfer) {
    $assignmentStmt->execute(['transfer_id' => (int)$transfer['id']]);
    $transfer['assignments'] = $assignmentStmt->fetchAll();
    $transfer['id'] = (int)$transfer['id'];
    $transfer['source_school_id'] = (int)$transfer['source_school_id'];
    $transfer['target_school_id'] = (int)$transfer['target_school_id'];
    $transfer['subject_id'] = (int)$transfer['subject_id'];
    $transfer['assignment_count'] = count($transfer['assignments']);
}
unset($transfer);

$pendingCount = 0;
foreach ($transfers as $transfer) {
    if ((string)$transfer['status'] === 'pending') $pendingCount++;
}

json_response([
    'ok' => true,
    'pending_count' => $pendingCount,
    'transfers' => $transfers,
]);
