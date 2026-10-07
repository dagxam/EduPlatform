<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/database/_migration.php';

function mysql_smoke_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "MySQL smoke failed: {$message}\n");
    exit(1);
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=urovia_ci;charset=utf8mb4',
    'root',
    'urovia_ci_root',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

mysql_apply_schema($pdo);

$required = [
    'users','schools','subjects','classes','assignments','assignment_students','questions',
    'question_options','attempts','answers','audit_log','auth_throttle',
    'password_reset_tokens','urovia_meta'
];
$tables = array_map(
    static fn(array $row): string => (string)$row[0],
    $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM)
);
foreach ($required as $table) {
    mysql_smoke_assert(in_array($table, $tables, true), "missing table {$table}");
}

$pdo->exec('DROP TABLE assignment_students');
mysql_apply_runtime_migrations($pdo);
$assignmentStudentsRestored = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'assignment_students'"
)->fetchColumn();
mysql_smoke_assert($assignmentStudentsRestored === 1, 'runtime migration did not restore assignment_students');

mysql_smoke_assert(
    mysql_column_exists($pdo, 'questions', 'is_active'),
    'questions.is_active migration missing'
);
mysql_smoke_assert(
    mysql_column_exists($pdo, 'questions', 'revision_of_id'),
    'questions.revision_of_id migration missing'
);
mysql_smoke_assert(
    mysql_index_exists($pdo, 'questions', 'idx_questions_assignment_active_position'),
    'question revision index missing'
);

$version = $pdo->query(
    "SELECT meta_value FROM urovia_meta WHERE meta_key = 'schema_version'"
)->fetchColumn();
mysql_smoke_assert((string)$version === mysql_schema_version(), 'schema version mismatch');

$attemptIndex = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = "attempts"
       AND index_name = "idx_attempts_assignment_student_status_id"'
);
$attemptIndex->execute();
mysql_smoke_assert((int)$attemptIndex->fetchColumn() > 0, 'attempt concurrency index missing');

$auditIndex = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = "audit_log"
       AND index_name = "idx_audit_log_entity_event"'
);
$auditIndex->execute();
mysql_smoke_assert((int)$auditIndex->fetchColumn() > 0, 'audit grading index missing');

$sqliteProbe = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sqliteProbe->exec(
    "CREATE TABLE schools (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        favicon_data TEXT,
        legacy_branding_note TEXT
    )"
);

$addedLegacyColumns = mysql_migration_ensure_target_columns($sqliteProbe, $pdo, 'schools');
$mysqlSchoolColumns = mysql_migration_target_columns($pdo, 'schools');
mysql_smoke_assert(
    in_array('favicon_data', $mysqlSchoolColumns, true),
    'favicon_data is missing from MySQL schools schema'
);
mysql_smoke_assert(
    in_array('legacy_branding_note', $mysqlSchoolColumns, true),
    'legacy SQLite column was not added to MySQL'
);
mysql_smoke_assert(
    in_array('legacy_branding_note', $addedLegacyColumns, true),
    'legacy column reconciliation was not reported'
);

$pdo->exec(
    "INSERT INTO users
     (id, first_name, last_name, email, login_name, password_hash, role, is_platform_admin, is_active)
     VALUES
     (1, 'Admin', 'UROVIA', 'admin@example.test', 'admin-login', 'x', 'admin', 1, 1),
     (2, 'Student', 'Test', 'student@example.test', NULL, 'x', 'student', 0, 1)"
);

$emailIdentityUser = find_user_by_identity($pdo, 'admin@example.test');
$loginIdentityUser = find_user_by_identity($pdo, 'admin-login');
mysql_smoke_assert((int)($emailIdentityUser['id'] ?? 0) === 1, 'staff identity lookup by email failed');
mysql_smoke_assert((int)($loginIdentityUser['id'] ?? 0) === 1, 'staff identity lookup by login failed');

