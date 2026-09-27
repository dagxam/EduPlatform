<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';

$user = require_user(['admin', 'teacher']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);

if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);

$stmt = $pdo->prepare(
    'SELECT a.id, a.school_id, a.teacher_id, a.subject_id, a.title, a.description,
            a.focus_policy, a.time_limit_minutes,
            s.name AS subject_name
     FROM assignments a
     LEFT JOIN subjects s ON s.id = a.subject_id
     WHERE a.id = :id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'school_id' => $schoolId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено в выбранной школе.'], 404);
}

if (!can_manage_school($user, $schoolId) && (int)$assignment['teacher_id'] !== (int)$user['id']) {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM teacher_subjects
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => (int)$assignment['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
    }
}

$stmt = $pdo->prepare(
    'SELECT q.id, q.type, q.text, q.points, q.position, q.interaction_type, q.settings_json
     FROM questions q
     WHERE q.assignment_id = :assignment_id
     ORDER BY q.position, q.id'
);
$stmt->execute(['assignment_id' => $assignmentId]);
$rows = $stmt->fetchAll();

$questionOrder = array_map('intval', array_column($rows, 'id'));

$byId = [];
foreach ($rows as $row) {
    $byId[(int)$row['id']] = $row;
}

$optionStmt = $pdo->prepare(
    'SELECT id, text, position
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

$questions = [];
foreach ($questionOrder as $questionId) {
    if (!isset($byId[$questionId])) continue;
    $row = $byId[$questionId];

    $interaction = (string)($row['interaction_type'] ?: $row['type']);
    if ($interaction === 'ordering') $interaction = 'order';
    if ($interaction === 'short_answer' || $interaction === 'image_answer') $interaction = 'text';

    $optionStmt->execute(['question_id' => $questionId]);
    $optionRows = $optionStmt->fetchAll();
    $optionsById = [];
    foreach ($optionRows as $option) {
        $optionsById[(int)$option['id']] = [
            'id' => (int)$option['id'],
            'text' => (string)$option['text'],
        ];
    }

    $optionIds = array_keys($optionsById);
    $options = [];
    foreach ($optionIds as $optionId) {
        if (isset($optionsById[$optionId])) $options[] = $optionsById[$optionId];
    }

    $settings = json_decode((string)($row['settings_json'] ?? ''), true);
    $settings = is_array($settings) ? $settings : [];
    $safeStructured = null;

    if ($interaction === 'order' && is_array($settings['items'] ?? null)) {
        $keys = array_map('strval', array_keys($settings['items']));
        $items = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings['items'])) {
                $items[] = ['key' => $key, 'text' => (string)$settings['items'][$key]];
            }
        }
        $safeStructured = ['items' => $items];
    } elseif ($interaction === 'matching') {
        $leftSource = is_array($settings['left'] ?? null) ? $settings['left'] : [];
        $rightSource = is_array($settings['right'] ?? null) ? $settings['right'] : [];
        $leftKeys = array_map('strval', array_keys($leftSource));
        $rightKeys = array_map('strval', array_keys($rightSource));
        $left = [];
        foreach ($leftKeys as $key) {
            $left[] = ['key' => $key, 'text' => (string)$leftSource[$key]];
        }
        $right = [];
        foreach ($rightKeys as $key) {
            $right[] = ['key' => $key, 'text' => (string)$rightSource[$key]];
        }
        $safeStructured = ['left' => $left, 'right' => $right];
    } elseif ($interaction === 'correction' && isset($settings['original_text'])) {
        $safeStructured = ['original_text' => (string)$settings['original_text']];
    }

    $assetStmt->execute(['question_id' => $questionId]);
    $assets = [];
    foreach ($assetStmt->fetchAll() as $asset) {
        $assets[] = [
            'id' => (int)$asset['id'],
            'original_name' => (string)($asset['original_name'] ?? ''),
            'mime_type' => (string)($asset['mime_type'] ?? ''),
            'url' => './api/questions/asset.php?id=' . (int)$asset['id'],
        ];
    }

    $questions[] = [
        'id' => $questionId,
        'type' => (string)$row['type'],
        'interaction_type' => $interaction,
        'text' => (string)$row['text'],
        'points' => (float)$row['points'],
        'options' => $options,
        'structured' => $safeStructured,
        'assets' => $assets,
    ];
}

json_response([
    'ok' => true,
    'assignment' => [
        'id' => (int)$assignment['id'],
        'title' => (string)$assignment['title'],
        'description' => $assignment['description'],
        'subject_name' => (string)($assignment['subject_name'] ?? ''),
        'focus_policy' => (string)($assignment['focus_policy'] ?? 'allow'),
        'time_limit_minutes' => $assignment['time_limit_minutes'] !== null ? (int)$assignment['time_limit_minutes'] : null,
    ],
    'preview' => [
        'does_not_save' => true,
    ],
    'questions' => $questions,
]);
