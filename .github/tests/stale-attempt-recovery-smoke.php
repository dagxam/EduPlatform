<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

function stale_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "Stale attempt recovery smoke failed: {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
stale_assert($schema !== false, 'schema.sql missing');
$pdo->exec($schema);
apply_schema_migrations($pdo);

$pdo->exec(
    "INSERT INTO users (id, first_name, last_name, email, password_hash, role)
     VALUES
     (9201, 'Teacher', 'Stale', 'stale-teacher@example.test', 'x', 'teacher'),
     (9202, 'Student', 'Old', 'stale-old@example.test', 'x', 'student'),
     (9203, 'Student', 'Fresh', 'stale-fresh@example.test', 'x', 'student')"
);
$pdo->exec("INSERT INTO subjects (id, name) VALUES (9201, 'Stale Test Subject')");
$pdo->exec(
    "INSERT INTO assignments
     (id, teacher_id, subject_id, title, status, workflow_status, max_attempts)
     VALUES (9201, 9201, 9201, 'Draft restored assignment', 'draft', 'draft', 3)"
);
$pdo->exec(
    "INSERT INTO questions
     (id, assignment_id, type, text, points, position, interaction_type, is_active)
     VALUES (9201, 9201, 'single', 'Question', 1, 1, 'single', 1)"
);
$pdo->exec(
    "INSERT INTO question_options (id, question_id, text, is_correct, position)
     VALUES (9201, 9201, 'Correct', 1, 1), (9202, 9201, 'Wrong', 0, 2)"
);

$pdo->exec(
    "INSERT INTO attempts
     (id, assignment_id, student_id, status, started_at, last_seen_at, question_order_json)
     VALUES
     (9201, 9201, 9202, 'in_progress', '2000-01-01 00:00:00', '2000-01-01 00:00:00', '[9201]'),
     (9202, 9201, 9203, 'in_progress', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '[9201]')"
);

stale_assert(attempt_is_stale([
    'status' => 'in_progress',
    'started_at' => '2000-01-01 00:00:00',
    'last_seen_at' => '2000-01-01 00:00:00',
], 120), 'old heartbeat is not considered stale');

stale_assert(!attempt_is_stale([
    'status' => 'in_progress',
    'started_at' => gmdate('Y-m-d H:i:s'),
    'last_seen_at' => gmdate('Y-m-d H:i:s'),
], 120), 'fresh heartbeat is incorrectly considered stale');

$finalized = finalize_stale_attempts($pdo, 9201, null, null, 120);
stale_assert($finalized === 1, 'expected exactly one stale attempt to be finalized');

$old = $pdo->query('SELECT status, termination_reason FROM attempts WHERE id = 9201')->fetch();
$fresh = $pdo->query('SELECT status, termination_reason FROM attempts WHERE id = 9202')->fetch();

stale_assert(($old['status'] ?? '') !== 'in_progress', 'stale attempt stayed in_progress');
stale_assert(($old['termination_reason'] ?? '') === 'connection_lost', 'stale attempt reason was not connection_lost');
stale_assert(($fresh['status'] ?? '') === 'in_progress', 'fresh attempt was finalized by mistake');

$startSource = file_get_contents(dirname(__DIR__, 2) . '/api/attempts/start.php');
$helperSource = file_get_contents(dirname(__DIR__, 2) . '/api/questions/_helpers.php');
stale_assert(is_string($startSource) && str_contains($startSource, 'finalize_stale_attempts'), 'attempt start does not clean stale sessions');
stale_assert(is_string($helperSource) && !str_contains($helperSource, '$activeAttemptsCount === 0'), 'question editor is still blocked by active attempts');
stale_assert(!str_contains($helperSource, 'ASSIGNMENT_HAS_ACTIVE_ATTEMPT'), 'obsolete active-attempt edit lock still exists');

echo "Stale attempt recovery smoke OK\n";
