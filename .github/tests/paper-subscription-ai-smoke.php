<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
$plans=subscription_plan_catalog();
foreach(['free','basic','pro','premium'] as $code){
 $paid=in_array($code,['pro','premium'],true);
 if(($plans[$code]['features']['paper_tests']??null)!==$paid)throw new RuntimeException('Wrong PAPER entitlement: '.$code);
}
if($plans['pro']['ai_monthly_limit']!==50||$plans['premium']['ai_monthly_limit']!==300)throw new RuntimeException('AI limits changed');
$root=dirname(__DIR__,2);
$boot=file_get_contents($root.'/api/bootstrap.php');
if(!str_contains($boot,"str_contains(\$script,'/api/paper/')"))throw new RuntimeException('Missing central PAPER gate');
$ai=file_get_contents($root.'/api/ai/generate-assignment.php');
foreach(["subscription_require_paper(","subscription_assert_ai_capacity(","subscription_record_ai_generation(","'assignment_type' => \$paper ? 'paper' : 'quiz'","\$paper ? 1 :","\$paper ? \$questionCount"] as $needle){if(!str_contains($ai,$needle))throw new RuntimeException('Missing AI integration: '.$needle);}
echo "PAPER plans, endpoint gates and shared AI quota OK\n";
