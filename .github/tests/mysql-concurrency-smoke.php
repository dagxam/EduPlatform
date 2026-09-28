<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/attempts/_helpers.php';

const LOAD_SCHOOL_ID = 99001;
const LOAD_SUBJECT_ID = 99001;
const LOAD_CLASS_ID = 99001;
const LOAD_ASSIGNMENT_ID = 99001;
const LOAD_TEACHER_ID = 99000;
const LOAD_STUDENT_FIRST_ID = 99100;
const LOAD_STUDENTS = 300;
const LOAD_QUESTIONS = 8;
const LOAD_WORKERS = 12;

function load_db(): PDO
{
    return new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=urovia_ci;charset=utf8mb4',
        'root',
        'urovia_ci_root',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 15,
        ]
    );
}

function load_fail(string $message): never
{
    fwrite(STDERR, "MySQL concurrency smoke failed: {$message}\n");
    exit(1);
}

function load_worker(int $offset, int $count): void
{
    $pdo = load_db();
    $questionStmt = $pdo->prepare(
        'SELECT q.id, MIN(CASE WHEN qo.is_correct = 1 THEN qo.id END) AS correct_option_id
         FROM questions q
         JOIN question_options qo ON qo.question_id = q.id
         WHERE q.assignment_id = ?
         GROUP BY q.id, q.position
         ORDER BY q.position, q.id'
    );
    $questionStmt->execute([LOAD_ASSIGNMENT_ID]);
    $questions = $questionStmt->fetchAll();
    if (count($questions) !== LOAD_QUESTIONS) {
        load_fail('worker did not see all questions');
    }

    $insertAttempt = $pdo->prepare(
        'INSERT INTO attempts
         (assignment_id, student_id, status, last_seen_at, attempt_session_hash, time_limit_snapshot)
         VALUES (?, ?, "in_progress", CURRENT_TIMESTAMP, ?, 45)'
    );

    for ($i = 0; $i < $count; $i++) {
        $studentId = LOAD_STUDENT_FIRST_ID + $offset + $i;
        $insertAttempt->execute([
            LOAD_ASSIGNMENT_ID,
            $studentId,
            hash('sha256', 'load-session-' . $studentId),
        ]);
        $attemptId = (int)$pdo->lastInsertId();

        foreach ($questions as $questionIndex => $question) {
            $questionId = (int)$question['id'];
            $correctOptionId = (int)$question['correct_option_id'];

            // First question is deliberately overwritten once. This checks that
            // concurrent autosaves remain idempotent through the unique answer key.
            if ($questionIndex === 0) {
                save_attempt_answer($pdo, $attemptId, $questionId, ['option_ids' => []]);
            }
            save_attempt_answer($pdo, $attemptId, $questionId, ['option_ids' => [$correctOptionId]]);
        }

        $result = finalize_attempt($pdo, $attemptId, 'student_submit');
        if ((float)$result['percent'] !== 100.0 || (string)$result['grade'] !== '5') {
            load_fail('incorrect final result for student ' . $studentId);
        }
    }
}

if (($argv[1] ?? '') === '--worker') {
    load_worker((int)($argv[2] ?? 0), (int)($argv[3] ?? 0));
    exit(0);
}

$pdo = load_db();
mysql_apply_schema($pdo);

