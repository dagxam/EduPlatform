<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/assignments/_import_parser.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

$gradeCases = [
    [0.0, '2'],
    [49.99, '2'],
    [50.0, '3'],
    [70.99, '3'],
    [71.0, '4'],
    [84.99, '4'],
    [85.0, '5'],
    [100.0, '5'],
];
foreach ($gradeCases as [$percent, $expectedGrade]) {
    if (grade_from_percent((float)$percent) !== $expectedGrade) {
        fwrite(STDERR, 'Grade scale mismatch at ' . $percent . '%: expected ' . $expectedGrade . PHP_EOL);
        exit(1);
    }
}
echo "Unified grade scale boundaries OK" . PHP_EOL;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT
)');
$pdo->exec('CREATE TABLE assignment_imports (
    assignment_id INTEGER PRIMARY KEY,
    extracted_text TEXT
)');
$pdo->exec('CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER
)');
$pdo->exec('CREATE TABLE questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    text TEXT NOT NULL,
    points REAL NOT NULL DEFAULT 1,
    position INTEGER NOT NULL DEFAULT 0,
    correct_text TEXT,
    interaction_type TEXT,
    settings_json TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    revision_of_id INTEGER
)');
$pdo->exec('CREATE TABLE question_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    is_correct INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TABLE question_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    stored_name TEXT NOT NULL,
    original_name TEXT,
    mime_type TEXT,
    position INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TABLE attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL DEFAULT 1,
    submitted_at TEXT,
    score REAL,
    max_score REAL,
    percent REAL,
    grade TEXT,
    status TEXT NOT NULL DEFAULT "in_progress",
    termination_reason TEXT,
    last_seen_at TEXT,
    question_order_json TEXT,
    option_order_json TEXT,
    structured_order_json TEXT
)');
$pdo->exec('CREATE TABLE answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    answer_text TEXT,
    score REAL,
    is_correct INTEGER,
    needs_review INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT,
    UNIQUE(attempt_id, question_id)
)');

$pdo->exec("INSERT INTO assignments (title) VALUES ('Grading smoke')");
$assignmentId = (int)$pdo->lastInsertId();

$source = <<<'TXT'
Что такое логика?
А) наука о законах и формах познающего мышления
Б) наука о числах
В) наука о природе
Г) наука о языке
TYPE: single
ANSWER: A
POINTS: 1

Какие два варианта правильные?
A) первый
B) второй
C) третий
D) четвёртый
TYPE: multiple
ANSWER: Б | Г
POINTS: 2
TXT;

$pdo->prepare('INSERT INTO assignment_imports (assignment_id, extracted_text) VALUES (:assignment_id, :text)')
    ->execute(['assignment_id' => $assignmentId, 'text' => $source]);

$parsed = import_parse_questions($source);
if (count($parsed) !== 2) {
    fwrite(STDERR, 'Grading smoke: parser did not return two questions.' . PHP_EOL);
    exit(1);
}

