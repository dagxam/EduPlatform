<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'questions' => file_get_contents($root . '/api/assignments/questions.php'),
    'update' => file_get_contents($root . '/api/assignments/update.php'),
    'grading' => file_get_contents($root . '/api/assignments/update-grading.php'),
    'builder' => file_get_contents($root . '/question-builder.js'),
    'index' => file_get_contents($root . '/index.html'),
];

foreach ($files as $name => $source) {
    if ($source === false) {
        fwrite(STDERR, "Assignment overview smoke failed: cannot read {$name}.\n");
        exit(1);
    }
}

$checks = [
    ['questions', "'max_attempts'", 'overview API exposes assignment settings'],
    ['questions', "'focus_policy'", 'overview API exposes focus policy'],
    ['update', "assignment_settings_updated", 'assignment settings update endpoint exists'],
    ['grading', "assignment_grading_updated", 'assignment grading update endpoint exists'],
    ['grading', "question_editor_assignment", 'grading respects assignment editability'],
    ['builder', "./api/assignments/update.php", 'builder saves assignment settings'],
    ['builder', "./api/assignments/update-grading.php", 'builder saves grading'],
    ['builder', "assignmentOverviewQuestions", 'builder navigates to question editing'],
    ['index', "Редактировать задание", 'overview shows assignment edit action'],
    ['index', "Редактировать оценивание", 'overview shows grading edit action'],
    ['index', "Редактировать вопросы", 'overview shows question edit action'],
];

foreach ($checks as [$file, $needle, $label]) {
    if (!str_contains($files[$file], $needle)) {
        fwrite(STDERR, "Assignment overview smoke failed: {$label}.\n");
        exit(1);
    }
}

echo "Assignment overview editing smoke OK\n";
