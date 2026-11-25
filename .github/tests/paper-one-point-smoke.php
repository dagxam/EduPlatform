<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/paper/grading.php';
$q=['type'=>'multiple','points'=>1.0,'options'=>[['id'=>3],['id'=>1],['id'=>2]],'correct'=>[3,2]];
foreach([[[0,2],1.0],[[0],0.0],[[0,1,2],0.0],[[],0.0]] as [$a,$score])if(paper_grade_answer($q,$a)['score']!==$score)throw new RuntimeException('One point choice mismatch');
$root=dirname(__DIR__,2);foreach(['create','update'] as $p){$s=file_get_contents($root.'/api/questions/'.$p.'.php');if(!str_contains($s,"if((\$assignment['type']??'')==='paper')\$data['points']=1;"))throw new RuntimeException('Paper-only guard missing');}
echo "PAPER one-point scoring OK\n";
