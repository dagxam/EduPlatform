<?php
declare(strict_types=1);
// Only new PAPER content is restricted. Historical snapshots stay readable.
function paper_choice_type(array $q): string {
    return (string)(($q['interaction_type']??'') ?: ($q['type']??''));
}
function paper_is_choice(array $q): bool {
    return in_array(paper_choice_type($q),['single','multiple','true_false'],true);
}
function paper_choice_partition(array $questions): array {
    $kept=[];$skipped=0;
    foreach($questions as $q){if(paper_is_choice($q))$kept[]=$q;else $skipped++;}
    return ['questions'=>$kept,'skipped'=>$skipped];
}
function paper_require_choice_questions(array $questions): void {
    foreach($questions as $i=>$q)if(!paper_is_choice($q))throw new DomainException('Вопрос № '.($i+1).': PAPER теперь поддерживает только выбор ответа. Измените тип или удалите этот вопрос в конструкторе. Старые бланки проверяются без изменений.',422);
}
function paper_check_new_question(array $assignment,array $normalized): void {
    if(($assignment['type']??'')!=='paper')return;
    if(!paper_is_choice($normalized))json_response(['ok'=>false,'error'=>'В PAPER доступны только вопросы с выбором ответа.'],422);
    $options=$normalized['options']??[];
    if(count($options)<2||count($options)>6)json_response(['ok'=>false,'error'=>'Для PAPER нужно от 2 до 6 вариантов ответа.'],422);
    if(paper_choice_type($normalized)==='multiple'&&count(array_filter($options,static fn($o)=>(int)$o['is_correct']===1))<2)json_response(['ok'=>false,'error'=>'Отметьте минимум два правильных варианта или выберите тип с одним ответом.'],422);
}