$stored = import_store_questions($pdo, $assignmentId, $parsed, []);
if ((int)($stored['count'] ?? 0) !== 2) {
    fwrite(STDERR, 'Grading smoke: questions were not stored.' . PHP_EOL);
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, points FROM questions WHERE assignment_id = :assignment_id ORDER BY position, id');
$stmt->execute(['assignment_id' => $assignmentId]);
$questions = $stmt->fetchAll();

$totalScore = 0.0;
$totalMax = 0.0;

foreach ($questions as $index => $question) {
    $optionStmt = $pdo->prepare(
        'SELECT id FROM question_options
         WHERE question_id = :question_id AND is_correct = 1
         ORDER BY id'
    );
    $optionStmt->execute(['question_id' => (int)$question['id']]);
    $correctIds = array_map('intval', array_column($optionStmt->fetchAll(), 'id'));

    $expectedCorrectCount = $index === 0 ? 1 : 2;
    if (count($correctIds) !== $expectedCorrectCount) {
        fwrite(STDERR, 'Grading smoke: incorrect answer-key count for question ' . ($index + 1) . PHP_EOL);
        exit(1);
    }

    $graded = grade_question_answer($pdo, (int)$question['id'], ['option_ids' => $correctIds]);
    if ((int)$graded['is_correct'] !== 1) {
        fwrite(STDERR, 'Grading smoke: correct selected options were graded as wrong for question ' . ($index + 1) . PHP_EOL);
        exit(1);
    }

    $totalScore += (float)$graded['score'];
    $totalMax += (float)$question['points'];
}

if (abs($totalScore - $totalMax) > 0.000001 || $totalMax <= 0) {
    fwrite(STDERR, 'Grading smoke: total score mismatch.' . PHP_EOL);
    exit(1);
}

echo "UROVIA import-to-grading pipeline OK: {$totalScore}/{$totalMax}" . PHP_EOL;

/*
 * Regression: legacy/editor data can keep type="text" while the actual
 * interaction is a choice question. Grading must follow interaction_type.
 */
$pdo->prepare(
    'INSERT INTO questions (assignment_id, type, text, points, position, interaction_type)
     VALUES (:assignment_id, "text", "Legacy choice type", 1, 99, "single")'
)->execute(['assignment_id' => $assignmentId]);
$legacyQuestionId = (int)$pdo->lastInsertId();

$legacyOption = $pdo->prepare(
    'INSERT INTO question_options (question_id, text, is_correct, position)
     VALUES (:question_id, :text, :is_correct, :position)'
);
$legacyCorrectId = 0;
foreach ([
    ['Неверно', 0],
    ['Верно', 1],
] as $index => $option) {
    $legacyOption->execute([
        'question_id' => $legacyQuestionId,
        'text' => $option[0],
        'is_correct' => $option[1],
        'position' => $index + 1,
    ]);
    if ($option[1] === 1) $legacyCorrectId = (int)$pdo->lastInsertId();
}

$legacyGraded = grade_question_answer($pdo, $legacyQuestionId, ['option_ids' => [$legacyCorrectId]]);
if ((int)$legacyGraded['is_correct'] !== 1 || (float)$legacyGraded['score'] !== 1.0) {
    fwrite(STDERR, 'Grading smoke: interaction_type was ignored for legacy choice question.' . PHP_EOL);
    exit(1);
}

/* Short text grading should tolerate harmless punctuation and ё/е spelling. */
$pdo->prepare(
    'INSERT INTO questions (assignment_id, type, text, points, position, correct_text, interaction_type)
     VALUES (:assignment_id, "text", "Text normalization", 1, 100, "Всё верно", "text")'
)->execute(['assignment_id' => $assignmentId]);
$textQuestionId = (int)$pdo->lastInsertId();
$textGraded = grade_question_answer($pdo, $textQuestionId, ['answer_text' => '  Все верно!  ']);
if ((int)$textGraded['is_correct'] !== 1 || (float)$textGraded['score'] !== 1.0) {
    fwrite(STDERR, 'Grading smoke: normalized short text answer was graded incorrectly.' . PHP_EOL);
    exit(1);
}

echo "Legacy interaction type and normalized text grading OK" . PHP_EOL;

/*
 * Full mixed-format template regression. One assignment may contain all
 * supported UROVIA question types and each must grade correctly.
 */
$pdo->exec("INSERT INTO assignments (title) VALUES ('Mixed template grading')");
$mixedAssignmentId = (int)$pdo->lastInsertId();

$mixedSource = <<<'TXT'
ЗАДАНИЕ 1
В каком году началась Вторая мировая война?
A) 1937
B) 1938
C) 1939
D) 1941
TYPE: single
ANSWER: C
POINTS: 1

ЗАДАНИЕ 2
Какие государства входили в антигитлеровскую коалицию?
A) СССР
B) Великобритания
C) США
D) Германия
TYPE: multiple
ANSWER: A | B | C
POINTS: 3

ЗАДАНИЕ 3
Расположите события в хронологическом порядке.
• Сталинградская битва
• Окончание Второй мировой войны
• Начало Второй мировой войны
• Нападение Германии на СССР
TYPE: order
ORDER: Начало Второй мировой войны | Нападение Германии на СССР | Сталинградская битва | Окончание Второй мировой войны
POINTS: 4

ЗАДАНИЕ 4
Сопоставьте событие и год.
TYPE: matching
PAIRS: Начало Второй мировой войны = 1939 | Нападение Германии на СССР = 1941 | Сталинградская битва = 1942 | Окончание Второй мировой войны = 1945
POINTS: 4

ЗАДАНИЕ 5
Как назывался план нападения Германии на СССР?
TYPE: text
ANSWER: Барбаросса
ALTERNATIVES: план Барбаросса | Барбаросса
POINTS: 1

ЗАДАНИЕ 6
Исправьте историческую ошибку:
«Вторая мировая война началась в 1941 году.»
TYPE: correction
ANSWER: Вторая мировая война началась в 1939 году.
POINTS: 2

ЗАДАНИЕ 7
2 + 2 = ?
TYPE: number
ANSWER: 4
POINTS: 1

