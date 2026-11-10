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

must_contain('api/library/list.php', [
    "'can_import' =>",
    'usage_count',
    'author_materials_count',
    'time_limit_minutes',
    'class_names',
]);

must_contain('api/library/import.php', [
    "require_user(['admin', 'teacher'])",
    'can_teach_school($user, $targetSchoolId)',
    ': (int)$user[\'id\']',
]);

must_contain('index.html', [
    'id="libraryClassFilter"',
    'id="librarySort"',
]);

must_contain('app.js', [
    'let libraryCanImport = false;',
    'data-library-open-copy',
    'data-library-assign-copy',
    'function libraryClassNames',
    'async function openLibraryCopiedAssignment',
]);

echo "Library UX stage 1 wiring OK\n";

