<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../api/journal/list.php');
if ($source === false) {
    fwrite(STDERR, "Journal history smoke failed: cannot read endpoint.\n");
    exit(1);
}

$checks = [
    'historical attempts are part of journal assignment discovery'
        => 'FROM attempts history_at',
    'historical attempts are scoped to the selected class'
        => 'history_cs.class_id = ?',
    'cancelled assignments may remain visible through completed attempts'
        => 'history_at.status <> "in_progress"',
    'current personal assignments are included in the class journal'
        => 'FROM assignment_students current_ast',
];

foreach ($checks as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Journal history smoke failed: {$label}.\n");
        exit(1);
    }
}

if (str_contains($source, 'JOIN assignment_classes ac ON ac.assignment_id = a.id')) {
    fwrite(STDERR, "Journal history smoke failed: assignment discovery still requires a current class assignment.\n");
    exit(1);
}

if (preg_match('/ORDER BY COALESCE\(a\.due_at, a\.created_at\) DESC, a\.id DESC LIMIT\s+12/', $source)) {
    fwrite(STDERR, "Journal history smoke failed: journal still truncates history to 12 works.\n");
    exit(1);
}

echo "Journal history smoke OK\n";
