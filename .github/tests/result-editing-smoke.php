<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'save' => file_get_contents($root . '/api/results/save.php'),
    'publish' => file_get_contents($root . '/api/results/publish.php'),
    'review' => file_get_contents($root . '/api/results/review.php'),
    'update_answer' => file_get_contents($root . '/api/results/update-answer.php'),
    'student_results' => file_get_contents($root . '/api/student/results.php'),
    'app' => file_get_contents($root . '/app.js'),
    'security' => file_get_contents($root . '/attempt-security.js'),
    'close' => file_get_contents($root . '/api/attempts/close.php'),
    'index' => file_get_contents($root . '/index.html'),
];

foreach ($files as $name => $source) {
    if ($source === false) {
        fwrite(STDERR, "Result editing smoke failed: cannot read {$name}.\n");
        exit(1);
    }
}

$checks = [
    ['save', "gradeRaw", 'teacher-selected grade is accepted'],
    ['publish', "manual_grade", 'manual grade is published'],
    ['review', "'editor' => $editor", 'review exposes answer editor data'],
    ['update_answer', "attempt_answer_corrected", 'answer correction endpoint exists'],
    ['student_results', "published_grade", 'student sees published teacher grade'],
    ['app', "./api/results/update-answer.php", 'UI saves corrected answers'],
    ['app', "grade: String(document.getElementById('resultEditGrade').value", 'UI submits explicit grade'],
    ['security', "window.addEventListener('blur'", 'Android multi-window blur is monitored'],
    ['security', "FOCUS_LOSS_GRACE_MS", 'focus loss has a short grace period'],
    ['close', "'window_blur'", 'server accepts window blur finish reason'],
    ['index', '<select id="resultEditGrade"', 'grade control is editable'],
];

foreach ($checks as [$file, $needle, $label]) {
    if (!str_contains($files[$file], $needle)) {
        fwrite(STDERR, "Result editing smoke failed: {$label}.\n");
        exit(1);
    }
}

if (str_contains($files['index'], 'id="resultEditGrade" type="text"')) {
    fwrite(STDERR, "Result editing smoke failed: grade is still read-only.\n");
    exit(1);
}

echo "Result editing and Android focus smoke OK\n";