$tokenHash = hash('sha256', 'mysql-smoke-reset-token');
$tokenStmt = $pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
     VALUES (:user_id, :token_hash, :expires_at, :created_at)'
);
$tokenStmt->execute([
    'user_id' => 1,
    'token_hash' => $tokenHash,
    'expires_at' => time() + 1800,
    'created_at' => time(),
]);
mysql_smoke_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = 1')->fetchColumn() === 1,
    'password reset token storage failed'
);
$pdo->exec(
    "INSERT INTO schools (id, name, slug, status, created_by)
     VALUES (1, 'Test School', 'test-school', 'active', 1)"
);
$pdo->exec(
    "INSERT INTO subjects (id, name) VALUES (1, 'Test Subject')"
);
$pdo->exec(
    "INSERT INTO classes (id, name, display_name, school_id)
     VALUES (1, 'class-1', '7A', 1)"
);
$pdo->exec(
    "INSERT INTO assignments
     (id, teacher_id, school_id, subject_id, title, status, workflow_status)
     VALUES (1, 1, 1, 1, 'MySQL Test', 'published', 'assigned')"
);
$pdo->exec(
    "INSERT INTO questions
     (id, assignment_id, type, interaction_type, text, points, position)
     VALUES (1, 1, 'single', 'single', '2 + 2?', 1, 1)"
);
$pdo->exec(
    "INSERT INTO question_options (id, question_id, text, is_correct, position)
     VALUES (1, 1, '4', 1, 1), (2, 1, '5', 0, 2)"
);
$pdo->exec(
    "INSERT INTO attempts
     (id, assignment_id, student_id, status, question_order_json)
     VALUES (1, 1, 2, 'in_progress', '[1]')"
);

$sql =
    'INSERT INTO answers
     (attempt_id, question_id, answer_text, score, is_correct, needs_review, updated_at)
     VALUES
     (:attempt_id, :question_id, :answer_text, :score, :is_correct, :needs_review, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['attempt_id', 'question_id'],
        ['answer_text', 'score', 'is_correct', 'needs_review', 'updated_at']
    );
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'attempt_id' => 1,
    'question_id' => 1,
    'answer_text' => '[1]',
    'score' => 1,
    'is_correct' => 1,
    'needs_review' => 0,
]);
$stmt->execute([
    'attempt_id' => 1,
    'question_id' => 1,
    'answer_text' => '[2]',
    'score' => 0,
    'is_correct' => 0,
    'needs_review' => 0,
]);

$count = (int)$pdo->query('SELECT COUNT(*) FROM answers WHERE attempt_id = 1')->fetchColumn();
$answer = (string)$pdo->query('SELECT answer_text FROM answers WHERE attempt_id = 1')->fetchColumn();
mysql_smoke_assert($count === 1, 'answer upsert created duplicate row');
mysql_smoke_assert($answer === '[2]', 'answer upsert did not update row');

$pdo->exec(
    "UPDATE attempts
     SET status = 'submitted',
         submitted_at = CURRENT_TIMESTAMP,
         percent = 100,
         grade = '5'
     WHERE id = 1"
);

$dashboardProbe = $pdo->query(
    "SELECT
       DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY) AS plus_week,
       DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY) AS minus_week,
       AVG(CASE
         WHEN COALESCE(published_grade, grade) IN ('2', '3', '4', '5')
         THEN CAST(COALESCE(published_grade, grade) AS DECIMAL(10,2))
         ELSE NULL
       END) AS avg_grade
     FROM attempts"
)->fetch();
mysql_smoke_assert(is_array($dashboardProbe), 'MySQL dashboard expressions failed');
mysql_smoke_assert((float)($dashboardProbe['avg_grade'] ?? 0) === 5.0, 'dashboard grade expression mismatch');

$backup = backup_create_archive($pdo, 'manual');
mysql_smoke_assert(!empty($backup['file']), 'MySQL backup file missing');
mysql_smoke_assert(($backup['database_driver'] ?? '') === 'mysql', 'backup driver is not mysql');
mysql_smoke_assert((int)($backup['size_bytes'] ?? 0) > 0, 'MySQL backup archive empty');

echo "MySQL schema, auth identity lookup, password reset storage, upsert, dashboard and backup OK\n";
