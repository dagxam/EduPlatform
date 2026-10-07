<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';
require dirname(__DIR__, 2) . '/api/questions/_helpers.php';

function revision_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "Active assignment revision smoke failed: {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
revision_assert($schema !== false, 'schema.sql missing');
$pdo->exec($schema);
apply_schema_migrations($pdo);

$pdo->exec(
    "INSERT INTO users (id, first_name, last_name, email, password_hash, role)
     VALUES
     (9001, 'Teacher', 'Revision', 'revision-teacher@example.test', 'x', 'teacher'),
     (9002, 'Student', 'Revision', 'revision-student@example.test', 'x', 'student')"
);
$pdo->exec("INSERT INTO subjects (id, name) VALUES (9001, 'Revision Test Subject')");
$pdo->exec(
    "INSERT INTO assignments
     (id, teacher_id, subject_id, title, status, workflow_status)
     VALUES (9001, 9001, 9001, 'Active assignment', 'published', 'assigned')"
);
$pdo->exec(
    "INSERT INTO questions
     (id, assignment_id, type, text, points, position, correct_text, interaction_type, is_active)
     VALUES (9001, 9001, 'single', 'Old question', 1, 1, NULL, 'single', 1)"
);
$pdo->exec(
    "INSERT INTO question_options (id, question_id, text, is_correct, position)
     VALUES (9001, 9001, 'Old correct', 1, 1), (9002, 9001, 'Old wrong', 0, 2)"
);
$pdo->exec(
    "INSERT INTO attempts
     (id, assignment_id, student_id, status, question_order_json, score, max_score, percent, grade)
     VALUES (9001, 9001, 9002, 'submitted', '[9001]', 1, 1, 100, '5')"
);
$pdo->exec(
    "INSERT INTO answers
     (attempt_id, question_id, answer_text, score, is_correct, needs_review)
     VALUES (9001, 9001, '[9001]', 1, 1, 0)"
);

$pdo->beginTransaction();
$newQuestionId = question_editor_fork_revision($pdo, 9001, [
    'text' => 'New question',
    'points' => 5,
]);
question_editor_replace_options($pdo, $newQuestionId, [
    ['text' => 'New correct', 'is_correct' => 1],
    ['text' => 'New wrong', 'is_correct' => 0],
]);
$pdo->commit();

revision_assert($newQuestionId !== 9001, 'revision did not create a new question id');
revision_assert(
    (int)$pdo->query('SELECT is_active FROM questions WHERE id = 9001')->fetchColumn() === 0,
    'old question was not archived'
);
revision_assert(
    (int)$pdo->query('SELECT is_active FROM questions WHERE id = ' . (int)$newQuestionId)->fetchColumn() === 1,
    'new question revision is not active'
);
revision_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM question_options WHERE question_id = 9001')->fetchColumn() === 2,
    'old answer options were changed or deleted'
);

$variant = build_attempt_variant($pdo, 9001, 9002);
$newOrder = json_decode((string)$variant['question_order_json'], true);
revision_assert(
    is_array($newOrder) && $newOrder === [$newQuestionId],
    'new attempts do not use the active question revision'
);

$result = finalize_attempt($pdo, 9001, 'student_submit');
revision_assert(abs((float)$result['max_score'] - 1.0) < 0.000001, 'old attempt max score changed after revision');
revision_assert(abs((float)$result['score'] - 1.0) < 0.000001, 'old attempt score changed after revision');
revision_assert((string)$result['grade'] === '5', 'old attempt grade changed after revision');

echo "Active assignment question revision smoke OK\n";
