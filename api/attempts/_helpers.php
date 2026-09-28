<?php
declare(strict_types=1);

function attempt_for_student(PDO $pdo, int $attemptId, int $studentId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*,
                ass.focus_policy,
                COALESCE(a.time_limit_snapshot, ac.time_limit_minutes, ass.time_limit_minutes) AS time_limit_minutes,
                ass.max_attempts,
                ass.status AS assignment_status
         FROM attempts a
         JOIN assignments ass ON ass.id = a.assignment_id
         LEFT JOIN class_students cs ON cs.student_id = a.student_id
         LEFT JOIN assignment_classes ac
           ON ac.assignment_id = a.assignment_id
          AND ac.class_id = cs.class_id
         WHERE a.id = :attempt_id AND a.student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute(['attempt_id' => $attemptId, 'student_id' => $studentId]);
    $attempt = $stmt->fetch();
    if (!$attempt) {
        json_response(['ok' => false, 'error' => 'Попытка не найдена.'], 404);
    }

    $sessionHash = hash('sha256', session_id());
    if (!empty($attempt['attempt_session_hash']) && !hash_equals((string)$attempt['attempt_session_hash'], $sessionHash)) {
        json_response([
            'ok' => false,
            'error' => 'Эта попытка открыта в другой сессии или на другом устройстве.',
            'code' => 'ATTEMPT_SESSION_MISMATCH',
        ], 409);
    }

    return $attempt;
}

function attempt_time_expired(array $attempt): bool
{
    if ((string)($attempt['status'] ?? '') !== 'in_progress') return false;

    $limit = (int)($attempt['time_limit_minutes'] ?? 0);
    if ($limit <= 0) return false;

    $startedRaw = trim((string)($attempt['started_at'] ?? ''));
    if ($startedRaw === '') return false;
    $startedAt = strtotime($startedRaw . ' UTC');
    if ($startedAt === false) return false;

    return time() >= ($startedAt + ($limit * 60));
}

function finalize_expired_attempt(PDO $pdo, array $attempt): ?array
{
    if (!attempt_time_expired($attempt)) return null;
    return finalize_attempt($pdo, (int)$attempt['id'], 'time_limit');
}

function normalize_answer_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = str_replace(['«', '»', '“', '”', '„', '’'], ['"', '"', '"', '"', '"', "'"], $value);
    $value = preg_replace('/[.!?;,]+$/u', '', $value) ?? $value;
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    return str_replace('ё', 'е', $value);
}

