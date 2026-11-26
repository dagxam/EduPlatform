<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_generation.php';

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

$prompt=ai_assignment_prompt($subjectName,$topic,$gradeLevel,$difficulty,$questionCount,$paper,$questionMode);
$config = ai_config();
try {
    $response=ai_request(['model'=>(string)$config['model'],'input'=>$prompt]);
    $parsed=ai_normalize_generated_assignment($response,$questionCount,$paper,$questionMode);
    $generated=$parsed['generated'];$normalizedQuestions=$parsed['questions'];
} catch (UnexpectedValueException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage(),'code'=>'AI_INVALID_OUTPUT'],502);
} catch (Throwable $error) {
    if(!($error instanceof AiProviderException))error_log('[UROVIA AI] generation: '.get_class($error));
    json_response(['ok'=>false,'error'=>$error instanceof AiProviderException?$error->getMessage():'Не удалось обработать ответ ИИ. Обратитесь к администратору.','code'=>'AI_REQUEST_FAILED'],502);
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
    'provider' => (string)$config['provider'],
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
