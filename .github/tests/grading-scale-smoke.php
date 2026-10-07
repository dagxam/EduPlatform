<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';

function grading_scale_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "Grading scale smoke failed: {$message}\n");
    exit(1);
}

$defaults = default_grade_scale();
grading_scale_assert($defaults === [
    'grade_5_min' => 85,
    'grade_4_min' => 71,
    'grade_3_min' => 50,
], 'default thresholds are not 85/71/50');

$cases = [
    [0, '2'],
    [49.99, '2'],
    [50, '3'],
    [70.99, '3'],
    [71, '4'],
    [84.99, '4'],
    [85, '5'],
    [100, '5'],
];
foreach ($cases as [$percent, $expected]) {
    grading_scale_assert(
        grade_from_percent((float)$percent) === $expected,
        "default grade mismatch at {$percent}%"
    );
}

$custom = ['grade_5_min' => 92, 'grade_4_min' => 78, 'grade_3_min' => 60];
grading_scale_assert(grade_from_percent(59.9, $custom) === '2', 'custom 2 boundary broken');
grading_scale_assert(grade_from_percent(60, $custom) === '3', 'custom 3 boundary broken');
grading_scale_assert(grade_from_percent(78, $custom) === '4', 'custom 4 boundary broken');
grading_scale_assert(grade_from_percent(92, $custom) === '5', 'custom 5 boundary broken');

$root = dirname(__DIR__, 2);
$settings = file_get_contents($root . '/api/school/settings.php');
$scaleApi = file_get_contents($root . '/api/school/grading-scale.php');
$app = file_get_contents($root . '/app.js');
$index = file_get_contents($root . '/index.html');
$analytics = file_get_contents($root . '/api/results/analytics.php');
$dashboard = file_get_contents($root . '/api/teacher/dashboard.php');

foreach ([
    'settings' => $settings,
    'scaleApi' => $scaleApi,
    'app' => $app,
    'index' => $index,
    'analytics' => $analytics,
    'dashboard' => $dashboard,
] as $name => $source) {
    grading_scale_assert(is_string($source), "cannot read {$name}");
}

grading_scale_assert(
    str_contains($settings, 'INVALID_GRADE_SCALE')
        && str_contains($settings, 'grade_5_min')
        && str_contains($settings, 'grade_4_min')
        && str_contains($settings, 'grade_3_min'),
    'school settings do not validate configurable grade thresholds'
);
grading_scale_assert(
    str_contains($scaleApi, "require_user(['admin', 'teacher', 'student'])")
        && str_contains($scaleApi, 'school_grade_scale'),
    'read-only school grade scale endpoint is missing'
);
grading_scale_assert(
    str_contains($index, 'id="gradeScaleSettingsForm"')
        && str_contains($index, 'id="gradeScale5Min"')
        && str_contains($index, 'id="gradeScale4Min"')
        && str_contains($index, 'id="gradeScale3Min"'),
    'grade scale admin controls are missing'
);
grading_scale_assert(
    str_contains($app, 'currentGradeScale.grade_5_min')
        && str_contains($app, 'currentGradeScale.grade_4_min')
        && str_contains($app, 'currentGradeScale.grade_3_min')
        && str_contains($app, './api/school/grading-scale.php'),
    'frontend does not use the current school grade scale'
);
grading_scale_assert(
    str_contains($analytics, 'grade_5_min')
        && str_contains($analytics, 'NULLIF(at.grade')
        && !str_contains($analytics, '>= 90 THEN 5')
        && !str_contains($analytics, '>= 75 THEN 4'),
    'leaderboard analytics still uses the old hard-coded scale'
);
grading_scale_assert(
    str_contains($dashboard, '$grade5Min')
        && str_contains($dashboard, '$grade4Min')
        && str_contains($dashboard, '$grade3Min')
        && !str_contains($dashboard, '>= 90 THEN 5')
        && !str_contains($dashboard, '>= 75 THEN 4'),
    'teacher dashboard still uses the old hard-coded scale'
);

echo "Configurable grading scale smoke OK\n";
