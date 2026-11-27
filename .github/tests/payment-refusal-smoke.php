<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,recurring_refused_at TEXT);CREATE TABLE user_subscriptions(user_id INTEGER PRIMARY KEY,auto_renew INTEGER,provider_payment_method_id TEXT);INSERT INTO users VALUES(1,NULL);');
function must(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$order=['user_id'=>1,'created_at'=>'2026-11-27 10:00:00'];
$payment=['payment_method'=>['saved'=>true,'id'=>'method-test'],'metadata'=>['auto_renew_consent'=>'1']];
must(subscription_saved_method_after_payment($pdo,$order,$payment)==='method-test','explicit consent rejected');
$oneTime=$payment;$oneTime['metadata']['auto_renew_consent']='0';
must(subscription_saved_method_after_payment($pdo,$order,$oneTime)===null,'one-time payment enabled renewal');
$legacy=$payment;$legacy['metadata']=[];
must(subscription_saved_method_after_payment($pdo,$order,$legacy)===null,'legacy payment enabled renewal without evidence');
$pdo->exec("UPDATE users SET recurring_refused_at='2026-11-27 10:01:00' WHERE id=1");
must(subscription_saved_method_after_payment($pdo,$order,$payment)===null,'late checkout undid refusal');
$order['created_at']='2026-11-27 10:02:00';
must(subscription_saved_method_after_payment($pdo,$order,$payment)==='method-test','new consent rejected');
$renewal=$payment;$renewal['metadata']=['renewal'=>'1'];
must(subscription_saved_method_after_payment($pdo,$order,$renewal)===null,'renewal with no saved preference');
$pdo->exec("INSERT INTO user_subscriptions VALUES(1,1,'method-test')");
must(subscription_saved_method_after_payment($pdo,$order,$renewal)==='method-test','authorized renewal rejected');
$pdo->exec("UPDATE user_subscriptions SET auto_renew=0,provider_payment_method_id=NULL");
must(subscription_saved_method_after_payment($pdo,$order,$renewal)===null,'disabled renewal restored');
echo "Payment consent and late webhook refusal checks OK\n";
