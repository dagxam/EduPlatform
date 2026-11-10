<?php
declare(strict_types=1);

function must_contain(string $path, array $needles): void {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$path} is missing expected marker: {$needle}\n");
            exit(1);
        }
    }
}

must_contain('database/schema.sql', [
    'CREATE TABLE IF NOT EXISTS library_reactions',
    'idx_library_reactions_item',
]);

must_contain('database/schema.mysql.sql', [
    'CREATE TABLE IF NOT EXISTS library_reactions',
    'fk_library_reactions_item',
]);

must_contain('api/bootstrap.php', [
    'CREATE TABLE IF NOT EXISTS library_reactions',
    'fk_library_reactions_user',
]);

must_contain('api/library/list.php', [
    'likes_count',
    'rating_average',
    'user_liked',
    ':current_user_id',
]);

must_contain('api/library/react.php', [
    "require_user(['admin', 'teacher'])",
    'rating < 1 || $rating > 5',
]);

must_contain('api/library/author.php', [
    'materials_count',
    'usage_count',
    'likes_count',
]);

must_contain('api/classes/list.php', [
    'submitted_today',
    'average_grade',
    'subject_names',
]);

must_contain('api/student/results.php', [
    'subject_progress',
    'trend_30_days',
]);

must_contain('index.html', [
    'id="studentProgressGrid"',
    'id="studentProgressTrend"',
    'id="libraryAuthorModal"',
    'Сегодня в ваших классах',
    'Мои классы',
]);

must_contain('app.js', [
    'async function openLibraryAuthor',
    'async function updateLibraryReaction',
    'class-work-metrics',
    'studentProgressGrid',
]);

echo "Teacher/student UX stage 2 wiring OK\n";
