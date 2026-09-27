<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/assignments/_import_parser.php';

$sample = <<<'TXT'
ЗАДАНИЕ 1
Один правильный ответ
Тест: выбор одного варианта
В каком году началась Вторая мировая война?
A) 1937
B) 1938
C) 1939
D) 1941
TYPE: single
ANSWER: C
POINTS: 1

ЗАДАНИЕ 2
Несколько правильных ответов
Тест: можно выбрать более одного варианта
Какие государства входили в антигитлеровскую коалицию?
A) СССР
B) Великобритания
C) США
D) Германия
TYPE: multiple
ANSWER: A | B | C
POINTS: 3

ЗАДАНИЕ 3
Восстановить хронологию
Ученик ставит события в правильном порядке
Расположите события в хронологическом порядке.
• Сталинградская битва
• Окончание Второй мировой войны
• Начало Второй мировой войны
• Нападение Германии на СССР
TYPE: order
ORDER: Начало Второй мировой войны | Нападение Германии на СССР | Сталинградская битва | Окончание Второй мировой войны
POINTS: 4

ЗАДАНИЕ 4
Соответствие: два столбца
Событие ↔ дата
Сопоставьте событие и год.
TYPE: matching
PAIRS: Начало Второй мировой войны = 1939 | Нападение Германии на СССР = 1941 | Сталинградская битва = 1942 | Окончание Второй мировой войны = 1945
POINTS: 4

ЗАДАНИЕ 5
Короткий ответ: дата или слово
Ученик вводит ответ вручную
Как назывался план нападения Германии на СССР?
TYPE: text
ANSWER: Барбаросса
ALTERNATIVES: план Барбаросса | Барбаросса
POINTS: 1

ЗАДАНИЕ 6
Найти и исправить ошибку
Ученик переписывает неверную часть или всё предложение
Исправьте историческую ошибку:
«Вторая мировая война началась в 1941 году.»
TYPE: correction
ANSWER: Вторая мировая война началась в 1939 году.
POINTS: 2

ЗАДАНИЕ 7
Вопрос по изображению
Картинка + короткий ответ
Рассмотрите изображение. Какой тип исторического события на нём показан?
TYPE: text
ANSWER: политический митинг | публичное выступление | массовый митинг
POINTS: 1

ЗАДАНИЕ 8
Изображение + выбор ответа
Картинка может использоваться с любым типом ответа
Что лучше всего описывает изображённую сцену?
A) Спортивное соревнование
B) Массовое политическое выступление
C) Археологические раскопки
D) Военный парад
TYPE: single
ANSWER: B
POINTS: 1

Справочник служебных полей UROVIA
TXT;

$questions = import_parse_questions($sample);
$expected = ['single', 'multiple', 'order', 'matching', 'text', 'correction', 'text', 'single'];
$actual = array_map(static fn(array $q): string => (string)$q['interaction_type'], $questions);

if (count($questions) !== 8) {
    fwrite(STDERR, 'Expected 8 questions, got ' . count($questions) . PHP_EOL);
    exit(1);
}
if ($actual !== $expected) {
    fwrite(STDERR, 'Unexpected types: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
if (($questions[2]['text'] ?? '') !== 'Расположите события в хронологическом порядке.') {
    fwrite(STDERR, 'Order prompt parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (count((array)($questions[2]['settings']['correct_order'] ?? [])) !== 4) {
    fwrite(STDERR, 'ORDER field parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (count((array)($questions[3]['settings']['pairs'] ?? [])) !== 4) {
    fwrite(STDERR, 'PAIRS field parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (strpos((string)($questions[4]['correct_text'] ?? ''), 'план Барбаросса') === false) {
    fwrite(STDERR, 'ALTERNATIVES field parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (empty($questions[6]['needs_image']) || empty($questions[7]['needs_image'])) {
    fwrite(STDERR, 'Image questions were not detected.' . PHP_EOL);
    exit(1);
}

echo "UROVIA mixed assignment parser OK\n";


$teacherStyle = <<<'TXT'
"Что такое логика?"
A) наука о законах и формах познающего мышления
B) наука о числах
C) наука о природе
D) наука о языке
TYPE: single
ANSWER: A
POINTS: 1
Сколько главных законов мышления выделяют в традиционной логике?
A) 2
B) 5
C) 4
D) 3
TYPE: single
ANSWER: C
POINTS: 1
Что изображает картина Ю. Пименова «Спор»?
[[UVORIA_IMAGE:rId5]]
TYPE: text
ANSWER: доброжелательную дискуссию | совместный поиск истины
POINTS: 1
В чём суть приёма «обращение полемики противника против него самого»?
A) игнорировать доводы оппонента
B) использовать доводы оппонента для подтверждения своей позиции
C) менять тему спора
D) использовать доводы оппонента для опровержения утверждений
E) повторять аргументы оппонента
TYPE: multiple
ANSWER: B | D
POINTS: 2
TXT;

