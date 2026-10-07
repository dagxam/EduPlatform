<?php
declare(strict_types=1);

function question_editor_assignment(PDO $pdo, array $user, int $assignmentId, bool $requireEditable = true): array
{
    if ($assignmentId < 1) {
        json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
    }

    $schoolId = require_active_school($user, false);
    $stmt = $pdo->prepare(
        'SELECT id, teacher_id, school_id, subject_id, title, status, workflow_status
         FROM assignments
         WHERE id = :id AND school_id = :school_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $assignmentId, 'school_id' => $schoolId]);
    $assignment = $stmt->fetch();
    if (!$assignment) {
        json_response(['ok' => false, 'error' => 'Задание не найдено в выбранной школе.'], 404);
    }

    if (!can_manage_school($user, $schoolId) && (int)$assignment['teacher_id'] !== (int)$user['id']) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM teacher_subjects
             WHERE school_id = :school_id
               AND teacher_id = :teacher_id
               AND subject_id = :subject_id
             LIMIT 1'
        );
        $stmt->execute([
            'school_id' => $schoolId,
            'teacher_id' => (int)$user['id'],
            'subject_id' => (int)$assignment['subject_id'],
        ]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
        }
    }

    $stmt = $pdo->prepare(
        'SELECT status, started_at, last_seen_at
         FROM attempts
         WHERE assignment_id = :assignment_id'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    $attemptRows = $stmt->fetchAll();
    $attemptsCount = count($attemptRows);
    $activeAttemptsCount = 0;
    $staleAttemptsCount = 0;
    $now = time();
    foreach ($attemptRows as $attemptRow) {
        if ((string)($attemptRow['status'] ?? '') !== 'in_progress') continue;
        $lastSeenRaw = trim((string)($attemptRow['last_seen_at'] ?? ''));
        $startedRaw = trim((string)($attemptRow['started_at'] ?? ''));
        $activityRaw = $lastSeenRaw !== '' ? $lastSeenRaw : $startedRaw;
        $activityAt = $activityRaw !== '' ? strtotime($activityRaw . ' UTC') : false;
        if ($activityAt !== false && ($now - $activityAt) <= 90) {
            $activeAttemptsCount++;
        } else {
            $staleAttemptsCount++;
        }
    }

    $assignment['attempts_count'] = $attemptsCount;
    $assignment['active_attempts_count'] = $activeAttemptsCount;
    $assignment['stale_attempts_count'] = $staleAttemptsCount;

    // Question revisions pin every already-started attempt to its own question IDs.
    // Therefore even a genuinely active attempt must not block editing: changes
    // become a new revision for future attempts and cannot alter the open one.
    $assignment['editable'] =
        (string)($assignment['workflow_status'] ?? 'draft') !== 'completed'
        && (string)$assignment['status'] !== 'closed';

    if ($requireEditable && !$assignment['editable']) {
        json_response([
            'ok' => false,
            'error' => 'Завершённое задание нельзя редактировать. Сначала верните его в активное состояние.',
            'code' => 'ASSIGNMENT_NOT_EDITABLE',
            'active_attempts_count' => $activeAttemptsCount,
            'stale_attempts_count' => $staleAttemptsCount,
        ], 409);
    }

    return $assignment;
}

function question_editor_question(PDO $pdo, array $user, int $questionId, bool $requireEditable = true): array
{
    $stmt = $pdo->prepare(
        'SELECT q.*, a.school_id, a.teacher_id, a.subject_id,
                a.status AS assignment_status, a.workflow_status AS assignment_workflow_status
         FROM questions q
         JOIN assignments a ON a.id = q.assignment_id
         WHERE q.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $questionId]);
    $question = $stmt->fetch();
    if (!$question) {
        json_response(['ok' => false, 'error' => 'Вопрос не найден.'], 404);
    }

    question_editor_assignment($pdo, $user, (int)$question['assignment_id'], $requireEditable);
    if ($requireEditable && array_key_exists('is_active', $question) && (int)$question['is_active'] !== 1) {
        json_response([
            'ok' => false,
            'error' => 'Открыта устаревшая версия вопроса. Обновите редактор задания.',
            'code' => 'QUESTION_REVISION_STALE',
        ], 409);
    }
    return $question;
}

function question_editor_assignment_has_history(PDO $pdo, int $assignmentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM attempts
         WHERE assignment_id = :assignment_id
         LIMIT 1'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    return (bool)$stmt->fetchColumn();
}

