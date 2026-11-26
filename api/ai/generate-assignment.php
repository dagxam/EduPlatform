<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/questions/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$paper = ($data['mode'] ?? 'online') === 'paper';
$questionMode = (string)($data['question_mode'] ?? 'mixed');
if(!in_array($questionMode,['mixed','single','multiple'],true)) $questionMode='mixed';
$subjectId = (int)($data['subject_id'] ?? 0);
$topic = trim((string)($data['topic'] ?? ''));
$gradeLevel = trim((string)($data['grade_level'] ?? ''));
$difficulty = trim((string)($data['difficulty'] ?? 'medium'));
$questionCount = max($paper ? 1 : 3, min(20, (int)($data['question_count'] ?? 10)));
$customTitle = trim((string)($data['title'] ?? ''));

if ($subjectId < 1 || $topic === '') {
    json_response(['ok' => false, 'error' => 'Выберите предмет и укажите тему задания.'], 422);
}
if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
    $difficulty = 'medium';
}
if (mb_strlen($topic) > 300 || mb_strlen($gradeLevel) > 80 || mb_strlen($customTitle) > 180) {
    json_response(['ok' => false, 'error' => 'Слишком длинное название, тема или класс.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);
if (!$manager && !can_teach_school($user, $schoolId)) {
    json_response(['ok' => false, 'error' => 'Нет прав для создания задания в этой школе.'], 403);
}

if ($manager) {
    $stmt = $pdo->prepare(
        'SELECT s.name
         FROM subjects s
         JOIN school_subjects ss ON ss.subject_id = s.id
         WHERE ss.school_id = :school_id AND ss.subject_id = :subject_id AND ss.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'subject_id' => $subjectId]);
} else {
    $stmt = $pdo->prepare(
        'SELECT s.name
         FROM subjects s
         JOIN teacher_subjects ts ON ts.subject_id = s.id
         WHERE ts.school_id = :school_id AND ts.teacher_id = :teacher_id AND ts.subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => $subjectId,
    ]);
}
$subjectName = trim((string)($stmt->fetchColumn() ?: ''));
if ($subjectName === '') {
    json_response(['ok' => false, 'error' => 'Этот предмет недоступен вашему аккаунту.'], 403);
}

if($paper) subscription_require_paper($pdo,$user);
$state = subscription_assert_ai_capacity($pdo, $user, $schoolId);

if (!ai_is_configured()) {
    json_response([
        'ok' => false,
        'error' => 'ИИ UROVIA ещё не подключён главным администратором.',
        'code' => 'AI_PROVIDER_NOT_CONFIGURED',
    ], 503);
}

$difficultyLabel = match ($difficulty) {
    'easy' => 'лёгкая',
    'hard' => 'повышенная',
    default => 'средняя',
};
$gradeText = $gradeLevel !== '' ? $gradeLevel : 'класс не указан';
$prompt =
    "Ты создаёшь учебное задание для российской образовательной платформы UROVIA.\n" .
    "Верни ТОЛЬКО корректный JSON без markdown и пояснений.\n\n" .
    "Предмет: {$subjectName}\n" .
    "Класс/уровень: {$gradeText}\n" .
    "Тема: {$topic}\n" .
    "Сложность: {$difficultyLabel}\n" .
    "Количество вопросов: {$questionCount}\n\n" .
    "Нужен объект вида {\"title\":\"краткое название\",\"description\":\"1-2 предложения\",\"questions\":[...]}.\n" .
    "Каждый question: interaction_type, text, points и данные правильного ответа.\n" .
    "Разрешённые interaction_type: single, multiple, true_false, text, number.\n" .
    "Для single: 3-5 вариантов options и ровно один is_correct=true.\n" .
    "Для multiple: 4-6 вариантов и минимум один правильный.\n" .
    "Для true_false: true_false_answer = \"true\" или \"false\".\n" .
    "Для text: correct_text, при необходимости alternatives массивом.\n" .
    "Для number: correct_text как число.\n" .
    "Не используй essay. Не добавляй спорные факты. Формулировки должны быть понятными и проверяемыми. " .
    "Сделай ровно {$questionCount} вопросов.";

if($paper){
    $allowedTypes = $questionMode === 'mixed' ? 'single, multiple, true_false' : $questionMode;
    $prompt .= "\nВАЖНО: это бумажный тест UROVIA PAPER. Правила PAPER имеют приоритет. Разрешены только типы: {$allowedTypes}. "
        . "У каждого вопроса ровно 1 балл. Для single и multiple: от 2 до 6 различных коротких текстовых вариантов, желательно 4. "
        . "Для single ровно один правильный, для multiple минимум два правильных и минимум один неверный. "
        . "is_correct должен быть JSON boolean. Для true_false укажи true_false_answer как строку true или false. "
        . "Не добавляй вопросы, требующие отсутствующего изображения, таблицы или текста. Формулируй компактно для печати на А4.";
}
$config = ai_config();
try {
    $response = ai_request([
        'model' => (string)$config['model'],
        'input' => $prompt,
    ]);
} catch (Throwable $error) {
    json_response(['ok' => false, 'error' => $error->getMessage(), 'code' => 'AI_REQUEST_FAILED'], 502);
}

$text = trim(ai_response_text($response));
$firstBrace = strpos($text, '{');
$lastBrace = strrpos($text, '}');
if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
    $text = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
}
$generated = json_decode($text, true);
if (!is_array($generated) || !is_array($generated['questions'] ?? null)) {
    json_response([
        'ok' => false,
        'error' => 'ИИ не смог сформировать корректное задание. Повторите генерацию.',
        'code' => 'AI_INVALID_OUTPUT',
    ], 502);
}

