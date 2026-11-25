<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/paper/grading.php';
function check_preview(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$q=['type'=>'single','points'=>2,'options'=>[['id'=>83],['id'=>12],['id'=>29]],'correct'=>[12]];
foreach([[[1],'correct',2.0],[[0],'incorrect',0.0],[[],'blank',0.0]] as [$a,$status,$score]){
 $g=paper_grade_answer($q,$a);$p=paper_preview_detail($q,$a,$g);
 check_preview($p['status']===$status && $p['score']===$score && $p['expected']===[1],'Variant key preview mismatch');
}
$q['type']='multiple';$q['correct']=[83,29];$a=[2,0];$p=paper_preview_detail($q,$a,paper_grade_answer($q,$a));
check_preview($p['status']==='correct'&&$p['expected']===[0,2],'Multiple choice key mismatch');
$q=['type'=>'essay','points'=>4,'correct_text'=>''];$a=['text'=>'Ответ','score'=>2];
$p=paper_preview_detail($q,$a,paper_grade_answer($q,$a));check_preview($p['status']==='partial'&&$p['score']===2.0,'Partial credit');
$work=file_get_contents(dirname(__DIR__,2).'/api/paper/work.php');
$preview=strpos($work,"if(\$action==='preview'){\$pdo->rollBack();");$insert=strpos($work,'INSERT INTO attempts');
check_preview($preview!==false&&$insert!==false&&$preview<$insert,'Preview must return before writing attempts');
echo "PAPER preview: correct, wrong, blank, multiple and partial grading OK\n";