function question_editor_fork_revision(PDO $pdo, int $questionId, array $overrides = []): int
{
    $stmt = $pdo->prepare(
        'SELECT id, assignment_id, type, text, points, position, correct_text,
                interaction_type, settings_json, is_active, revision_of_id
         FROM questions
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $questionId]);
    $source = $stmt->fetch();
    if (!$source) {
        throw new RuntimeException('Вопрос для создания ревизии не найден.');
    }
    if ((int)($source['is_active'] ?? 1) !== 1) {
        throw new RuntimeException('Нельзя создать ревизию из устаревшей версии вопроса.');
    }

    $value = static function (string $key) use ($source, $overrides) {
        return array_key_exists($key, $overrides) ? $overrides[$key] : ($source[$key] ?? null);
    };

    $rootRevisionId = (int)($source['revision_of_id'] ?? 0);
    if ($rootRevisionId < 1) {
        $rootRevisionId = (int)$source['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text,
          interaction_type, settings_json, is_active, revision_of_id)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text,
          :interaction_type, :settings_json, 1, :revision_of_id)'
    );
    $insert->execute([
        'assignment_id' => (int)$source['assignment_id'],
        'type' => (string)$value('type'),
        'text' => (string)$value('text'),
        'points' => (float)$value('points'),
        'position' => (int)$value('position'),
        'correct_text' => $value('correct_text'),
        'interaction_type' => $value('interaction_type'),
        'settings_json' => $value('settings_json'),
        'revision_of_id' => $rootRevisionId,
    ]);
    $newQuestionId = (int)$pdo->lastInsertId();

    $copyOptions = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, position)
         SELECT :new_question_id, text, is_correct, position
         FROM question_options
         WHERE question_id = :old_question_id
         ORDER BY position, id'
    );
    $copyOptions->execute([
        'new_question_id' => $newQuestionId,
        'old_question_id' => $questionId,
    ]);

    $copyAssets = $pdo->prepare(
        'INSERT INTO question_assets (question_id, stored_name, original_name, mime_type, position)
         SELECT :new_question_id, stored_name, original_name, mime_type, position
         FROM question_assets
         WHERE question_id = :old_question_id
         ORDER BY position, id'
    );
    $copyAssets->execute([
        'new_question_id' => $newQuestionId,
        'old_question_id' => $questionId,
    ]);

    $pdo->prepare('UPDATE questions SET is_active = 0 WHERE id = :id')
        ->execute(['id' => $questionId]);

    return $newQuestionId;
}