$teacherQuestions = import_parse_questions($teacherStyle);
if (count($teacherQuestions) !== 4) {
    fwrite(STDERR, 'Teacher-style unnumbered DOCX blocks parsed incorrectly: ' . count($teacherQuestions) . PHP_EOL);
    exit(1);
}
$teacherTypes = array_map(static fn(array $q): string => (string)$q['interaction_type'], $teacherQuestions);
if ($teacherTypes !== ['single', 'single', 'text', 'multiple']) {
    fwrite(STDERR, 'Teacher-style types parsed incorrectly: ' . json_encode($teacherTypes, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
if (($teacherQuestions[0]['options'][0]['is_correct'] ?? false) !== true) {
    fwrite(STDERR, 'Teacher-style single answer parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (($teacherQuestions[3]['options'][1]['is_correct'] ?? false) !== true
    || ($teacherQuestions[3]['options'][3]['is_correct'] ?? false) !== true) {
    fwrite(STDERR, 'Teacher-style multiple answer parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (($teacherQuestions[2]['media_relationship_ids'][0] ?? '') !== 'rId5') {
    fwrite(STDERR, 'DOCX image relationship marker parsed incorrectly.' . PHP_EOL);
    exit(1);
}
if (strpos((string)$teacherQuestions[2]['correct_text'], 'совместный поиск истины') === false) {
    fwrite(STDERR, 'Teacher-style text alternatives parsed incorrectly.' . PHP_EOL);
    exit(1);
}

echo "Teacher-style unnumbered assignment parser OK\n";


$mixedAlphabet = <<<'TXT'
Какой вариант правильный?
А) первый
Б) второй
В) третий
Г) четвёртый
TYPE: single
ANSWER: C
POINTS: 1

Выберите два правильных варианта.
A) первый
B) второй
C) третий
D) четвёртый
TYPE: multiple
ANSWER: Б | Г
POINTS: 2
TXT;

$mixedQuestions = import_parse_questions($mixedAlphabet);
if (count($mixedQuestions) !== 2) {
    fwrite(STDERR, 'Mixed alphabet parser expected 2 questions.' . PHP_EOL);
    exit(1);
}
if (($mixedQuestions[0]['options'][2]['is_correct'] ?? false) !== true) {
    fwrite(STDERR, 'Cyrillic options + Latin answer label were not normalized by position.' . PHP_EOL);
    exit(1);
}
if (($mixedQuestions[1]['options'][1]['is_correct'] ?? false) !== true
    || ($mixedQuestions[1]['options'][3]['is_correct'] ?? false) !== true) {
    fwrite(STDERR, 'Latin options + Cyrillic answer labels were not normalized by position.' . PHP_EOL);
    exit(1);
}

echo "Mixed Cyrillic/Latin answer labels OK\n";


/*
 * Regression: an older imported assignment may have lost/wrong correct flags,
 * and option rows may have been reordered later in the builder.
 * Repair must match correct answers by option text, not just row position.
 */
require_once dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

$repairDb = new PDO('sqlite::memory:');
$repairDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repairDb->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY, school_id INTEGER)');
$repairDb->exec('CREATE TABLE assignment_imports (
    assignment_id INTEGER PRIMARY KEY,
    extracted_text TEXT
)');
$repairDb->exec('CREATE TABLE questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    text TEXT NOT NULL,
    type TEXT NOT NULL,
    interaction_type TEXT,
    points REAL NOT NULL,
    correct_text TEXT,
    settings_json TEXT
)');
$repairDb->exec('CREATE TABLE question_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    is_correct INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL
)');
$repairDb->exec('CREATE TABLE attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT "submitted",
    termination_reason TEXT,
    score REAL,
    max_score REAL,
    percent REAL,
    grade TEXT,
    submitted_at TEXT,
    last_seen_at TEXT
)');
$repairDb->exec('CREATE TABLE answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    answer_text TEXT,
    score REAL,
    is_correct INTEGER,
    needs_review INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
)');

