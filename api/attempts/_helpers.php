<?php
declare(strict_types=1);

function attempt_for_student(PDO $pdo, int $attemptId, int $studentId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, ass.focus_policy, ass.time_limit_minutes, ass.max_attempts, ass.status AS assignment_status
         FROM attempts a
         JOIN assignments ass ON ass.id = a.assignment_id
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
        $interaction = (string)($question['interaction_type'] ?? 'short_answer');

        if ($interaction === 'ordering') {
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