function question_editor_normalize_payload(array $data): array
{
    $interaction = trim((string)($data['interaction_type'] ?? 'single'));
    $aliases = [
        'ordering' => 'order',
        'short_answer' => 'text',
        'image_answer' => 'text',
    ];
    $interaction = $aliases[$interaction] ?? $interaction;

    // Новые вопросы UVORIA следуют формату шаблона импорта.
    // essay оставлен только для совместимости со старыми черновиками.
    $allowed = ['single', 'multiple', 'true_false', 'order', 'matching', 'text', 'number', 'correction', 'essay'];
    if (!in_array($interaction, $allowed, true)) {
        json_response(['ok' => false, 'error' => 'Неизвестный тип вопроса.'], 422);
    }

    $text = trim((string)($data['text'] ?? ''));
    if ($text === '') {
        json_response(['ok' => false, 'error' => 'Введите текст вопроса.'], 422);
    }

    $points = (float)($data['points'] ?? 1);
    if ($points <= 0 || $points > 1000) {
        json_response(['ok' => false, 'error' => 'Баллы должны быть больше 0 и не более 1000.'], 422);
    }

    $dbType = 'text';
    $correctText = null;
    $settings = [];
    $options = [];

    if (in_array($interaction, ['single', 'multiple'], true)) {
        $dbType = $interaction;
        $rawOptions = is_array($data['options'] ?? null) ? $data['options'] : [];
        foreach ($rawOptions as $option) {
            if (!is_array($option)) continue;
            $optionText = trim((string)($option['text'] ?? ''));
            if ($optionText === '') continue;
            $options[] = [
                'text' => $optionText,
                'is_correct' => !empty($option['is_correct']) ? 1 : 0,
            ];
        }
        if (count($options) < 2) {
            json_response(['ok' => false, 'error' => 'Для тестового вопроса нужно минимум два варианта ответа.'], 422);
        }
        $correctCount = count(array_filter($options, static fn(array $o): bool => (int)$o['is_correct'] === 1));
        if ($interaction === 'single' && $correctCount !== 1) {
            json_response(['ok' => false, 'error' => 'Для вопроса с одним ответом отметьте ровно один правильный вариант.'], 422);
        }
        if ($interaction === 'multiple' && $correctCount < 1) {
            json_response(['ok' => false, 'error' => 'Отметьте хотя бы один правильный вариант.'], 422);
        }
    } elseif ($interaction === 'true_false') {
        $dbType = 'true_false';
        $correct = (string)($data['true_false_answer'] ?? 'true') === 'false' ? 'false' : 'true';
        $options = [
            ['text' => 'Верно', 'is_correct' => $correct === 'true' ? 1 : 0],
            ['text' => 'Неверно', 'is_correct' => $correct === 'false' ? 1 : 0],
        ];
    } elseif ($interaction === 'order') {
        $items = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            is_array($data['ordering_items'] ?? null) ? $data['ordering_items'] : []
        ), static fn(string $value): bool => $value !== ''));
        if (count($items) < 2) {
            json_response(['ok' => false, 'error' => 'Для хронологии нужно минимум два элемента.'], 422);
        }
        $mapped = [];
        foreach ($items as $i => $value) $mapped[(string)($i + 1)] = $value;
        $order = array_keys($mapped);
        $settings = ['items' => $mapped, 'correct_order' => $order];
        $correctText = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($interaction === 'matching') {
        $pairs = is_array($data['matching_pairs'] ?? null) ? $data['matching_pairs'] : [];
        $left = [];
        $right = [];
        $map = [];
        $index = 0;
        foreach ($pairs as $pair) {
            if (!is_array($pair)) continue;
            $leftText = trim((string)($pair['left'] ?? ''));
            $rightText = trim((string)($pair['right'] ?? ''));
            if ($leftText === '' || $rightText === '') continue;
            $index++;
            $lk = 'L' . $index;
            $rk = 'R' . $index;
            $left[$lk] = $leftText;
            $right[$rk] = $rightText;
            $map[$lk] = $rk;
        }
        if (count($map) < 2) {
            json_response(['ok' => false, 'error' => 'Для соответствий нужно минимум две пары.'], 422);
        }
        $settings = ['left' => $left, 'right' => $right, 'pairs' => $map];
        $correctText = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($interaction === 'correction') {
        $originalText = trim((string)($data['original_text'] ?? ''));
        $correctText = trim((string)($data['correct_text'] ?? ''));
        if ($originalText === '' || $correctText === '') {
            json_response(['ok' => false, 'error' => 'Укажите текст с ошибкой и правильный вариант.'], 422);
        }
        $settings = ['original_text' => $originalText];
    } elseif ($interaction === 'image_answer') {
        $correctText = trim((string)($data['correct_text'] ?? ''));
        if ($correctText === '') {
            json_response(['ok' => false, 'error' => 'Укажите правильный ответ к изображению.'], 422);
        }
        $settings = ['answer_hint' => trim((string)($data['answer_hint'] ?? 'Событие / год / место / объект'))];
    } elseif ($interaction === 'essay') {
        $dbType = 'essay';
        $correctText = null;
    } elseif ($interaction === 'number') {
        $dbType = 'number';
        $correctText = trim((string)($data['correct_text'] ?? ''));
        if ($correctText === '' || !is_numeric(str_replace(',', '.', $correctText))) {
            json_response(['ok' => false, 'error' => 'Укажите числовой правильный ответ.'], 422);
        }
        $correctText = str_replace(',', '.', $correctText);
    } else {
        $correctText = trim((string)($data['correct_text'] ?? ''));
        if ($correctText === '') {
            json_response(['ok' => false, 'error' => 'Укажите правильный ответ.'], 422);
        }

        if ($interaction === 'text') {
            $rawAlternatives = $data['alternatives'] ?? [];
            if (is_string($rawAlternatives)) {
                $rawAlternatives = preg_split('/\s*\|\s*/u', $rawAlternatives) ?: [];
            }
            $alternatives = [];
            foreach (is_array($rawAlternatives) ? $rawAlternatives : [] as $alternative) {
                $value = trim((string)$alternative);
                if ($value !== '') $alternatives[] = $value;
            }

            $allAnswers = array_merge(
                preg_split('/\s*\|\s*/u', $correctText) ?: [],
                $alternatives
            );
            $seen = [];
            $normalizedAnswers = [];
            foreach ($allAnswers as $answerValue) {
                $answerValue = trim((string)$answerValue);
                if ($answerValue === '') continue;
                $key = function_exists('mb_strtolower') ? mb_strtolower($answerValue) : strtolower($answerValue);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $normalizedAnswers[] = $answerValue;
            }
            $correctText = implode(' | ', $normalizedAnswers);
        }
    }

    return [
        'type' => $dbType,
        'interaction_type' => $interaction,
        'text' => $text,
        'points' => $points,
        'correct_text' => $correctText,
        'settings_json' => $settings ? json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'options' => $options,
    ];
}

function question_editor_replace_options(PDO $pdo, int $questionId, array $options): void
{
    $pdo->prepare('DELETE FROM question_options WHERE question_id = :question_id')
        ->execute(['question_id' => $questionId]);

    if (!$options) return;

    $stmt = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, position)
         VALUES (:question_id, :text, :is_correct, :position)'
    );
    foreach ($options as $index => $option) {
        $stmt->execute([
            'question_id' => $questionId,
            'text' => (string)$option['text'],
            'is_correct' => (int)$option['is_correct'],
            'position' => $index + 1,
        ]);
    }
}

function question_editor_refresh_import_count(PDO $pdo, int $assignmentId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE assignment_id = :assignment_id AND is_active = 1');
    $stmt->execute(['assignment_id' => $assignmentId]);
    $count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'UPDATE assignment_imports
         SET parsed_question_count = :count
         WHERE assignment_id = :assignment_id'
    );
    $stmt->execute(['count' => $count, 'assignment_id' => $assignmentId]);
}
