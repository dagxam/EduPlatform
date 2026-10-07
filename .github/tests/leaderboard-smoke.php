<?php
declare(strict_types=1);

$analytics = file_get_contents(__DIR__ . '/../../api/results/analytics.php');
$app = file_get_contents(__DIR__ . '/../../app.js');
$index = file_get_contents(__DIR__ . '/../../index.html');

if ($analytics === false || $app === false || $index === false) {
    fwrite(STDERR, "Leaderboard smoke failed: cannot read source files.\n");
    exit(1);
}

$checks = [
    'students are eligible after one completed work' => "HAVING COUNT(*) >= 1",
    'student leaderboard returns five places' => "LIMIT 5",
    'class leaderboard returns five places' => "array_slice(\$classLeaders, 0, 5)",
    'school leaderboard returns five places' => "array_slice(\$schoolLeaders, 0, 5)",
    'student ranking uses cumulative final percent' => "ORDER BY final_percent DESC",
    'student leaderboard sums earned points' => 'SUM({$effectiveScoreSql}) AS total_score',
    'student leaderboard sums maximum points' => "SUM(COALESCE(at.max_score, 0)) AS total_max_score",
    'student leaderboard exposes final percent' => "'final_percent' => \$finalPercent",
    'student leaderboard exposes final grade' => "'final_grade' => \$finalGrade",
    'API exposes five-leader rule' => "'leaders_limit' => 5",
    'API exposes one-work student rule' => "'student_min_works' => 1",
    'API exposes one-work class rule' => "'class_min_completed' => 1",
    'API exposes one-work school rule' => "'school_min_completed' => 1",
];

foreach ($checks as $label => $needle) {
    if (!str_contains($analytics, $needle)) {
        fwrite(STDERR, "Leaderboard smoke failed: {$label}.\n");
        exit(1);
    }
}

if (!str_contains($app, 'items.slice(0,5)')) {
    fwrite(STDERR, "Leaderboard smoke failed: UI does not render five leaders.\n");
    exit(1);
}

if (!str_contains($app, 'item.final_percent')
    || !str_contains($app, 'item.final_grade')
    || !str_contains($app, 'item.total_score')
    || !str_contains($app, 'item.total_max_score')
    || !str_contains($app, 'Итого:')) {
    fwrite(STDERR, "Leaderboard smoke failed: cumulative student result is not rendered.\n");
    exit(1);
}

if (!str_contains($index, 'суммарные баллы переводятся в общий процент и итоговую оценку')) {
    fwrite(STDERR, "Leaderboard smoke failed: cumulative student leaderboard explanation is missing.\n");
    exit(1);
}

if (str_contains($index, 'минимум по 3 выполненным работам')
    || str_contains($index, '80% среднего результата + 20%')) {
    fwrite(STDERR, "Leaderboard smoke failed: old leaderboard rules are still shown in UI.\n");
    exit(1);
}

echo "Leaderboard smoke OK\n";
