<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/paper/choice-policy.php';
$rows=[['type'=>'single'],['type'=>'multiple'],['type'=>'true_false'],['type'=>'text'],['type'=>'single','interaction_type'=>'order'],['type'=>'text','interaction_type'=>'image_answer'],['type'=>'single','assets'=>[['id'=>1]]]];
$p=paper_choice_partition($rows);
if(count($p['questions'])!==4||$p['skipped']!==3||!isset($p['questions'][3]['assets']))throw new RuntimeException('Choice filter lost an image or included written questions');
paper_require_choice_questions($p['questions']);
try{paper_require_choice_questions($rows);throw new RuntimeException('Legacy types passed issuance');}catch(DomainException $e){if($e->getCode()!==422)throw $e;}
paper_check_new_question(['type'=>'quiz'],['interaction_type'=>'essay']);
paper_require_choice_questions(array_fill(0,20,['type'=>'single']));
try{paper_require_choice_questions(array_fill(0,21,['type'=>'single']));throw new RuntimeException('21 questions passed');}catch(DomainException $e){if($e->getCode()!==422)throw $e;}
paper_require_choice_questions(array_fill(0,36,['type'=>'single']),36);
echo "PAPER choice policy: copy filter, images, legacy protection and online isolation OK\n";
