<?php
declare(strict_types=1);

// Paper-only scoring: never call online attempt mutation endpoints.
function paper_grade_answer(array $question,array $answer): array
{
    $type=(string)$question['type'];
    $max=(float)$question['points'];
    if(!is_finite($max)||$max<0) throw new DomainException('Некорректные баллы вопроса.',422);
    if(in_array($type,['single','multiple','true_false'],true)) {
        if(!array_is_list($answer)) throw new DomainException('Неверный формат вариантов.',422);
        $selected=[];
        foreach($answer as $index) {
            if(!is_int($index)||!isset($question['options'][$index])) throw new DomainException('Некорректный вариант ответа.',422);
            $selected[]=(int)$question['options'][$index]['id'];
        }
        $selected=array_values(array_unique($selected));sort($selected,SORT_NUMERIC);
        if($type!=='multiple'&&count($selected)>1) throw new DomainException('В вопросе с одним ответом оставьте только один вариант.',422);
        $key=array_map('intval',$question['correct']);sort($key,SORT_NUMERIC);
        $correct=$selected!==[]&&$selected===$key;
        return ['answer_text'=>json_encode($selected,JSON_THROW_ON_ERROR),'score'=>$correct?$max:0.0,'is_correct'=>(int)$correct];
    }
    $raw=$answer['text']??'';
    if(!is_string($raw)||strlen($raw)>32000) throw new DomainException('Некорректный письменный ответ.',422);
    $raw=trim($raw);$expected=trim((string)($question['correct_text']??''));$correct=false;$stored=$raw;
    if($type==='order') {
        $tokens=preg_split('/[\s,;]+/u',strtoupper($raw),-1,PREG_SPLIT_NO_EMPTY)?:[];
        if(count($tokens)===1&&preg_match('/^[A-Z]+$/D',$tokens[0])) $tokens=str_split($tokens[0]);
        $keys=[];
        foreach($tokens as $token){
            $i=strlen($token)===1?ord($token)-65:-1;
            if($i<0||!isset($question['structured']['items'][$i])) throw new DomainException('Введите буквы порядка через пробел.',422);
            $keys[]=(string)$question['structured']['items'][$i]['key'];
        }
        $key=json_decode($expected,true);$key=is_array($key)?array_values(array_map('strval',$key)):[];
        $correct=$keys!==[]&&$keys===$key;$stored=json_encode($keys,JSON_THROW_ON_ERROR);
    } elseif($type==='matching') {
        $pairs=preg_split('/[\s,;]+/u',strtoupper($raw),-1,PREG_SPLIT_NO_EMPTY)?:[];$keys=[];
        foreach($pairs as $pair){
            if(!preg_match('/^(\d+)[-:=]([A-Z])$/D',$pair,$m)) throw new DomainException('Введите соответствия в формате 1-A, 2-B.',422);
            $left=(int)$m[1]-1;$right=ord($m[2])-65;
            if(!isset($question['structured']['left'][$left],$question['structured']['right'][$right])) throw new DomainException('Неверная пара соответствия.',422);
            $lk=(string)$question['structured']['left'][$left]['key'];
            if(array_key_exists($lk,$keys)) throw new DomainException('Повторяющийся номер соответствия.',422);
            $keys[$lk]=(string)$question['structured']['right'][$right]['key'];
        }
        $key=json_decode($expected,true);$key=is_array($key)?array_map('strval',$key):[];ksort($keys,SORT_NATURAL);ksort($key,SORT_NATURAL);
        $correct=$keys!==[]&&$keys===$key;$stored=json_encode((object)$keys,JSON_THROW_ON_ERROR);
    } elseif($type==='number') {
        $num=str_replace(',','.',$raw);$key=str_replace(',','.',$expected);
        $correct=$raw!==''&&is_numeric($num)&&is_numeric($key)&&abs((float)$num-(float)$key)<0.0000001;
    } elseif(in_array($type,['text','correction'],true)&&$expected!=='') {
        $alternatives=array_map('normalize_answer_text',preg_split('/\s*\|\s*/u',$expected)?:[]);
        $correct=$raw!==''&&in_array(normalize_answer_text($raw),$alternatives,true);
    }
    $manual=$answer['score']??null;
    $requiresManual=!in_array($type,['order','matching','number','text','correction'],true)||$expected==='';
    if($manual===null && $requiresManual) throw new DomainException('Укажите баллы за письменный ответ (в том числе 0 за пропуск).',422);
    if($manual!==null) {
        if((!is_int($manual)&&!is_float($manual))||!is_finite((float)$manual)||(float)$manual<0||(float)$manual>$max) throw new DomainException('Баллы за письменный ответ вне допустимого диапазона.',422);
        if($raw===''&&(float)$manual>0) throw new DomainException('Для ненулевых баллов внесите письменный ответ.',422);
        $score=round((float)$manual,2);$correct=$raw!==''&&abs($score-$max)<0.000001;
    } else $score=$correct?$max:0.0;
    return ['answer_text'=>$stored,'score'=>$score,'is_correct'=>(int)$correct];
}