$repairDb->prepare('INSERT INTO assignments (id, school_id) VALUES (1, 1)')->execute();
$repairDb->prepare('INSERT INTO assignment_imports (assignment_id, extracted_text) VALUES (1, :text)')
    ->execute(['text' => $teacherStyle]);
$repairDb->prepare(
    'INSERT INTO questions (assignment_id, position, text, type, interaction_type, points)
     VALUES (1, 1, :text, "single", "single", 1)'
)->execute(['text' => $teacherQuestions[0]['text']]);
$repairQuestionId = (int)$repairDb->lastInsertId();

// Deliberately store options in a different order and with no correct flag.
$reordered = [
    $teacherQuestions[0]['options'][2],
    $teacherQuestions[0]['options'][0],
    $teacherQuestions[0]['options'][3],
    $teacherQuestions[0]['options'][1],
];
$insertRepairOption = $repairDb->prepare(
    'INSERT INTO question_options (question_id, text, is_correct, position)
     VALUES (:question_id, :text, 0, :position)'
);
foreach ($reordered as $i => $option) {
    $insertRepairOption->execute([
        'question_id' => $repairQuestionId,
        'text' => $option['text'],
        'position' => $i + 1,
    ]);
}

$correctIds = repair_missing_correct_options($repairDb, $repairQuestionId);
if (count($correctIds) !== 1) {
    fwrite(STDERR, 'Imported answer-key repair did not restore exactly one correct option.' . PHP_EOL);
    exit(1);
}
$repairStmt = $repairDb->prepare(
    'SELECT text FROM question_options WHERE id = :id AND is_correct = 1 LIMIT 1'
);
$repairStmt->execute(['id' => $correctIds[0]]);
if ((string)$repairStmt->fetchColumn() !== (string)$teacherQuestions[0]['options'][0]['text']) {
    fwrite(STDERR, 'Imported answer-key repair matched the wrong option after reorder.' . PHP_EOL);
    exit(1);
}

$gradedRepair = grade_question_answer($repairDb, $repairQuestionId, ['option_ids' => $correctIds]);
if ((int)$gradedRepair['is_correct'] !== 1 || (float)$gradedRepair['score'] !== 1.0) {
    fwrite(STDERR, 'Repaired imported answer was not graded correctly.' . PHP_EOL);
    exit(1);
}

echo "Imported answer-key repair and grading OK\n";

/*
 * Regression: if a teacher intentionally changes the correct answer in the
 * constructor after import, repair logic must preserve that valid current key.
 */
$repairDb->prepare(
    'INSERT INTO questions (assignment_id, position, text, type, interaction_type, points)
     VALUES (1, 1, :text, "single", "single", 1)'
)->execute(['text' => $teacherQuestions[0]['text']]);
$editedQuestionId = (int)$repairDb->lastInsertId();

$editedCorrectId = 0;
$insertEditedOption = $repairDb->prepare(
    'INSERT INTO question_options (question_id, text, is_correct, position)
     VALUES (:question_id, :text, :is_correct, :position)'
);
foreach ($teacherQuestions[0]['options'] as $i => $option) {
    $isCorrect = $i === 1 ? 1 : 0; // deliberately differs from imported source
    $insertEditedOption->execute([
        'question_id' => $editedQuestionId,
        'text' => $option['text'],
        'is_correct' => $isCorrect,
        'position' => $i + 1,
    ]);
    if ($isCorrect === 1) $editedCorrectId = (int)$repairDb->lastInsertId();
}

$preservedIds = repair_missing_correct_options($repairDb, $editedQuestionId);
if ($preservedIds !== [$editedCorrectId]) {
    fwrite(STDERR, 'Imported answer-key repair overwrote a valid editor answer key.' . PHP_EOL);
    exit(1);
}

$editedGraded = grade_question_answer($repairDb, $editedQuestionId, ['option_ids' => [$editedCorrectId]]);
if ((int)$editedGraded['is_correct'] !== 1 || (float)$editedGraded['score'] !== 1.0) {
    fwrite(STDERR, 'Edited imported answer key was not used for grading.' . PHP_EOL);
    exit(1);
}

echo "Edited imported answer key is preserved\\n";

