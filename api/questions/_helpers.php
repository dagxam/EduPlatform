<?php
declare(strict_types=1);

function question_editor_assignment(PDO $pdo, array $user, int $assignmentId, bool $requireEditable = true): array
{
    if ($assignmentId < 1) {
        json_response(['ok' => false, 'error' => 'Не указано задание.'], 422);
    }

    $schoolId = require_active_school($user, false);
    $stmt = $pdo->prepare(
        'SELECT id, teacher_id, school_id, subject_id, title, status
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

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE assignment_id = :assignment_id');
    $stmt->execute(['assignment_id' => $assignmentId]);
    $attemptsCount = (int)$stmt->fetchColumn();
    $assignment['attempts_count'] = $attemptsCount;
    $assignment['editable'] = $assignment['status'] === 'draft' && $attemptsCount === 0;

    if ($requireEditable && !$assignment['editable']) {
        json_response([
            'ok' => false,
            'error' => 'Редактировать вопросы можно только в черновике до начала попыток учеников.',
            'code' => 'ASSIGNMENT_NOT_EDITABLE',
        ], 409);
    }

    return $assignment;
}

function question_editor_question(PDO $pdo, array $user, int $questionId, bool $requireEditable = true): array
{
    $stmt = $pdo->prepare(
        'SELECT q.*, a.school_id, a.teacher_id, a.subject_id, a.status AS assignment_status
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
    return $question;
}

function question_editor_normalize_payload(array $data): array
{
    $interaction = trim((string)($data['interaction_type'] ?? 'single'));
    $allowed = ['single', 'multiple', 'true_false', 'ordering', 'matching', 'short_answer', 'number', 'correction', 'image_answer', 'essay'];
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
    } elseif ($interaction === 'ordering') {
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
            json_response(['ok' => false, 'error' => 'Укажите правильный ответ. Можно разделить допустимые варианты символом |.'], 422);
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
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE assignment_id = :assignment_id');
    $stmt->execute(['assignment_id' => $assignmentId]);
    $count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'UPDATE assignment_imports
         SET parsed_question_count = :count
         WHERE assignment_id = :assignment_id'
    );
    $stmt->execute(['count' => $count, 'assignment_id' => $assignmentId]);
}
