<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/questions/_helpers.php';
require_once dirname(__DIR__).'/paper/choice-policy.php';

function ai_assignment_prompt(string $subjectName,string $topic,string $gradeLevel,string $difficulty,int $questionCount,bool $paper,string $questionMode='mixed'): string {
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
return $prompt;
}

function ai_normalize_generated_assignment(array $response,int $questionCount,bool $paper,string $questionMode='mixed'): array {
$text = trim(ai_response_text($response));
$firstBrace = strpos($text, '{');
$lastBrace = strrpos($text, '}');
if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
    $text = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
}
$generated = json_decode($text, true);
if (!is_array($generated) || !is_array($generated['questions'] ?? null)) {
    throw new UnexpectedValueException('ИИ не смог сформировать корректное задание. Повторите генерацию.');
}

$normalizedQuestions = [];
$seen=[];
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
            if(!is_bool($option['is_correct'] ?? null)){ $options=[]; break; }
            $options[] = ['text' => $optionText, 'is_correct' => !empty($option['is_correct'])];
        }
        $correctCount = count(array_filter(
            $options,
            static fn(array $option): bool => !empty($option['is_correct'])
        ));
        if (count($options) < 2 || count($options)>6 || count(array_unique(array_map(static fn($o)=>mb_strtolower($o['text']),$options)))!==count($options)) continue;
        if($paper && (count($options)>6||count(array_unique(array_map(static fn($o)=>mb_strtolower($o['text']),$options)))!==count($options)||($interaction==='multiple'&&($correctCount<2||$correctCount>=count($options)))))continue;
        if ($interaction === 'single' && $correctCount !== 1) continue;
        if ($interaction === 'multiple' && ($correctCount < 1 || $correctCount >= count($options))) continue;
        $candidate['options'] = $options;
    } elseif ($interaction === 'true_false') {
        if(!in_array($rawQuestion['true_false_answer']??null,['true','false',true,false],true))continue;
        $candidate['true_false_answer'] =
            in_array($rawQuestion['true_false_answer'],['false',false],true) ? 'false' : 'true';
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

    $dedupe=mb_strtolower(preg_replace('/\s+/u',' ',$candidate['text'])??$candidate['text']);
    if(isset($seen[$dedupe]))continue;
    $seen[$dedupe]=true;
    $normalized = question_editor_normalize_payload($candidate);
    if($paper){require_once dirname(__DIR__).'/paper/choice-policy.php';paper_check_new_question(['type'=>'paper'],$normalized);}
    $normalizedQuestions[] = $normalized;
    if (count($normalizedQuestions) >= $questionCount) break;
}

if (count($normalizedQuestions) < $questionCount) {
    throw new UnexpectedValueException('ИИ сформировал слишком мало проверяемых вопросов. Повторите генерацию.');
}

return ['generated'=>$generated,'questions'=>$normalizedQuestions];
}
