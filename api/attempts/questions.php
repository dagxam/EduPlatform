<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_helpers.php';

$user = require_user(['student']);
$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId < 1) {
    json_response(['ok' => false, 'error' => 'Не указана попытка.'], 422);
}

$pdo = app_db();
$attempt = attempt_for_student($pdo, $attemptId, (int)$user['id']);
if ((string)$attempt['status'] !== 'in_progress') {
    json_response([
        'ok' => false,
        'error' => 'После завершения попытки вопросы теста ученику больше недоступны.',
        'code' => 'ATTEMPT_ALREADY_FINISHED',
    ], 409);
}

$questionOrder = array_map('intval', decoded_json_array($attempt['question_order_json'] ?? null));
$optionOrder = decoded_json_array($attempt['option_order_json'] ?? null);
$structuredOrder = decoded_json_array($attempt['structured_order_json'] ?? null);

$stmt = $pdo->prepare(
    'SELECT q.id, q.type, q.text, q.points, q.position, q.interaction_type, q.settings_json
     FROM questions q
     WHERE q.assignment_id = :assignment_id
     ORDER BY q.position, q.id'
);
$stmt->execute(['assignment_id' => (int)$attempt['assignment_id']]);
$rows = $stmt->fetchAll();

$byId = [];
foreach ($rows as $row) {
    $byId[(int)$row['id']] = $row;
}
if (!$questionOrder) {
    $questionOrder = array_keys($byId);
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

    $savedOptionOrder = array_map('intval', (array)($optionOrder[(string)$questionId] ?? []));
    if (!$savedOptionOrder) $savedOptionOrder = array_keys($optionsById);
    $options = [];
    foreach ($savedOptionOrder as $optionId) {
        if (isset($optionsById[$optionId])) $options[] = $optionsById[$optionId];
    }

    $settings = json_decode((string)($row['settings_json'] ?? ''), true);
    $settings = is_array($settings) ? $settings : [];
    $safeStructured = null;
    $savedStructured = is_array($structuredOrder[(string)$questionId] ?? null)
        ? $structuredOrder[(string)$questionId]
        : [];

    if ($interaction === 'order' && is_array($settings['items'] ?? null)) {
        $keys = array_map('strval', (array)($savedStructured['items'] ?? array_keys($settings['items'])));
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
        $leftKeys = array_map('strval', (array)($savedStructured['left'] ?? array_keys($leftSource)));
        $rightKeys = array_map('strval', (array)($savedStructured['right'] ?? array_keys($rightSource)));

        $left = [];
        foreach ($leftKeys as $key) {
            if (array_key_exists($key, $leftSource)) {
                $left[] = ['key' => $key, 'text' => (string)$leftSource[$key]];
            }
        }
        $right = [];
        foreach ($rightKeys as $key) {
            if (array_key_exists($key, $rightSource)) {
                $right[] = ['key' => $key, 'text' => (string)$rightSource[$key]];
            }
        }
        $safeStructured = ['left' => $left, 'right' => $right];
    } elseif ($interaction === 'correction' && isset($settings['original_text'])) {
        $safeStructured = ['original_text' => (string)$settings['original_text']];
    } elseif ($interaction === 'image_answer' && isset($settings['answer_hint'])) {
        $safeStructured = ['answer_hint' => (string)$settings['answer_hint']];
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

$stmt = $pdo->prepare(
    'SELECT question_id, answer_text
     FROM answers
     WHERE attempt_id = :attempt_id'
);
$stmt->execute(['attempt_id' => $attemptId]);
$savedAnswers = [];
foreach ($stmt->fetchAll() as $answer) {
    $savedAnswers[(string)(int)$answer['question_id']] = (string)($answer['answer_text'] ?? '');
}

json_response([
    'ok' => true,
    'attempt' => [
        'id' => (int)$attempt['id'],
        'assignment_id' => (int)$attempt['assignment_id'],
        'status' => (string)$attempt['status'],
        'variant_label' => (string)($attempt['variant_label'] ?? 'A'),
        'variant_index' => (int)($attempt['variant_index'] ?? 0),
        'started_at' => (string)$attempt['started_at'],
        'time_limit_minutes' => $attempt['time_limit_minutes'] !== null ? (int)$attempt['time_limit_minutes'] : null,
        'focus_policy' => (string)$attempt['focus_policy'],
    ],
    'questions' => $questions,
    'saved_answers' => $savedAnswers,
]);