try {
    $pdo->exec('DELETE FROM schools WHERE id = ' . LOAD_SCHOOL_ID);
    $pdo->exec('DELETE FROM subjects WHERE id = ' . LOAD_SUBJECT_ID);
    $pdo->exec(
        'DELETE FROM users
         WHERE id = ' . LOAD_TEACHER_ID . '
            OR id BETWEEN ' . LOAD_STUDENT_FIRST_ID . ' AND ' . (LOAD_STUDENT_FIRST_ID + LOAD_STUDENTS - 1)
    );

    $teacherStmt = $pdo->prepare(
        'INSERT INTO users
         (id, first_name, last_name, email, password_hash, role, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $teacherStmt->execute([
        LOAD_TEACHER_ID,
        'Load',
        'Teacher',
        'load-teacher@example.test',
        'x',
        'teacher',
    ]);

    $schoolStmt = $pdo->prepare(
        'INSERT INTO schools (id, name, slug, status, created_by)
         VALUES (?, ?, ?, "active", ?)'
    );
    $schoolStmt->execute([
        LOAD_SCHOOL_ID,
        'Concurrency School',
        'concurrency-school',
        LOAD_TEACHER_ID,
    ]);

    $pdo->prepare('INSERT INTO subjects (id, name) VALUES (?, ?)')
        ->execute([LOAD_SUBJECT_ID, 'Concurrency Subject']);

    $pdo->prepare(
        'INSERT INTO classes (id, name, display_name, school_id, teacher_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        LOAD_CLASS_ID,
        'concurrency-class',
        'LOAD',
        LOAD_SCHOOL_ID,
        LOAD_TEACHER_ID,
    ]);

    $pdo->prepare(
        'INSERT INTO assignments
         (id, teacher_id, school_id, subject_id, title, status, workflow_status, max_attempts, time_limit_minutes)
         VALUES (?, ?, ?, ?, ?, "published", "assigned", 1, 45)'
    )->execute([
        LOAD_ASSIGNMENT_ID,
        LOAD_TEACHER_ID,
        LOAD_SCHOOL_ID,
        LOAD_SUBJECT_ID,
        '300 student concurrency test',
    ]);

    $pdo->prepare(
        'INSERT INTO assignment_classes (assignment_id, class_id, time_limit_minutes)
         VALUES (?, ?, 45)'
    )->execute([LOAD_ASSIGNMENT_ID, LOAD_CLASS_ID]);

    $studentStmt = $pdo->prepare(
        'INSERT INTO users
         (id, first_name, last_name, email, password_hash, role, is_active)
         VALUES (?, ?, ?, ?, ?, "student", 1)'
    );
    $classStudentStmt = $pdo->prepare(
        'INSERT INTO class_students (student_id, class_id, activated_at)
         VALUES (?, ?, CURRENT_TIMESTAMP)'
    );
    for ($i = 0; $i < LOAD_STUDENTS; $i++) {
        $studentId = LOAD_STUDENT_FIRST_ID + $i;
        $studentStmt->execute([
            $studentId,
            'Student',
            (string)($i + 1),
            'load-student-' . $studentId . '@example.test',
            'x',
        ]);
        $classStudentStmt->execute([$studentId, LOAD_CLASS_ID]);
    }

    $questionStmt = $pdo->prepare(
        'INSERT INTO questions
         (id, assignment_id, type, interaction_type, text, points, position)
         VALUES (?, ?, "single", "single", ?, 1, ?)'
    );
    $optionStmt = $pdo->prepare(
        'INSERT INTO question_options
         (id, question_id, text, is_correct, position)
         VALUES (?, ?, ?, ?, ?)'
    );

    for ($q = 1; $q <= LOAD_QUESTIONS; $q++) {
        $questionId = 99000 + $q;
        $questionStmt->execute([
            $questionId,
            LOAD_ASSIGNMENT_ID,
            'Load question ' . $q,
            $q,
        ]);
        for ($o = 1; $o <= 4; $o++) {
            $optionId = 990000 + ($q * 10) + $o;
            $optionStmt->execute([
                $optionId,
                $questionId,
                'Option ' . $o,
                $o === 1 ? 1 : 0,
                $o,
            ]);
        }
    }

    $startedAt = microtime(true);
    $workers = [];
    $baseCount = intdiv(LOAD_STUDENTS, LOAD_WORKERS);
    $remainder = LOAD_STUDENTS % LOAD_WORKERS;
    $offset = 0;

    for ($worker = 0; $worker < LOAD_WORKERS; $worker++) {
        $count = $baseCount + ($worker < $remainder ? 1 : 0);
        $cmd = [
            PHP_BINARY,
            __FILE__,
            '--worker',
            (string)$offset,
            (string)$count,
        ];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($process)) {
            load_fail('could not start worker ' . $worker);
        }
        fclose($pipes[0]);
        $workers[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'worker' => $worker,
        ];
        $offset += $count;
    }

    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exitCode = proc_close($worker['process']);
        if ($exitCode !== 0) {
            load_fail(
                'worker ' . $worker['worker'] . ' exited with ' . $exitCode .
                '; stdout=' . trim((string)$stdout) .
                '; stderr=' . trim((string)$stderr)
            );
        }
    }

    $elapsed = microtime(true) - $startedAt;

    $attemptStats = $pdo->query(
        'SELECT COUNT(*) AS attempts,
                SUM(status = "submitted") AS submitted,
                SUM(percent = 100) AS perfect
         FROM attempts
         WHERE assignment_id = ' . LOAD_ASSIGNMENT_ID
    )->fetch();
    $answerCount = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM answers a
         JOIN attempts at ON at.id = a.attempt_id
         WHERE at.assignment_id = ' . LOAD_ASSIGNMENT_ID
    )->fetchColumn();
    $duplicateAnswers = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM (
           SELECT attempt_id, question_id, COUNT(*) AS c
           FROM answers
           WHERE attempt_id IN (
             SELECT id FROM attempts WHERE assignment_id = ' . LOAD_ASSIGNMENT_ID . '
           )
           GROUP BY attempt_id, question_id
           HAVING COUNT(*) > 1
         ) duplicates'
    )->fetchColumn();

    if ((int)($attemptStats['attempts'] ?? 0) !== LOAD_STUDENTS) {
        load_fail('attempt count mismatch');
    }
    if ((int)($attemptStats['submitted'] ?? 0) !== LOAD_STUDENTS) {
        load_fail('not every attempt was submitted');
    }
    if ((int)($attemptStats['perfect'] ?? 0) !== LOAD_STUDENTS) {
        load_fail('not every attempt kept the expected score');
    }
    if ($answerCount !== LOAD_STUDENTS * LOAD_QUESTIONS) {
        load_fail('answer row count mismatch: ' . $answerCount);
    }
    if ($duplicateAnswers !== 0) {
        load_fail('duplicate answer rows detected');
    }

    printf(
        "MySQL concurrency OK: %d students, %d questions, %d workers, %.2fs\n",
        LOAD_STUDENTS,
        LOAD_QUESTIONS,
        LOAD_WORKERS,
        $elapsed
    );
} finally {
    // Keep CI MySQL small and make the test repeatable.
    $pdo->exec('DELETE FROM schools WHERE id = ' . LOAD_SCHOOL_ID);
    $pdo->exec('DELETE FROM subjects WHERE id = ' . LOAD_SUBJECT_ID);
    $pdo->exec(
        'DELETE FROM users
         WHERE id = ' . LOAD_TEACHER_ID . '
            OR id BETWEEN ' . LOAD_STUDENT_FIRST_ID . ' AND ' . (LOAD_STUDENT_FIRST_ID + LOAD_STUDENTS - 1)
    );
}
