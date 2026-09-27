<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$transferId = (int)($_GET['transfer_id'] ?? 0);
if ($transferId < 1) {
    json_response(['ok' => false, 'error' => 'Не указан входящий пакет.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT t.id, t.status, t.source_school_id, t.subject_id,
            source.name AS source_school_name,
            subj.name AS subject_name
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
    json_response(['ok' => false, 'error' => 'Входящий пакет не найден.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.description, a.type, a.variant_count,
            a.focus_policy, a.time_limit_minutes,
            m.position
     FROM school_material_transfer_assignments m
     JOIN assignments a ON a.id = m.assignment_id
     WHERE m.transfer_id = :transfer_id
     ORDER BY m.position, m.assignment_id'
);
$stmt->execute(['transfer_id' => $transferId]);
$assignments = $stmt->fetchAll();

$questionStmt = $pdo->prepare(
    'SELECT id, type, interaction_type, text, points, position, correct_text, settings_json
     FROM questions
     WHERE assignment_id = :assignment_id
     ORDER BY position, id'
);
$optionStmt = $pdo->prepare(
    'SELECT id, text, is_correct, position
     FROM question_options
     WHERE question_id = :question_id
     ORDER BY position, id'
);
$assetStmt = $pdo->prepare(
    'SELECT id, original_name, mime_type, position
     FROM question_assets
     WHERE question_id = :question_id
     ORDER BY position, id'
);

foreach ($assignments as &$assignment) {
    $questionStmt->execute(['assignment_id' => (int)$assignment['id']]);
    $questions = $questionStmt->fetchAll();

    foreach ($questions as &$question) {
        $optionStmt->execute(['question_id' => (int)$question['id']]);
        $question['options'] = $optionStmt->fetchAll();

        $assetStmt->execute(['question_id' => (int)$question['id']]);
        $assets = $assetStmt->fetchAll();
        foreach ($assets as &$asset) {
            $asset['url'] = './api/materials/asset.php?transfer_id=' . $transferId . '&asset_id=' . (int)$asset['id'];
        }
        unset($asset);
        $question['assets'] = $assets;

        $settings = json_decode((string)($question['settings_json'] ?? ''), true);
        $question['settings'] = is_array($settings) ? $settings : [];
        unset($question['settings_json']);
    }
    unset($question);

    $assignment['questions'] = $questions;
}
unset($assignment);

json_response([
    'ok' => true,
    'transfer' => [
        'id' => (int)$transfer['id'],
        'status' => (string)$transfer['status'],
        'source_school_name' => (string)$transfer['source_school_name'],
        'subject_name' => (string)$transfer['subject_name'],
    ],
    'assignments' => $assignments,
]);