function correct_option_ids(PDO $pdo, int $questionId): array
{
    $stmt = $pdo->prepare(
        'SELECT id FROM question_options
         WHERE question_id = :question_id AND is_correct = 1
         ORDER BY id'
    );
    $stmt->execute(['question_id' => $questionId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function valid_choice_answer_key(string $interaction, array $correctIds): bool
{
    $count = count($correctIds);
    if (in_array($interaction, ['single', 'true_false'], true)) {
        return $count === 1;
    }
    if ($interaction === 'multiple') {
        return $count >= 1;
    }
    return false;
}

function expected_choice_option_ids(PDO $pdo, int $questionId, string $interaction): array
{
    $current = correct_option_ids($pdo, $questionId);
    sort($current, SORT_NUMERIC);

    // Manual constructor edits are authoritative.
    if (question_answer_key_edited($pdo, $questionId)) {
        return $current;
    }

    // Imported questions must be checked against the original ANSWER field,
    // even when the database already contains a valid-looking correct flag.
    // This avoids stale/wrong is_correct flags producing 0 points.
    try {
        $source = repair_missing_correct_options($pdo, $questionId);
    } catch (Throwable $e) {
        $source = [];
    }
    if (valid_choice_answer_key($interaction, $source)) {
        sort($source, SORT_NUMERIC);
        return $source;
    }

    return $current;
}

function question_answer_key_edited(PDO $pdo, int $questionId): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM audit_log
             WHERE entity_type = "question"
               AND entity_id = :question_id
               AND event_type = "question_updated"
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['question_id' => $questionId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Tiny in-memory smoke databases may not include audit_log.
        return false;
    }
}

function repair_missing_correct_options(PDO $pdo, int $questionId): array
{
    $stmt = $pdo->prepare(
        'SELECT q.id, q.assignment_id, q.position, q.text, q.type, q.interaction_type,
                ai.extracted_text
         FROM questions q
         LEFT JOIN assignment_imports ai ON ai.assignment_id = q.assignment_id
         WHERE q.id = :question_id
         LIMIT 1'
    );
    $stmt->execute(['question_id' => $questionId]);
    $row = $stmt->fetch();

    if (!$row || trim((string)($row['extracted_text'] ?? '')) === '') {
        return [];
    }

    $interaction = (string)($row['interaction_type'] ?: $row['type']);
    if (!in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
        return [];
    }

    // For imported questions, the source file is the repair authority unless
    // the teacher explicitly edited this question in the constructor.
    $existingCorrect = correct_option_ids($pdo, $questionId);
    if (
        valid_choice_answer_key($interaction, $existingCorrect)
        && question_answer_key_edited($pdo, $questionId)
    ) {
        sort($existingCorrect, SORT_NUMERIC);
        return $existingCorrect;
    }

    require_once dirname(__DIR__) . '/assignments/_import_parser.php';

    $parsed = import_parse_questions((string)$row['extracted_text']);
    if (!$parsed) return [];

    $normalize = static function (string $value): string {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    };

    $target = null;
    $position = max(1, (int)($row['position'] ?? 1));
    $candidate = $parsed[$position - 1] ?? null;
    if (is_array($candidate)) {
        $candidateInteraction = (string)($candidate['interaction_type'] ?? '');
        $sameText = $normalize((string)($candidate['text'] ?? '')) === $normalize((string)$row['text']);
        $sameKind = $candidateInteraction === $interaction;
        if ($sameText || $sameKind) {
            $target = $candidate;
        }
    }

    if ($target === null) {
        foreach ($parsed as $candidate) {
            if (!is_array($candidate)) continue;
            if ($normalize((string)($candidate['text'] ?? '')) === $normalize((string)$row['text'])) {
                $target = $candidate;
                break;
            }
        }
    }

    if ($target === null || !is_array($target['options'] ?? null)) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, text, position
         FROM question_options
         WHERE question_id = :question_id
         ORDER BY position, id'
    );
    $stmt->execute(['question_id' => $questionId]);
    $dbOptions = $stmt->fetchAll();
    $parsedOptions = array_values($target['options']);

    if (count($dbOptions) < 2 || count($dbOptions) !== count($parsedOptions)) {
        return [];
    }

    $dbByText = [];
    foreach ($dbOptions as $dbOption) {
        $key = $normalize((string)($dbOption['text'] ?? ''));
        if ($key !== '' && !isset($dbByText[$key])) {
            $dbByText[$key] = (int)$dbOption['id'];
        }
    }

    $correctIds = [];
    foreach ($parsedOptions as $index => $parsedOption) {
        if (empty($parsedOption['is_correct'])) continue;

        $optionTextKey = $normalize((string)($parsedOption['text'] ?? ''));
        $optionId = $optionTextKey !== '' && isset($dbByText[$optionTextKey])
            ? (int)$dbByText[$optionTextKey]
            : (int)($dbOptions[$index]['id'] ?? 0);

        if ($optionId > 0) $correctIds[] = $optionId;
    }

    $correctIds = array_values(array_unique(array_map('intval', $correctIds)));
    sort($correctIds, SORT_NUMERIC);
    if (!$correctIds) return [];

    $current = $existingCorrect;
    sort($current, SORT_NUMERIC);
    if ($current === $correctIds) {
        return $correctIds;
    }

    $pdo->beginTransaction();
    try {
        $reset = $pdo->prepare('UPDATE question_options SET is_correct = 0 WHERE question_id = :question_id');
        $reset->execute(['question_id' => $questionId]);

        $mark = $pdo->prepare('UPDATE question_options SET is_correct = 1 WHERE id = :id AND question_id = :question_id');
        foreach ($correctIds as $optionId) {
            $mark->execute(['id' => $optionId, 'question_id' => $questionId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [];
    }

    return $correctIds;
}

function repair_imported_answer_keys_and_scores(PDO $pdo, ?int $studentId = null, ?int $schoolId = null): int
{
    $sql =
        'SELECT DISTINCT q.id
         FROM questions q
         JOIN assignments ass ON ass.id = q.assignment_id
         JOIN assignment_imports ai ON ai.assignment_id = ass.id
         WHERE (
             q.type IN ("single", "multiple", "true_false")
             OR q.interaction_type IN ("single", "multiple", "true_false")
           )
           AND trim(COALESCE(ai.extracted_text, "")) <> ""';
    $params = [];

    if ($schoolId !== null) {
        $sql .= ' AND ass.school_id = :school_id';
        $params['school_id'] = $schoolId;
    }
    if ($studentId !== null) {
        $sql .= ' AND EXISTS (
          SELECT 1 FROM attempts at
          WHERE at.assignment_id = ass.id AND at.student_id = :student_id
        )';
        $params['student_id'] = $studentId;
    }
    $sql .= ' ORDER BY q.id LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $questionIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if (!$questionIds) return 0;

    $affectedAttempts = [];
    $repairedQuestions = 0;

    foreach ($questionIds as $questionId) {
        $typeStmt = $pdo->prepare(
            'SELECT type, interaction_type FROM questions WHERE id = :id LIMIT 1'
        );
        $typeStmt->execute(['id' => $questionId]);
        $typeRow = $typeStmt->fetch();
        if (!$typeRow) continue;

        $interaction = (string)($typeRow['interaction_type'] ?: $typeRow['type']);
        $currentCorrect = correct_option_ids($pdo, $questionId);
        if (
            valid_choice_answer_key($interaction, $currentCorrect)
            && question_answer_key_edited($pdo, $questionId)
        ) {
            continue;
        }

        $correctIds = repair_missing_correct_options($pdo, $questionId);
        if (!$correctIds) continue;

        $beforeIds = $currentCorrect;
        sort($beforeIds, SORT_NUMERIC);
        $afterIds = $correctIds;
        sort($afterIds, SORT_NUMERIC);
        if ($beforeIds !== $afterIds) {
            $repairedQuestions++;
        }

        // Regrade stored answers even when the key itself did not change.
        // Older versions could save a correct selection with score=0.
        $stmt = $pdo->prepare(
            'SELECT a.id, a.attempt_id, a.answer_text, q.points
             FROM answers a
             JOIN questions q ON q.id = a.question_id
             WHERE a.question_id = :question_id'
        );
        $stmt->execute(['question_id' => $questionId]);

        $update = $pdo->prepare(
            'UPDATE answers
             SET score = :score,
                 is_correct = :is_correct,
                 needs_review = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        foreach ($stmt->fetchAll() as $answer) {
            $selected = json_decode((string)($answer['answer_text'] ?? '[]'), true);
            $selected = is_array($selected)
                ? array_values(array_unique(array_map('intval', $selected)))
                : [];
            sort($selected, SORT_NUMERIC);

            $correct = $correctIds;
            sort($correct, SORT_NUMERIC);
            $isCorrect = ($selected && $selected === $correct) ? 1 : 0;

            $update->execute([
                'score' => $isCorrect ? (float)$answer['points'] : 0.0,
                'is_correct' => $isCorrect,
                'id' => (int)$answer['id'],
            ]);
            $affectedAttempts[(int)$answer['attempt_id']] = true;
        }
    }

    foreach (array_keys($affectedAttempts) as $attemptId) {
        $stmt = $pdo->prepare('SELECT status, termination_reason FROM attempts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $attemptId]);
        $attempt = $stmt->fetch();
        if (!$attempt || (string)$attempt['status'] === 'in_progress') continue;
        finalize_attempt(
            $pdo,
            (int)$attemptId,
            trim((string)($attempt['termination_reason'] ?? '')) !== ''
                ? (string)$attempt['termination_reason']
                : null
        );
    }

    return $repairedQuestions;
}

function grade_question_answer(PDO $pdo, int $questionId, array $payload): array
{
    $stmt = $pdo->prepare('SELECT id, type, points, correct_text, interaction_type, settings_json FROM questions WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $questionId]);
    $question = $stmt->fetch();
    if (!$question) {
        json_response(['ok' => false, 'error' => 'Вопрос не найден.'], 404);
    }

    $type = (string)$question['type'];
    $interaction = (string)($question['interaction_type'] ?: $type);
    if ($interaction === 'ordering') $interaction = 'order';
    if ($interaction === 'short_answer' || $interaction === 'image_answer') $interaction = 'text';

    $points = (float)$question['points'];
    $answerText = '';
    $score = 0.0;
    $isCorrect = 0;
    $needsReview = 0;

    // interaction_type is the authoritative UI/grading type. Older imported
    // questions can legitimately have type="text" with a choice interaction.
    if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
        $selected = array_values(array_unique(array_map('intval', (array)($payload['option_ids'] ?? []))));
        sort($selected, SORT_NUMERIC);

        $correct = expected_choice_option_ids($pdo, $questionId, $interaction);

        $answerText = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $isCorrect = ($selected === $correct && count($selected) > 0) ? 1 : 0;
        $score = $isCorrect ? $points : 0.0;
    } elseif (in_array($interaction, ['text', 'order', 'matching', 'correction'], true)) {
        if ($interaction === 'order') {
            $selected = array_values(array_map('strval', (array)($payload['order'] ?? [])));
            $expected = json_decode((string)($question['correct_text'] ?? ''), true);
            $expected = is_array($expected) ? array_values(array_map('strval', $expected)) : [];

            $answerText = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            $isCorrect = ($expected && $selected === $expected) ? 1 : 0;
            $score = $isCorrect ? $points : 0.0;
        } elseif ($interaction === 'matching') {
            $selected = (array)($payload['matches'] ?? []);
            $selected = array_map('strval', $selected);
            ksort($selected, SORT_NATURAL);

            $expected = json_decode((string)($question['correct_text'] ?? ''), true);
            $expected = is_array($expected) ? array_map('strval', $expected) : [];
            ksort($expected, SORT_NATURAL);

            $answerText = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $isCorrect = ($expected && $selected === $expected) ? 1 : 0;
            $score = $isCorrect ? $points : 0.0;
        } else {
            $answerText = trim((string)($payload['answer_text'] ?? ''));
            $actual = normalize_answer_text($answerText);
            $rawExpected = trim((string)($question['correct_text'] ?? ''));

            $alternatives = array_values(array_filter(array_map(
                static fn(string $value): string => normalize_answer_text($value),
                preg_split('/\\s*\\|\\s*/u', $rawExpected) ?: []
            )));

            if ($alternatives) {
                $isCorrect = in_array($actual, $alternatives, true) ? 1 : 0;
                $score = $isCorrect ? $points : 0.0;
            } else {
                $needsReview = $answerText !== '' ? 1 : 0;
                $score = 0.0;
                $isCorrect = 0;
            }
        }
    } elseif ($interaction === 'number') {
        $answerText = trim((string)($payload['answer_text'] ?? ''));
        $expected = trim((string)($question['correct_text'] ?? ''));
        if (is_numeric($answerText) && is_numeric($expected)) {
            $isCorrect = abs((float)$answerText - (float)$expected) < 0.0000001 ? 1 : 0;
        }
        $score = $isCorrect ? $points : 0.0;
    } else {
        $answerText = trim((string)($payload['answer_text'] ?? ''));
        $needsReview = $answerText !== '' ? 1 : 0;
        $score = 0.0;
        $isCorrect = 0;
    }

    return [
        'answer_text' => $answerText,
        'score' => $score,
        'is_correct' => $isCorrect,
        'needs_review' => $needsReview,
    ];
}

function save_attempt_answers_snapshot(PDO $pdo, int $attemptId, array $answersSnapshot): int
{
    if (!$answersSnapshot) return 0;

    $stmt = $pdo->prepare(
        'SELECT q.id
         FROM questions q
         JOIN attempts a ON a.assignment_id = q.assignment_id
         WHERE a.id = :attempt_id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $validIds = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(), 'id')), true);
    if (!$validIds) return 0;

    // Grade first, before opening the write transaction. Imported-key repair
    // may itself need a short write, and nested transactions are not allowed.
    $gradedRows = [];
    foreach ($answersSnapshot as $answer) {
        if (!is_array($answer)) continue;
        $questionId = (int)($answer['question_id'] ?? 0);
        if ($questionId < 1 || !isset($validIds[$questionId])) continue;

        $payload = is_array($answer['payload'] ?? null) ? $answer['payload'] : [];
        $gradedRows[$questionId] = grade_question_answer($pdo, $questionId, $payload);
    }
    if (!$gradedRows) return 0;

    $upsert = $pdo->prepare(
        'INSERT INTO answers
         (attempt_id, question_id, answer_text, score, is_correct, needs_review, updated_at)
         VALUES
         (:attempt_id, :question_id, :answer_text, :score, :is_correct, :needs_review, CURRENT_TIMESTAMP)
         ON CONFLICT(attempt_id, question_id) DO UPDATE SET
           answer_text = excluded.answer_text,
           score = excluded.score,
           is_correct = excluded.is_correct,
           needs_review = excluded.needs_review,
           updated_at = CURRENT_TIMESTAMP'
    );

    $pdo->beginTransaction();
    try {
        foreach ($gradedRows as $questionId => $graded) {
            $upsert->execute([
                'attempt_id' => $attemptId,
                'question_id' => (int)$questionId,
                'answer_text' => $graded['answer_text'],
                'score' => $graded['score'],
                'is_correct' => $graded['is_correct'],
                'needs_review' => $graded['needs_review'],
            ]);
        }

        $pdo->prepare('UPDATE attempts SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $attemptId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return count($gradedRows);
}

function save_attempt_answer(PDO $pdo, int $attemptId, int $questionId, array $payload): array
{
    $stmt = $pdo->prepare(
        'SELECT q.id
         FROM questions q
         JOIN attempts a ON a.assignment_id = q.assignment_id
         WHERE a.id = :attempt_id AND q.id = :question_id
         LIMIT 1'
    );
    $stmt->execute(['attempt_id' => $attemptId, 'question_id' => $questionId]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Вопрос не относится к этой попытке.'], 422);
    }

    $graded = grade_question_answer($pdo, $questionId, $payload);
    $stmt = $pdo->prepare(
        'INSERT INTO answers (attempt_id, question_id, answer_text, score, is_correct, needs_review, updated_at)
         VALUES (:attempt_id, :question_id, :answer_text, :score, :is_correct, :needs_review, CURRENT_TIMESTAMP)
         ON CONFLICT(attempt_id, question_id) DO UPDATE SET
           answer_text = excluded.answer_text,
           score = excluded.score,
           is_correct = excluded.is_correct,
           needs_review = excluded.needs_review,
           updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'answer_text' => $graded['answer_text'],
        'score' => $graded['score'],
        'is_correct' => $graded['is_correct'],
        'needs_review' => $graded['needs_review'],
    ]);

    $pdo->prepare('UPDATE attempts SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $attemptId]);

    return $graded;
}

function grade_from_percent(float $percent): string
{
    if ($percent >= 90) return '5';
    if ($percent >= 75) return '4';
    if ($percent >= 50) return '3';
    return '2';
}

function regrade_attempt_answers(PDO $pdo, int $attemptId): void
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.question_id, a.answer_text,
                q.type, q.interaction_type
         FROM answers a
         JOIN questions q ON q.id = a.question_id
         WHERE a.attempt_id = :attempt_id
         ORDER BY a.id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return;

    // Grade outside the transaction. This keeps the SQLite write lock short.
    $updates = [];
    foreach ($rows as $row) {
        $interaction = (string)($row['interaction_type'] ?: $row['type']);
        if ($interaction === 'ordering') $interaction = 'order';
        if ($interaction === 'short_answer' || $interaction === 'image_answer') $interaction = 'text';

        $raw = (string)($row['answer_text'] ?? '');
        if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
            $decoded = json_decode($raw, true);
            $payload = ['option_ids' => is_array($decoded) ? $decoded : []];
        } elseif ($interaction === 'order') {
            $decoded = json_decode($raw, true);
            $payload = ['order' => is_array($decoded) ? $decoded : []];
        } elseif ($interaction === 'matching') {
            $decoded = json_decode($raw, true);
            $payload = ['matches' => is_array($decoded) ? $decoded : []];
        } else {
            $payload = ['answer_text' => $raw];
        }

        $updates[] = [
            'id' => (int)$row['id'],
            'graded' => grade_question_answer($pdo, (int)$row['question_id'], $payload),
        ];
    }

    $update = $pdo->prepare(
        'UPDATE answers
         SET score = :score,
             is_correct = :is_correct,
             needs_review = :needs_review,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($updates as $item) {
            $graded = $item['graded'];
            $update->execute([
                'score' => (float)$graded['score'],
                'is_correct' => (int)$graded['is_correct'],
                'needs_review' => (int)$graded['needs_review'],
                'id' => (int)$item['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function repair_zero_total_attempts(PDO $pdo, ?int $studentId = null, ?int $schoolId = null): int
{
    $sql =
        'SELECT DISTINCT at.id
         FROM attempts at
         JOIN assignments ass ON ass.id = at.assignment_id
         WHERE at.status <> "in_progress"
           AND COALESCE(at.score, 0) = 0
           AND EXISTS (
             SELECT 1 FROM answers ans WHERE ans.attempt_id = at.id
           )';
    $params = [];

    if ($studentId !== null) {
        $sql .= ' AND at.student_id = :student_id';
        $params['student_id'] = $studentId;
    }
    if ($schoolId !== null) {
        $sql .= ' AND ass.school_id = :school_id';
        $params['school_id'] = $schoolId;
    }

    $sql .= ' ORDER BY at.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attemptIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    $repaired = 0;
    foreach ($attemptIds as $attemptId) {
        $stmt = $pdo->prepare(
            'SELECT termination_reason FROM attempts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $attemptId]);
        $row = $stmt->fetch();
        if (!$row) continue;

        $before = $pdo->prepare('SELECT COALESCE(score, 0) FROM attempts WHERE id = :id');
        $before->execute(['id' => $attemptId]);
        $beforeScore = (float)$before->fetchColumn();

        finalize_attempt(
            $pdo,
            $attemptId,
            trim((string)($row['termination_reason'] ?? '')) !== ''
                ? (string)$row['termination_reason']
                : null
        );

        $after = $pdo->prepare('SELECT COALESCE(score, 0) FROM attempts WHERE id = :id');
        $after->execute(['id' => $attemptId]);
        $afterScore = (float)$after->fetchColumn();

        if (abs($afterScore - $beforeScore) > 0.000001) {
            $repaired++;
        }
    }

    return $repaired;
}

function finalize_attempt(PDO $pdo, int $attemptId, ?string $reason = null): array
{
    // Never trust a stale score stored by an older grading implementation.
    // Recalculate every saved answer from its actual value before the total.
    regrade_attempt_answers($pdo, $attemptId);

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0) FROM questions
         WHERE assignment_id = (SELECT assignment_id FROM attempts WHERE id = :attempt_id)'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $maxScore = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(score), 0) AS total_score,
                COALESCE(MAX(needs_review), 0) AS needs_review
         FROM answers
         WHERE attempt_id = :attempt_id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $row = $stmt->fetch();
    $score = (float)($row['total_score'] ?? 0);
    $needsReview = (int)($row['needs_review'] ?? 0);

    $percent = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;
    $grade = grade_from_percent($percent);
    $status = $needsReview ? 'needs_review' : 'submitted';

    $stmt = $pdo->prepare(
        'UPDATE attempts
         SET submitted_at = COALESCE(submitted_at, CURRENT_TIMESTAMP),
             score = :score,
             max_score = :max_score,
             percent = :percent,
             grade = :grade,
             status = :status,
             termination_reason = COALESCE(:termination_reason, termination_reason),
             last_seen_at = CURRENT_TIMESTAMP
         WHERE id = :attempt_id'
    );
    $stmt->execute([
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => $grade,
        'status' => $status,
        'termination_reason' => $reason,
        'attempt_id' => $attemptId,
    ]);

    return [
        'attempt_id' => $attemptId,
        'score' => $score,
        'max_score' => $maxScore,
        'percent' => $percent,
        'grade' => $grade,
        'status' => $status,
        'termination_reason' => $reason,
    ];
}


function variant_label_from_index(int $index): string
{
    $labels = ['A', 'B', 'C', 'D'];
    return $labels[max(0, min(3, $index))] ?? 'A';
}

function deterministic_variant_order(array $values, string $seed): array
{
    $decorated = [];
    foreach (array_values($values) as $position => $value) {
        $decorated[] = [
            'value' => $value,
            'hash' => hash('sha256', $seed . '|' . (string)$position . '|' . (string)$value),
        ];
    }

    usort($decorated, static fn(array $a, array $b): int => strcmp($a['hash'], $b['hash']));
    return array_values(array_map(static fn(array $row) => $row['value'], $decorated));
}

function variant_permutation(array $values, string $seed, int $variantIndex): array
{
    $base = deterministic_variant_order($values, $seed);
    $count = count($base);
    if ($count <= 1 || $variantIndex <= 0) {
        return $base;
    }

    $round = intdiv($variantIndex, $count);
    $shift = $variantIndex % $count;
    if ($shift > 0) {
        $base = array_merge(array_slice($base, $shift), array_slice($base, 0, $shift));
    }
    if ($round % 2 === 1) {
        $base = array_reverse($base);
    }

    return array_values($base);
}

function build_attempt_variant(PDO $pdo, int $assignmentId, int $studentId): array
{
    // UROVIA now uses one canonical test per assignment. Mixed question types
    // are supported inside the same test; A/B/C/D variants are no longer used.
    $stmt = $pdo->prepare(
        'SELECT id
         FROM questions
         WHERE assignment_id = :assignment_id
         ORDER BY position, id'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    $questionOrder = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    $optionOrder = [];
    $optionStmt = $pdo->prepare(
        'SELECT id
         FROM question_options
         WHERE question_id = :question_id
         ORDER BY position, id'
    );
    foreach ($questionOrder as $questionId) {
        $optionStmt->execute(['question_id' => $questionId]);
        $optionIds = array_map('intval', array_column($optionStmt->fetchAll(), 'id'));
        if ($optionIds) {
            $optionOrder[(string)$questionId] = $optionIds;
        }
    }

    return [
        'variant_index' => 0,
        'variant_label' => '',
        'question_order_json' => json_encode($questionOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'option_order_json' => json_encode($optionOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'structured_order_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function decoded_json_array(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
