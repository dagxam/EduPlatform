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
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
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
    $points = (float)$question['points'];
    $answerText = '';
    $score = 0.0;
    $isCorrect = 0;
    $needsReview = 0;

    if (in_array($type, ['single', 'multiple', 'true_false'], true)) {
        $selected = array_values(array_unique(array_map('intval', (array)($payload['option_ids'] ?? []))));
        sort($selected, SORT_NUMERIC);

        $stmt = $pdo->prepare(
            'SELECT id FROM question_options WHERE question_id = :question_id AND is_correct = 1 ORDER BY id'
        );
        $stmt->execute(['question_id' => $questionId]);
        $correct = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        sort($correct, SORT_NUMERIC);

        $answerText = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $isCorrect = ($selected === $correct && count($selected) > 0) ? 1 : 0;
        $score = $isCorrect ? $points : 0.0;
    } elseif ($type === 'text') {
        $interaction = (string)($question['interaction_type'] ?? 'text');
        if ($interaction === 'ordering') $interaction = 'order';
        if ($interaction === 'short_answer' || $interaction === 'image_answer') $interaction = 'text';

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
    } elseif ($type === 'number') {
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

function finalize_attempt(PDO $pdo, int $attemptId, ?string $reason = null): array
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0) FROM questions
         WHERE assignment_id = (SELECT assignment_id FROM attempts WHERE id = :attempt_id)'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $maxScore = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(score), 0), COALESCE(MAX(needs_review), 0)
         FROM answers WHERE attempt_id = :attempt_id'
    );
    $stmt->execute(['attempt_id' => $attemptId]);
    $row = $stmt->fetch();
    $score = (float)($row[0] ?? 0);
    $needsReview = (int)($row[1] ?? 0);

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
    $stmt = $pdo->prepare(
        'SELECT variant_count, shuffle_questions, shuffle_options, shuffle_structured
         FROM assignments
         WHERE id = :assignment_id
         LIMIT 1'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    $settings = $stmt->fetch();
    if (!$settings) {
        json_response(['ok' => false, 'error' => 'Задание не найдено.'], 404);
    }

    $variantCount = max(1, min(4, (int)($settings['variant_count'] ?? 1)));
    $variantIndex = $variantCount > 1
        ? (int)(sprintf('%u', crc32($assignmentId . ':' . $studentId)) % $variantCount)
        : 0;
    $variantLabel = variant_label_from_index($variantIndex);
    $seedBase = 'assignment:' . $assignmentId;

    $stmt = $pdo->prepare(
        'SELECT id, interaction_type, settings_json
         FROM questions
         WHERE assignment_id = :assignment_id
         ORDER BY position, id'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    $questions = $stmt->fetchAll();

    $questionOrder = array_map('intval', array_column($questions, 'id'));
    if ($variantCount > 1 && (int)($settings['shuffle_questions'] ?? 0) === 1) {
        $questionOrder = variant_permutation($questionOrder, $seedBase . '|questions', $variantIndex);
    }

    $optionOrder = [];
    $structuredOrder = [];

    $optionStmt = $pdo->prepare(
        'SELECT id
         FROM question_options
         WHERE question_id = :question_id
         ORDER BY position, id'
    );

    foreach ($questions as $question) {
        $questionId = (int)$question['id'];

        $optionStmt->execute(['question_id' => $questionId]);
        $optionIds = array_map('intval', array_column($optionStmt->fetchAll(), 'id'));
        if (
            $variantCount > 1
            && (int)($settings['shuffle_options'] ?? 0) === 1
            && count($optionIds) > 1
        ) {
            $optionIds = variant_permutation($optionIds, $seedBase . '|options:' . $questionId, $variantIndex);
        }
        if ($optionIds) {
            $optionOrder[(string)$questionId] = $optionIds;
        }

        if ($variantCount > 1 && (int)($settings['shuffle_structured'] ?? 0) === 1) {
            $interaction = (string)($question['interaction_type'] ?? '');
            $questionSettings = json_decode((string)($question['settings_json'] ?? ''), true);
            $questionSettings = is_array($questionSettings) ? $questionSettings : [];

            if (in_array($interaction, ['order', 'ordering'], true) && is_array($questionSettings['items'] ?? null)) {
                $keys = array_map('strval', array_keys($questionSettings['items']));
                if (count($keys) > 1) {
                    $structuredOrder[(string)$questionId] = [
                        'items' => variant_permutation($keys, $seedBase . '|order:' . $questionId, $variantIndex),
                    ];
                }
            } elseif ($interaction === 'matching') {
                $leftKeys = is_array($questionSettings['left'] ?? null)
                    ? array_map('strval', array_keys($questionSettings['left']))
                    : [];
                $rightKeys = is_array($questionSettings['right'] ?? null)
                    ? array_map('strval', array_keys($questionSettings['right']))
                    : [];

                $structuredOrder[(string)$questionId] = [
                    'left' => $leftKeys,
                    'right' => count($rightKeys) > 1
                        ? variant_permutation($rightKeys, $seedBase . '|matching-right:' . $questionId, $variantIndex)
                        : $rightKeys,
                ];
            }
        }
    }

    return [
        'variant_index' => $variantIndex,
        'variant_label' => $variantLabel,
        'question_order_json' => json_encode($questionOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'option_order_json' => json_encode($optionOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'structured_order_json' => json_encode($structuredOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
