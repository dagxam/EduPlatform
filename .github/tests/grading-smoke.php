<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/assignments/_import_parser.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT
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
    settings_json TEXT
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
