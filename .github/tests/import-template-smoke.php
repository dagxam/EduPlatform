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

Справочник служебных полей UVORIA
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

echo "UVORIA mixed assignment parser OK\n";