$normalizedQuestions = [];
foreach ((array)$generated['questions'] as $rawQuestion) {
    if (!is_array($rawQuestion)) continue;
    $interaction = trim((string)($rawQuestion['interaction_type'] ?? ''));
    if (!in_array($interaction, ['single', 'multiple', 'true_false', 'text', 'number'], true)) continue;
    if($paper && (!in_array($interaction,['single','multiple','true_false'],true)||($questionMode!=='mixed'&&$interaction!==$questionMode)))continue;

    $candidate = [
        'interaction_type' => $interaction,
        'text' => trim((string)($rawQuestion['text'] ?? '')),
        'points' => $paper ? 1 : max(1, min(10, (float)($rawQuestion['points'] ?? 1))),
    ];
    if ($candidate['text'] === '') continue;

    if (in_array($interaction, ['single', 'multiple'], true)) {
        $options = [];
        foreach ((array)($rawQuestion['options'] ?? []) as $option) {
            if (!is_array($option)) continue;
            $optionText = trim((string)($option['text'] ?? ''));
            if ($optionText === '') continue;
            if($paper && !is_bool($option['is_correct'] ?? null))continue;
            $options[] = ['text' => $optionText, 'is_correct' => !empty($option['is_correct'])];
        }
        $correctCount = count(array_filter(
            $options,
            static fn(array $option): bool => !empty($option['is_correct'])
        ));
        if (count($options) < 2) continue;
        if($paper && (count($options)>6||count(array_unique(array_map(static fn($o)=>mb_strtolower($o['text']),$options)))!==count($options)||($interaction==='multiple'&&($correctCount<2||$correctCount>=count($options)))))continue;
        if ($interaction === 'single' && $correctCount !== 1) continue;
        if ($interaction === 'multiple' && $correctCount < 1) continue;
        $candidate['options'] = $options;
    } elseif ($interaction === 'true_false') {
        if($paper&&!in_array($rawQuestion['true_false_answer']??null,['true','false'],true))continue;
        $candidate['true_false_answer'] =
            (string)($rawQuestion['true_false_answer'] ?? 'true') === 'false' ? 'false' : 'true';
    } else {
        $correctText = trim((string)($rawQuestion['correct_text'] ?? ''));
        if ($correctText === '') continue;
        if ($interaction === 'number' && !is_numeric(str_replace(',', '.', $correctText))) continue;
        $candidate['correct_text'] = $correctText;
        if ($interaction === 'text' && is_array($rawQuestion['alternatives'] ?? null)) {
            $candidate['alternatives'] = array_values(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $rawQuestion['alternatives']
            )));
        }
    }

    $normalizedQuestions[] = question_editor_normalize_payload($candidate);
    if (count($normalizedQuestions) >= $questionCount) break;
}

if (count($normalizedQuestions) < ($paper ? $questionCount : max(3, (int)ceil($questionCount / 2)))) {
    json_response([
        'ok' => false,
        'error' => 'ИИ сформировал слишком мало проверяемых вопросов. Повторите генерацию.',
        'code' => 'AI_TOO_FEW_VALID_QUESTIONS',
    ], 502);
}

$title = $customTitle !== '' ? $customTitle : trim((string)($generated['title'] ?? ''));
if ($title === '') $title = 'ИИ: ' . $topic;
$title = mb_substr($title, 0, 180);
$description = trim((string)($generated['description'] ?? ''));
if ($description === '') {
    $description = 'Черновик создан ИИ UROVIA. Проверьте вопросы и ответы перед назначением ученикам.';
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, description, type, status, workflow_status,
          max_attempts, focus_policy, variant_count, shuffle_questions, shuffle_options, shuffle_structured)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :description, :assignment_type, "draft", "draft",
          1, "allow", 1, 0, 0, 0)'
    );
    $stmt->execute([
        'teacher_id' => (int)$user['id'],
        'school_id' => $schoolId,
        'subject_id' => $subjectId,
        'title' => $title,
        'description' => $description,
        'assignment_type' => $paper ? 'paper' : 'quiz',
    ]);
    $assignmentId = (int)$pdo->lastInsertId();

    $insertQuestion = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
    );
    foreach ($normalizedQuestions as $index => $question) {
        $insertQuestion->execute([
            'assignment_id' => $assignmentId,
            'type' => $question['type'],
            'text' => $question['text'],
            'points' => $question['points'],
            'position' => $index + 1,
            'correct_text' => $question['correct_text'],
            'interaction_type' => $question['interaction_type'],
            'settings_json' => $question['settings_json'],
        ]);
        question_editor_replace_options($pdo, (int)$pdo->lastInsertId(), $question['options']);
    }

    subscription_record_ai_generation($pdo, (int)$user['id']);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

audit_event('ai_assignment_generated', 'assignment', $assignmentId, [
    'subject_id' => $subjectId,
    'topic' => $topic,
    'mode' => $paper ? 'paper' : 'online',
    'grade_level' => $gradeLevel,
    'difficulty' => $difficulty,
    'questions_count' => count($normalizedQuestions),
    'model' => (string)$config['model'],
], $schoolId, (int)$user['id']);

$usage = subscription_usage_snapshot($pdo, $user, $schoolId);
$limit = is_platform_admin($user) ? null : (int)($state['plan']['ai_monthly_limit'] ?? 0);

json_response([
    'ok' => true,
    'assignment_id' => $assignmentId,
    'questions_count' => count($normalizedQuestions),
    'usage' => [
        'used' => (int)($usage['ai_generations'] ?? 0),
        'limit' => $limit,
    ],
    'mode' => $paper ? 'paper' : 'online',
    'message' => 'Черновик создан. Проверьте вопросы и правильные ответы в конструкторе.',
], 201);
