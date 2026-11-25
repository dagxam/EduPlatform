<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
require dirname(__DIR__,2).'/api/attempts/_helpers.php';
require dirname(__DIR__,2).'/api/paper/grading.php';
function paper_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$choice=['type'=>'single','points'=>2,'options'=>[['id'=>42],['id'=>11],['id'=>29]],'correct'=>[11]];
paper_expect(paper_grade_answer($choice,[1])['score']===2.0,'Printed option index maps to original option id');
paper_expect(paper_grade_answer($choice,[])['score']===0.0,'Blank choice');
try{paper_grade_answer($choice,[0,1]);throw new RuntimeException('Multiple selection accepted for single');}catch(DomainException){}
$multiple=$choice;$multiple['type']='multiple';$multiple['correct']=[29,42];
paper_expect(paper_grade_answer($multiple,[2,0])['score']===2.0,'Multiple selection');
paper_expect(paper_grade_answer($multiple,[2])['score']===0.0,'Incomplete selection');
$number=['type'=>'number','points'=>3,'correct_text'=>'2.5'];
paper_expect(paper_grade_answer($number,['text'=>'2,5','score'=>null])['score']===3.0,'Comma decimal');
$text=['type'=>'text','points'=>2,'correct_text'=>'Всё верно | Да'];
paper_expect(paper_grade_answer($text,['text'=>'Все верно!','score'=>null])['score']===2.0,'Normalized written text');
$essay=['type'=>'essay','points'=>5,'correct_text'=>''];
paper_expect(paper_grade_answer($essay,['text'=>'Рукописный ответ','score'=>2.5])['score']===2.5,'Teacher-awarded partial credit');
try{paper_grade_answer($essay,['text'=>'Ответ','score'=>6]);throw new RuntimeException('Out-of-range score accepted');}catch(DomainException){}
try{paper_grade_answer($essay,['text'=>'Ответ','score'=>null]);throw new RuntimeException('Ungraded essay accepted');}catch(DomainException){}
$order=['type'=>'order','points'=>4,'correct_text'=>'["y","x"]','structured'=>['items'=>[['key'=>'x'],['key'=>'y']]]];
paper_expect(paper_grade_answer($order,['text'=>'B A','score'=>null])['score']===4.0,'Written order uses printed order');
$matching=['type'=>'matching','points'=>4,'correct_text'=>'{"l1":"r2","l2":"r1"}','structured'=>['left'=>[['key'=>'l1'],['key'=>'l2']],'right'=>[['key'=>'r1'],['key'=>'r2']]]];
paper_expect(paper_grade_answer($matching,['text'=>'1-B, 2-A','score'=>null])['score']===4.0,'Written matching uses printed positions');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE attempts (id INTEGER PRIMARY KEY, termination_reason TEXT)');
$pdo->exec("INSERT INTO attempts VALUES(1,'paper')");
regrade_attempt_answers($pdo,1); // no answers table: this must return before attempting a regrade
$source=file_get_contents(dirname(__DIR__,2).'/api/paper/work.php');
paper_expect(str_contains($source,"'submitted','paper'"),'Paper source marker');
paper_expect(str_contains($source,'FOR UPDATE'),'Student lock');
paper_expect(str_contains($source,'hash_equals'),'Printed snapshot/code integrity');
echo "PAPER mixed grading and isolation OK\n";
