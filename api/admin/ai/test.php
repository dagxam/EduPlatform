<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
require dirname(__DIR__,2).'/ai/_generation.php';
$user=require_user(['admin']);
if(!is_platform_admin($user))json_response(['ok'=>false,'error'=>'Доступ только главному администратору.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$data=read_json_body();
$mode=$data['mode']??'';
if(!in_array($mode,['online','paper'],true))json_response(['ok'=>false,'error'=>'Укажите режим проверки.'],422);
if(!ai_is_configured())json_response(['ok'=>false,'error'=>'Ключ Groq не настроен.'],503);
$recent=array_values(array_filter((array)($_SESSION['ai_diagnostic_times']??[]),static fn($t)=>is_int($t)&&$t>time()-300));
if(count($recent)>=6)json_response(['ok'=>false,'error'=>'Повторите проверку через несколько минут.'],429);
$recent[]=time();$_SESSION['ai_diagnostic_times']=$recent;
session_write_close();
$paper=$mode==='paper';$start=microtime(true);
try{
 $config=ai_config();
 $prompt=ai_assignment_prompt('Математика','Сложение и вычитание целых чисел','5 класс','easy',3,$paper,'mixed');
 $parsed=ai_normalize_generated_assignment(ai_request(['model'=>(string)$config['model'],'input'=>$prompt]),3,$paper,'mixed');
 json_response(['ok'=>true,'mode'=>$mode,'questions_count'=>count($parsed['questions']),'duration_ms'=>(int)round((microtime(true)-$start)*1000),'model'=>$config['model']]);
}catch(Throwable $e){
 $diagnostic=$e instanceof AiProviderException?$e->diagnostic:['provider_code'=>$e instanceof UnexpectedValueException?'AI_INVALID_OUTPUT':'INTERNAL_ERROR'];
 $message=$e instanceof AiProviderException||$e instanceof UnexpectedValueException?$e->getMessage():'Внутренняя ошибка обработки ИИ. Проверьте журнал PHP.';
 if(!($e instanceof AiProviderException)&&!($e instanceof UnexpectedValueException))error_log('[UROVIA AI] diagnostic: '.get_class($e));
 json_response(['ok'=>false,'mode'=>$mode,'error'=>$message,'diagnostic'=>$diagnostic,'model'=>(string)ai_config()['model'],'duration_ms'=>(int)round((microtime(true)-$start)*1000)],502);
}
