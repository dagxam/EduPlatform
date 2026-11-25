<?php
declare(strict_types=1);

/** Validate the PAPER answer key against the exact printed response layout. */
function paper_validate_question(array $q, int $number): void
{
    $fail = static function(string $message) use ($number): never {
        throw new DomainException('Вопрос № '.$number.': '.$message, 422);
    };
    $type = (string)($q['type'] ?? '');
    $text = trim((string)($q['text'] ?? ''));
    $points = (float)($q['points'] ?? 0);
    if ($text === '') $fail('текст вопроса пуст.');
    if (!is_finite($points) || $points <= 0 || $points > 1000) $fail('некорректное количество баллов.');
    $structured = is_array($q['structured'] ?? null) ? $q['structured'] : [];
    $correctText = trim((string)($q['correct_text'] ?? ''));

    if (in_array($type, ['single','multiple','true_false'], true)) {
        $options = is_array($q['options'] ?? null) ? $q['options'] : [];
        if (count($options) < 2 || count($options) > 6) $fail('для бланка нужно от 2 до 6 вариантов ответа.');
        $ids = [];
        foreach ($options as $o) {
            $id = (int)($o['id'] ?? 0);
            if ($id < 1 || trim((string)($o['text'] ?? '')) === '' || in_array($id,$ids,true)) $fail('варианты ответа содержат пустые или повторяющиеся данные.');
            $ids[] = $id;
        }
        $correct = array_values(array_unique(array_map('intval',(array)($q['correct'] ?? []))));
        if (!$correct || !valid_choice_answer_key($type,$correct)) $fail('ключ правильного ответа некорректен.');
        foreach ($correct as $id) if (!in_array($id,$ids,true)) $fail('ключ ссылается на отсутствующий вариант.');
        return;
    }
    if ($type === 'order') {
        $items = array_values(is_array($structured['items'] ?? null) ? $structured['items'] : []);
        if (count($items) < 2 || count($items) > 26) $fail('порядок должен содержать от 2 до 26 элементов (A–Z).');
        $keys = [];
        foreach ($items as $item) {
            $key = trim((string)($item['key'] ?? ''));
            if ($key === '' || trim((string)($item['text'] ?? '')) === '' || in_array($key,$keys,true)) $fail('элементы последовательности некорректны.');
            $keys[] = $key;
        }
        if ($correctText === '') return; // manual scoring is allowed when no key is supplied
        $expected = json_decode($correctText,true);
        if (!is_array($expected) || !array_is_list($expected) || count($expected)!==count($keys)) $fail('ключ последовательности отсутствует или некорректен.');
        $expected = array_map('strval',$expected);
        if (count(array_unique($expected))!==count($expected) || array_diff($expected,$keys) || array_diff($keys,$expected)) $fail('ключ последовательности не соответствует элементам.');
        return;
    }
    if ($type === 'matching') {
        $left = array_values(is_array($structured['left'] ?? null) ? $structured['left'] : []);
        $right = array_values(is_array($structured['right'] ?? null) ? $structured['right'] : []);
        if (count($left)<2 || count($left)>26 || count($left)!==count($right)) $fail('соответствие должно содержать от 2 до 26 равных пар.');
        $leftKeys=[];$rightKeys=[];
        foreach($left as $item){$key=trim((string)($item['key']??''));if($key===''||trim((string)($item['text']??''))===''||in_array($key,$leftKeys,true))$fail('левая часть соответствий некорректна.');$leftKeys[]=$key;}
        foreach($right as $item){$key=trim((string)($item['key']??''));if($key===''||trim((string)($item['text']??''))===''||in_array($key,$rightKeys,true))$fail('правая часть соответствий некорректна.');$rightKeys[]=$key;}
        if($correctText === '') return; // manual scoring is allowed when no key is supplied
        $expected=json_decode($correctText,true);
        if(!is_array($expected)||count($expected)!==count($leftKeys))$fail('ключ соответствий отсутствует или некорректен.');
        $keys=array_map('strval',array_keys($expected));$values=array_map('strval',array_values($expected));
        if(count(array_unique($keys))!==count($keys)||count(array_unique($values))!==count($values)||array_diff($keys,$leftKeys)||array_diff($leftKeys,$keys)||array_diff($values,$rightKeys)||array_diff($rightKeys,$values))$fail('ключ соответствий не совпадает с печатными элементами.');
        return;
    }
    if ($type === 'number') {
        if ($correctText !== '' && !is_numeric(str_replace(',','.',$correctText))) $fail('числовой ключ некорректен.');
        return; // empty key means the teacher grades manually
    }
    if ($type === 'correction') {
        if (trim((string)($structured['original_text'] ?? '')) === '') $fail('текст с ошибкой не задан.');
        return; // a missing key is explicitly manual
    }
    if (in_array($type,['text','essay'],true)) return; // empty key means manual grading
    $fail('тип ответа не поддерживается бумажным бланком.');
}
function paper_validate_questions(array $questions): void
{
    if (!$questions || count($questions)>36) throw new DomainException('Персональный бланк поддерживает от 1 до 36 вопросов.',422);
    foreach($questions as $i=>$q){if(!is_array($q))throw new DomainException('Некорректный вопрос № '.($i+1).'.',422);paper_validate_question($q,$i+1);}
}
