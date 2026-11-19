<?php
declare(strict_types=1);

$analytics = file_get_contents(__DIR__ . '/../../api/results/analytics.php');
$app = file_get_contents(__DIR__ . '/../../app.js');
$index = file_get_contents(__DIR__ . '/../../index.html');
$resultsList = file_get_contents(__DIR__ . '/../../api/results/list.php');

if ($analytics === false || $app === false || $index === false || $resultsList === false) {
    fwrite(STDERR, "Leaderboard smoke failed: cannot read source files.\n");
    exit(1);
}

$checks = [
    'students are eligible after one completed work' => "HAVING COUNT(*) >= 1",
    'student leaderboard returns five places' => "LIMIT 5",
    'class leaderboard returns five places' => "array_slice(\$classLeaders, 0, 5)",
    'school leaderboard returns five places' => "array_slice(\$schoolLeaders, 0, 5)",
    'student ranking uses overall final percent first' => "ORDER BY final_percent DESC, works_count DESC, correct_answers_count DESC, rating_points DESC",
    'student leaderboard sums per-work rating points' => 'SUM({$ratingWorkPointsSql}) AS rating_points',
    'student leaderboard counts correct answers' => 'AS correct_answers_count',
    'student leaderboard counts checked answers' => 'AS checked_answers_count',
    'student leaderboard sums earned points' => 'SUM({$effectiveScoreSql}) AS total_score',
    'student leaderboard sums maximum points' => "SUM(COALESCE(at.max_score, 0)) AS total_max_score",
    'student leaderboard exposes final percent' => "'final_percent' => \$finalPercent",
    'student leaderboard exposes final grade' => "'final_grade' => \$finalGrade",
    'student leaderboard exposes school name' => "'school_name' => \$schoolName",
    'student leaderboard exposes school id' => "'school_id' => \$schoolId",
    'API exposes five-leader rule' => "'leaders_limit' => 5",
    'API exposes one-work student rule' => "'student_min_works' => 1",
    'API exposes one-work class rule' => "'class_min_completed' => 1",
    'API exposes one-work school rule' => "'school_min_completed' => 1",
    'analytics accepts subject filter' => "\$subjectId = max(0, (int)(\$_GET['subject_id'] ?? 0))",
    'student ranking filters by subject' => "\$studentSql .= ' AND a.subject_id = ?'",
    'class ranking filters by subject' => "\$attemptSql .= ' AND a.subject_id = ?'",
    'analytics exposes subject options' => "'subjects' => \$subjectRows",
    'analytics exposes selected subject' => "'selected_subject' => \$selectedSubject",
    'manager subject list includes assignment fallback' => "FROM assignments subject_assignment",
    'manager subject fallback is scoped to school' => "subject_assignment.school_id = ?",
    'manager subject list includes school teacher-subject fallback' => "FROM teacher_subjects school_teacher_subject",
    'teacher subject fallback is scoped to teacher' => "subject_assignment.teacher_id = ?",
    'teacher analytics access matches subject grants' => "FROM teacher_subjects access_ts",
];

foreach ($checks as $label => $needle) {
    if (!str_contains($analytics, $needle)) {
        fwrite(STDERR, "Leaderboard smoke failed: {$label}.\n");
        exit(1);
    }
}

if (!str_contains($resultsList, "'subject_id' => \$row['subject_id'] !== null ? (int)\$row['subject_id'] : null")) {
    fwrite(STDERR, "Leaderboard smoke failed: results API does not expose subject id fallback.\n");
    exit(1);
}

if (!str_contains($index, 'app.js?v=') || !str_contains($index, 'styles.css?v=')) {
    fwrite(STDERR, "Leaderboard smoke failed: leaderboard assets are not cache-busted.\n");
    exit(1);
}

if (!str_contains($app, 'items.slice(0,5)')) {
    fwrite(STDERR, "Leaderboard smoke failed: UI does not render five leaders.\n");
    exit(1);
}

if (!str_contains($app, 'item.final_percent')
    || !str_contains($app, 'item.final_grade')
    || !str_contains($app, 'общий результат:')
    || !str_contains($app, 'item.correct_answers_count')
    || !str_contains($app, 'item.checked_answers_count')
    || !str_contains($app, 'item.total_score')
    || !str_contains($app, 'item.total_max_score')
    || !str_contains($app, 'Правильных ответов:')
    || !str_contains($app, 'Итого:')
    || !str_contains($app, 'leader-school-badge')
    || !str_contains($app, 'leader-class-badge')
    || !str_contains($app, 'item.school_name')) {
    fwrite(STDERR, "Leaderboard smoke failed: cumulative student result is not rendered.\n");
    exit(1);
}

if (!str_contains($index, 'Главный показатель — итоговый процент')
    || !str_contains($index, 'resultsLeaderboardSubjectFilter')
    || !str_contains($index, 'resultsLeaderboardSubjectCount')
    || !str_contains($index, 'resultsStudentLeaderScope')
    || !str_contains($index, 'resultsClassLeaderScope')
    || !str_contains($index, 'У каждого ученика указаны школа и класс')
    || !str_contains($index, 'У каждого класса указана школа')
    || !str_contains($index, 'Рейтинг по предмету')) {
    fwrite(STDERR, "Leaderboard smoke failed: subject-aware leaderboard explanation/filter is missing.\n");
    exit(1);
}

if (!str_contains($app, "analyticsUrl.searchParams.set('subject_id'")
    || !str_contains($app, "resultsLeaderboardSubjectFilter")
    || !str_contains($app, "data.filters?.subjects")
    || !str_contains($app, "resultSubjectMap")
    || !str_contains($app, "item.subject_id")
    || !str_contains($app, "data.selected_subject?.name")
    || !str_contains($app, "subjectSelect.disabled = subjects.length === 0")
    || !str_contains($app, "Доступно предметов:")
    || !str_contains($app, "resultsStudentLeaderScope")
    || !str_contains($app, "resultsClassLeaderScope")) {
    fwrite(STDERR, "Leaderboard smoke failed: subject filter is not wired in UI.\n");
    exit(1);
}

if (str_contains($index, 'минимум по 3 выполненным работам')
    || str_contains($index, '80% среднего результата + 20%')) {
    fwrite(STDERR, "Leaderboard smoke failed: old leaderboard rules are still shown in UI.\n");
    exit(1);
}

echo "Leaderboard smoke OK\n";