ЗАДАНИЕ 8
Вторая мировая война началась в 1939 году.
TYPE: true_false
ANSWER: верно
POINTS: 1
TXT;

$pdo->prepare('INSERT INTO assignment_imports (assignment_id, extracted_text) VALUES (:assignment_id, :text)')
    ->execute(['assignment_id' => $mixedAssignmentId, 'text' => $mixedSource]);

$mixedParsed = import_parse_questions($mixedSource);
if (count($mixedParsed) !== 8) {
    fwrite(STDERR, 'Mixed template grading expected 8 questions, got ' . count($mixedParsed) . PHP_EOL);
    exit(1);
}
import_store_questions($pdo, $mixedAssignmentId, $mixedParsed, []);

$mixedStmt = $pdo->prepare(
    'SELECT id, interaction_type, correct_text, points
     FROM questions
     WHERE assignment_id = :assignment_id
     ORDER BY position, id'
);
$mixedStmt->execute(['assignment_id' => $mixedAssignmentId]);
$mixedRows = $mixedStmt->fetchAll();

$mixedScore = 0.0;
$mixedMax = 0.0;
foreach ($mixedRows as $row) {
    $questionId = (int)$row['id'];
    $interaction = (string)$row['interaction_type'];
    $payload = [];

    if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
        $ids = correct_option_ids($pdo, $questionId);
        $payload = ['option_ids' => $ids];
    } elseif ($interaction === 'order') {
        $payload = ['order' => json_decode((string)$row['correct_text'], true) ?: []];
    } elseif ($interaction === 'matching') {
        $payload = ['matches' => json_decode((string)$row['correct_text'], true) ?: []];
    } elseif ($interaction === 'number') {
        $payload = ['answer_text' => (string)$row['correct_text']];
    } else {
        $first = trim((string)(preg_split('/\s*\|\s*/u', (string)$row['correct_text'])[0] ?? ''));
        $payload = ['answer_text' => $first];
    }

    $graded = grade_question_answer($pdo, $questionId, $payload);
    if ((int)$graded['is_correct'] !== 1) {
        fwrite(STDERR, 'Mixed template grading failed for type ' . $interaction . PHP_EOL);
        exit(1);
    }
    $mixedScore += (float)$graded['score'];
    $mixedMax += (float)$row['points'];
}

if (abs($mixedScore - $mixedMax) > 0.000001 || abs($mixedMax - 17.0) > 0.000001) {
    fwrite(STDERR, 'Mixed template total score mismatch: ' . $mixedScore . '/' . $mixedMax . PHP_EOL);
    exit(1);
}

echo "Mixed UROVIA template grading OK: {$mixedScore}/{$mixedMax}" . PHP_EOL;

/*
 * Regression for the production PDO mode (FETCH_ASSOC): final aggregation
 * must read named columns, not numeric indexes. This was the cause of 0/N.
 */
$pdo->prepare(
    'INSERT INTO attempts (assignment_id, student_id, status)
     VALUES (:assignment_id, 1, "in_progress")'
)->execute(['assignment_id' => $mixedAssignmentId]);
$mixedAttemptId = (int)$pdo->lastInsertId();

foreach ($mixedRows as $row) {
    $questionId = (int)$row['id'];
    $interaction = (string)$row['interaction_type'];
    if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
        $payload = ['option_ids' => correct_option_ids($pdo, $questionId)];
    } elseif ($interaction === 'order') {
        $payload = ['order' => json_decode((string)$row['correct_text'], true) ?: []];
    } elseif ($interaction === 'matching') {
        $payload = ['matches' => json_decode((string)$row['correct_text'], true) ?: []];
    } elseif ($interaction === 'number') {
        $payload = ['answer_text' => (string)$row['correct_text']];
    } else {
        $first = trim((string)(preg_split('/\s*\|\s*/u', (string)$row['correct_text'])[0] ?? ''));
        $payload = ['answer_text' => $first];
    }
    save_attempt_answer($pdo, $mixedAttemptId, $questionId, $payload);
}

$final = finalize_attempt($pdo, $mixedAttemptId, 'student_submit');
if (
    abs((float)$final['score'] - 17.0) > 0.000001
    || abs((float)$final['max_score'] - 17.0) > 0.000001
    || abs((float)$final['percent'] - 100.0) > 0.000001
    || (string)$final['grade'] !== '5'
) {
    fwrite(
        STDERR,
        'FETCH_ASSOC final aggregation failed: '
        . json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . PHP_EOL
    );
    exit(1);
}

echo "FETCH_ASSOC final aggregation OK: 17/17, 100%, grade 5" . PHP_EOL;



