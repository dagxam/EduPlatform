<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
require dirname(__DIR__,2).'/api/attempts/_helpers.php';
require dirname(__DIR__,2).'/api/paper/grading.php';
require dirname(__DIR__,2).'/api/paper/validation.php';
function pv(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function pvreject(array $q,string $msg):void{try{paper_validate_question($q,1);throw new RuntimeException('Expected rejection: '.$msg);}catch(DomainException $e){if(!str_contains($e->getMessage(),$msg))throw $e;}}
$single=['type'=>'single','text'=>'Выберите','points'=>1,'options'=>[['id'=>10,'text'=>'A'],['id'=>20,'text'=>'B']], 'correct'=>[20]];
$multi=['type'=>'multiple','text'=>'Выберите','points'=>1,'options'=>[['id'=>10,'text'=>'A'],['id'=>20,'text'=>'B'],['id'=>30,'text'=>'C']], 'correct'=>[10,30]];
$tf=['type'=>'true_false','text'=>'Верно?','points'=>1,'options'=>[['id'=>10,'text'=>'Верно'],['id'=>20,'text'=>'Неверно']], 'correct'=>[10]];
$order=['type'=>'order','text'=>'Порядок','points'=>2,'correct_text'=>'["K2","K1"]','structured'=>['items'=>[['key'=>'K1','text'=>'Первый'],['key'=>'K2','text'=>'Второй']]]];
$matching=['type'=>'matching','text'=>'Соответствия','points'=>2,'correct_text'=>'{"L1":"R2","L2":"R1"}','structured'=>['left'=>[['key'=>'L1','text'=>'1'],['key'=>'L2','text'=>'2']], 'right'=>[['key'=>'R1','text'=>'A'],['key'=>'R2','text'=>'B']]]];
$text=['type'=>'text','text'=>'Краткий','points'=>1,'correct_text'=>'Да | Верно'];
$number=['type'=>'number','text'=>'Число','points'=>1,'correct_text'=>'2.5'];
$correction=['type'=>'correction','text'=>'Исправьте','points'=>1,'correct_text'=>'правильно','structured'=>['original_text'=>'текст с ошибкой']];
$essay=['type'=>'essay','text'=>'Развернуто','points'=>3,'correct_text'=>''];
$manual=['type'=>'text','text'=>'Ответ','points'=>1,'correct_text'=>''];
$manualOrder=array_merge($order,['correct_text'=>'']);$manualMatching=array_merge($matching,['correct_text'=>'']);
paper_validate_questions([$single,$multi,$tf,$order,$matching,$text,$number,$correction,$essay,$manual,$manualOrder,$manualMatching]);
pv(paper_grade_answer($order,['text'=>'B A','score'=>null])['score']===2.0,'order grading');
pv(paper_grade_answer($matching,['text'=>'1-B, 2-A','score'=>null])['score']===2.0,'matching grading');
pv(paper_grade_answer($correction,['text'=>'Правильно','score'=>null])['score']===1.0,'correction grading');
pv(paper_grade_answer($number,['text'=>'2,5','score'=>null])['score']===1.0,'numeric grading');
pv(paper_grade_answer($text,['text'=>'Верно','score'=>null])['score']===1.0,'short answer grading');
pv(paper_grade_answer($manualOrder,['text'=>'A B','score'=>1])['score']===1.0,'manual order scoring');
pv(paper_grade_answer($manualMatching,['text'=>'1-A, 2-B','score'=>1])['score']===1.0,'manual matching scoring');
pvreject(array_merge($order,['correct_text'=>'["K1"]']),'ключ последовательности');
pvreject(array_merge($matching,['correct_text'=>'{"L1":"R2"}']),'ключ соответствий');
pvreject(array_merge($number,['correct_text'=>'2x']),'числовой ключ');
pvreject(array_merge($correction,['structured'=>['original_text'=>'']]),'текст с ошибкой');
pvreject(array_merge($single,['correct'=>[]]),'ключ правильного ответа');
pvreject(array_merge($order,['structured'=>['items'=>array_fill(0,27,['key'=>'K','text'=>'x'])]]),'26 элементов');
echo "PAPER validation: all question types and keys OK\n";
