<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'questions' => file_get_contents($root . '/api/assignments/questions.php'),
    'update' => file_get_contents($root . '/api/assignments/update.php'),
    'grading' => file_get_contents($root . '/api/assignments/update-grading.php'),
    'builder' => file_get_contents($root . '/question-builder.js'),
    'app' => file_get_contents($root . '/app.js'),
    'helper' => file_get_contents($root . '/api/questions/_helpers.php'),
    'attempt_helper' => file_get_contents($root . '/api/attempts/_helpers.php'),
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
    ['builder', "dataset.builderBound", 'overview controls are bound only once'],
    ['builder', "showOverviewSection", 'overview buttons switch real editor tabs'],
    ['builder', "overviewActions.addEventListener('click'", 'overview tabs use delegated click handling'],
    ['builder', "aria-selected", 'overview tabs expose selected state'],
    ['app', "data-edit-assignment", 'existing assignments expose a real edit action'],
    ['app', "openAssignmentEditor", 'existing assignment edit action opens the editor'],
    ['helper', "'editable'] =", 'assignment helper exposes editable state'],
    ['helper', "Question revisions pin every already-started attempt", 'active attempts must not block revision-safe editing'],
    ['questions', "'stale_attempts_count' => \$staleAttemptsCount", 'overview exposes stale attempt count'],
    ['helper', "question_editor_fork_revision", 'question revisions preserve historical attempts'],
    ['attempt_helper', "attempt_question_ids", 'attempt grading is pinned to its question revision'],
    ['attempt_helper', "AND is_active = 1", 'new attempts use only current question revisions'],
    ['index', "Редактировать задание", 'overview shows assignment edit action'],
    ['index', "Редактировать оценивание", 'overview shows grading edit action'],
    ['index', "Редактировать вопросы", 'overview shows question edit action'],
    ['index', "assignment-overview-tab", 'overview controls use tab styling'],
];

foreach ($checks as [$file, $needle, $label]) {
    if (!str_contains($files[$file], $needle)) {
        fwrite(STDERR, "Assignment overview smoke failed: {$label}.\n");
        exit(1);
    }
}

if (str_contains($files['builder'], "function render() {\n    var list = document.getElementById('questionPreviewList');\n    document.getElementById('assignmentOverviewSettingsForm')?.addEventListener")) {
    fwrite(STDERR, "Assignment overview smoke failed: overview listeners are still rebound during render.\n");
    exit(1);
}

if (str_contains($files['app'], 'data-preview-questions')) {
    fwrite(STDERR, "Assignment overview smoke failed: legacy preview-only action still exists.\n");
    exit(1);
}

if (str_contains($files['helper'], '$activeAttemptsCount === 0')
    || str_contains($files['helper'], 'ASSIGNMENT_HAS_ACTIVE_ATTEMPT')) {
    fwrite(STDERR, "Assignment overview smoke failed: active attempts still block revision-safe editing.\n");
    exit(1);
}

echo "Assignment overview editing smoke OK\n";
