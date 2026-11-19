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
    "'can_change_author' => is_platform_admin(\$user)",
    'author_middle_name',
]);

must_contain('api/library/import.php', [
    "require_user(['admin', 'teacher'])",
    'can_teach_school($user, $targetSchoolId)',
    ': (int)$user[\'id\']',
]);

must_contain('index.html', [
    'id="libraryClassFilter"',
    'id="librarySort"',
    'id="libraryAuthorEditModal"',
    'id="libraryAuthorSelect"',
    'library-filter-panel-compact',
]);

must_contain('app.js', [
    'let libraryCanImport = false;',
    'data-library-open-copy',
    'data-library-assign-copy',
    'function libraryClassNames',
    'async function openLibraryCopiedAssignment',
    'let libraryCanChangeAuthor = false;',
    'async function openLibraryAuthorEditor',
    'data-library-change-author',
    './api/library/authors.php?item_id=',
    './api/library/set-author.php',
    'library-card-compact',
]);

must_contain('api/library/authors.php', [
    'is_platform_admin($user)',
    'su.can_teach = 1',
    'li.source_school_id',
]);

must_contain('api/library/set-author.php', [
    'is_platform_admin($user)',
    'UPDATE library_items',
    'SET submitted_by = :author_id',
    "audit_event('library_author_changed'",
    'su.can_teach = 1',
]);

echo "Library UX stage 1 wiring OK\n";

