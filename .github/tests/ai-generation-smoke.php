<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
require dirname(__DIR__,2).'/api/ai/_generation.php';
function must(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function answer(array $questions):array{return ['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode(['title'=>'Проверка','questions'=>$questions],JSON_UNESCAPED_UNICODE)]]]]];}
function single(int $i):array{return ['interaction_type'=>'single','text'=>'Сколько будет '.$i.' + 1?','points'=>4,'options'=>[['text'=>(string)($i+1),'is_correct'=>true],['text'=>(string)$i,'is_correct'=>false]]];}
$multiple=['interaction_type'=>'multiple','text'=>'Чётные числа','points'=>7,'options'=>[['text'=>'2','is_correct'=>true],['text'=>'4','is_correct'=>true],['text'=>'3','is_correct'=>false]]];
$tf=['interaction_type'=>'true_false','text'=>'2 + 2 = 5','true_false_answer'=>false,'points'=>3];
$num=['interaction_type'=>'number','text'=>'3 + 4','correct_text'=>'7','points'=>2];
$txt=['interaction_type'=>'text','text'=>'Столица России','correct_text'=>'Москва','alternatives'=>['москва']];
$online=ai_normalize_generated_assignment(answer([single(1),$multiple,$tf,$num,$txt]),5,false)['questions'];
must(count($online)===5,'online count');must((float)$online[0]['points']===4.0,'online points');must($online[2]['options'][1]['is_correct']===1,'false boolean');must($online[3]['correct_text']==='7','number');
foreach([1,10,20] as $n){$questions=[];for($i=1;$i<=$n;$i++)$questions[]=single($i);$out=ai_normalize_generated_assignment(answer($questions),$n,true,'single')['questions'];must(count($out)===$n,'paper count');foreach($out as $q){must((float)$q['points']===1.0,'paper points');must(count($q['options'])===2,'paper options');}}
$paper=ai_normalize_generated_assignment(answer([single(1),$multiple,$tf]),3,true)['questions'];must($paper[1]['options'][1]['is_correct']===1,'multiple key');
$bad=single(1);$bad['options'][1]['is_correct']='false';
foreach([[answer([$bad]),1,false],[answer([$num]),1,true],[answer([single(1),single(1)]),2,true],[answer([single(1)]),3,false],[['output_text'=>'bad'],1,true]] as [$payload,$n,$isPaper]){try{ai_normalize_generated_assignment($payload,$n,$isPaper);throw new RuntimeException('accepted invalid output');}catch(UnexpectedValueException $e){}}
must(str_contains(ai_assignment_prompt('Математика','Сложение','5','easy',20,true),'ровно 20'),'prompt count');
foreach([false,true] as $isPaper){
 $payload=ai_chat_payload(['model'=>'openai/gpt-oss-20b','input'=>ai_assignment_prompt('Математика','Сложение','5','easy',3,$isPaper)]);
 must($payload['response_format']['type']==='json_object','JSON mode');
 must($payload['messages'][1]['role']==='user','chat input');
 $mock=answer([single(1),single(2),single(3)]);
 $chat=['choices'=>[['message'=>['content'=>$mock['output'][0]['content'][0]['text']],'finish_reason'=>'stop']]];
 must(count(ai_normalize_generated_assignment($chat,3,$isPaper)['questions'])===3,'chat output');
}
foreach([401,403,404,429,503] as $code){
 $e=ai_provider_failure($code,0,'example gsk_fakekey','test','gsk_fakekey');
 must($e->diagnostic['http_status']===$code,'HTTP status');
 must(!str_contains(json_encode($e->diagnostic),'gsk_fakekey'),'key redaction');
}
foreach([6,7,28,60] as $errno)must(ai_provider_failure(0,$errno)->diagnostic['curl_errno']===$errno,'cURL error');
$stored=['provider'=>'groq','api_key'=>'gsk_test','model'=>'openai/gpt-oss-20b','openrouter_api_key'=>'sk-or-v1-test','active_provider'=>'openrouter'];
must(ai_config_for_provider('groq',$stored)['api_key']==='gsk_test','preserve Groq key');
must(ai_config_for_provider('openrouter',$stored)['api_key']==='sk-or-v1-test','separate OpenRouter key');
must(ai_config_for_provider('openrouter',$stored)['model']==='openrouter/free','free-only model');
$or=ai_chat_payload(['model'=>'openrouter/free','input'=>'JSON test']);
must($or['model']==='openrouter/free'&&$or['provider']['require_parameters']===true,'OpenRouter routing');
must(!isset($or['reasoning_effort'])&&isset($or['max_tokens']),'OpenRouter compatible parameters');
$e=ai_provider_failure(403,0,'Forbidden sk-or-v1-test','403','sk-or-v1-test','openrouter');
must(str_contains($e->getMessage(),'OpenRouter')&&!str_contains(json_encode($e->diagnostic),'sk-or-v1-test'),'OpenRouter diagnostics redaction');
echo "AI online and PAPER: provider selection, free-only routing and response validation OK (mock provider)\n";
