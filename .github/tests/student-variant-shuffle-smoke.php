<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

function variant_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "Student variant shuffle smoke failed: {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
variant_assert($schema !== false, 'schema.sql missing');
$pdo->exec($schema);
apply_schema_migrations($pdo);

$pdo->exec(
    "INSERT INTO users (id, first_name, last_name, email, password_hash, role)
     VALUES
     (5101, 'Teacher', 'Shuffle', 'shuffle-teacher@example.test', 'x', 'teacher'),
     (5102, 'Student', 'One', 'shuffle-one@example.test', 'x', 'student'),
     (5103, 'Student', 'Two', 'shuffle-two@example.test', 'x', 'student')"
);
$pdo->exec("INSERT INTO subjects (id, name) VALUES (5101, 'Shuffle Subject')");
$pdo->exec(
    "INSERT INTO assignments
     (id, teacher_id, subject_id, title, status, workflow_status)
     VALUES (5101, 5101, 5101, 'Shuffle assignment', 'published', 'assigned')"
);

$questionInsert = $pdo->prepare(
    'INSERT INTO questions
     (id, assignment_id, type, text, points, position, correct_text, interaction_type, settings_json, is_active)
     VALUES (:id, 5101, :type, :text, 1, :position, :correct_text, :interaction_type, :settings_json, 1)'
);

$questionInsert->execute([
    'id' => 5101,
    'type' => 'single',
    'text' => 'Choice',
    'position' => 1,
    'correct_text' => null,
    'interaction_type' => 'single',
    'settings_json' => null,
]);
$questionInsert->execute([
    'id' => 5102,
    'type' => 'text',
    'text' => 'Ordering',
    'position' => 2,
    'correct_text' => json_encode(['1','2','3'], JSON_UNESCAPED_UNICODE),
    'interaction_type' => 'order',
    'settings_json' => json_encode([
        'items' => ['1' => 'Первый', '2' => 'Второй', '3' => 'Третий'],
        'correct_order' => ['1','2','3'],
    ], JSON_UNESCAPED_UNICODE),
]);
$questionInsert->execute([
    'id' => 5103,
    'type' => 'text',
    'text' => 'Matching',
    'position' => 3,
    'correct_text' => json_encode(['1' => 'a', '2' => 'b', '3' => 'c'], JSON_UNESCAPED_UNICODE),
    'interaction_type' => 'matching',
    'settings_json' => json_encode([
        'left' => ['1' => 'L1', '2' => 'L2', '3' => 'L3'],
        'right' => ['a' => 'R1', 'b' => 'R2', 'c' => 'R3'],
        'pairs' => ['1' => 'a', '2' => 'b', '3' => 'c'],
    ], JSON_UNESCAPED_UNICODE),
]);
$questionInsert->execute([
    'id' => 5104,
    'type' => 'text',
    'text' => 'Text',
    'position' => 4,
    'correct_text' => 'answer',
    'interaction_type' => 'text',
    'settings_json' => null,
]);

$optionInsert = $pdo->prepare(
    'INSERT INTO question_options (id, question_id, text, is_correct, position)
     VALUES (:id, 5101, :text, :is_correct, :position)'
);
foreach ([
    [5201, 'A', 1, 1],
    [5202, 'B', 0, 2],
    [5203, 'C', 0, 3],
] as $row) {
    $optionInsert->execute([
        'id' => $row[0],
        'text' => $row[1],
        'is_correct' => $row[2],
        'position' => $row[3],
    ]);
}

$variantA = build_attempt_variant($pdo, 5101, 5102);
$variantARepeat = build_attempt_variant($pdo, 5101, 5102);
$variantB = build_attempt_variant($pdo, 5101, 5103);

$qA = json_decode((string)$variantA['question_order_json'], true);
$qARepeat = json_decode((string)$variantARepeat['question_order_json'], true);
$qB = json_decode((string)$variantB['question_order_json'], true);
$oA = json_decode((string)$variantA['option_order_json'], true);
$sA = json_decode((string)$variantA['structured_order_json'], true);

variant_assert(is_array($qA) && count($qA) === 4, 'question order missing');
variant_assert($qA === $qARepeat, 'same student does not get stable order');
variant_assert($qA !== [5101,5102,5103,5104], 'question order stayed canonical');
variant_assert($qA !== $qB, 'two students received the same question order in fixture');

variant_assert(
    isset($oA['5101']) && $oA['5101'] !== [5201,5202,5203],
    'choice options were not shuffled'
);
variant_assert(
    isset($sA['5102']['items']) && $sA['5102']['items'] !== ['1','2','3'],
    'ordering question starts in the correct order and leaks the answer'
);
variant_assert(
    isset($sA['5103']['right']) && $sA['5103']['right'] !== ['a','b','c'],
    'matching right column was not shuffled'
);

variant_assert(
    in_array((string)$variantA['variant_label'], ['A','B','C','D'], true),
    'variant label is invalid'
);

echo "Student variant shuffle smoke OK\n";
