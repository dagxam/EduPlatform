<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$config = yookassa_config();
$allowed = PHP_SAPI === 'cli';
if (!$allowed) {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $expected = (string)($config['cron_secret'] ?? '');
    if ($expected !== '' && str_starts_with($auth, 'Bearer ')) {
        $allowed = hash_equals($expected, trim(substr($auth, 7)));
    }
}
if (!$allowed) {
    json_response(['ok'=>false,'error'=>'Недостаточно прав.'],403);
}
if (!yookassa_is_configured()) {
    json_response(['ok'=>false,'error'=>'ЮKassa не настроена.'],503);
}
if (empty($config['recurring_enabled'])) {
    json_response([
        'ok'=>true,
        'processed'=>0,
        'results'=>[],
        'message'=>'Автоплатежи отключены до отдельного разрешения ЮKassa.',
    ]);
}

$pdo=app_db();
$now=time();
$threshold=gmdate('Y-m-d H:i:s',$now+24*3600);

$stmt=$pdo->prepare(
    'SELECT s.user_id, s.plan_code, s.billing_period, s.ends_at, s.provider_payment_method_id,
            u.email, u.is_active
     FROM user_subscriptions s
     JOIN users u ON u.id=s.user_id
     WHERE s.status="active"
       AND s.auto_renew=1
       AND s.payment_provider="yookassa"
       AND s.provider_payment_method_id IS NOT NULL
       AND s.provider_payment_method_id <> ""
       AND s.ends_at IS NOT NULL
       AND s.ends_at <= :threshold
       AND u.is_active=1'
);
$stmt->execute(['threshold'=>$threshold]);
$subscriptions=$stmt->fetchAll();
$plans=subscription_plan_catalog();
$results=[];

foreach($subscriptions as $sub){
    $userId=(int)$sub['user_id'];
    try{
    subscription_payment_lock($pdo,$userId,function()use($pdo,$userId,$plans,$threshold,&$results){
    // Re-read after locking, including a refusal received since the initial list query.
    $fresh=$pdo->prepare('SELECT s.*,u.email,u.is_active FROM user_subscriptions s JOIN users u ON u.id=s.user_id WHERE s.user_id=? AND s.status="active" AND s.auto_renew=1 AND s.payment_provider="yookassa" AND s.provider_payment_method_id IS NOT NULL AND s.provider_payment_method_id<>"" AND s.ends_at<=? AND u.is_active=1');
    $fresh->execute([$userId,$threshold]);$sub=$fresh->fetch();
    if(!$sub){$results[]=['user_id'=>$userId,'status'=>'skipped_disabled'];return;}
    $planCode=(string)$sub['plan_code'];
    $period=(string)$sub['billing_period'];
    if(!isset($plans[$planCode]) || !in_array($period,['month','year'],true)) return;

    $pending=$pdo->prepare(
        'SELECT id FROM payment_orders
         WHERE user_id=:user_id
           AND provider="yookassa"
           AND status IN ("created","pending")
         ORDER BY id DESC LIMIT 1'
    );
    $pending->execute(['user_id'=>$userId]);
    if($pending->fetchColumn()){
        $results[]=['user_id'=>$userId,'status'=>'skipped_pending'];
        return;
    }

    $amountMinor=$period==='year'
        ? (int)$plans[$planCode]['yearly_price_minor']
        : (int)$plans[$planCode]['monthly_price_minor'];
    if($amountMinor<1) return;

    $idempotenceKey=bin2hex(random_bytes(24));
    $insert=$pdo->prepare(
        'INSERT INTO payment_orders
         (user_id,plan_code,billing_period,amount_minor,currency,provider,customer_email,status,idempotence_key,created_at)
         VALUES (:user_id,:plan_code,:billing_period,:amount_minor,"RUB","yookassa",:customer_email,"created",:idempotence_key,CURRENT_TIMESTAMP)'
    );
    $insert->execute([
        'user_id'=>$userId,
        'plan_code'=>$planCode,
        'billing_period'=>$period,
        'amount_minor'=>$amountMinor,
        'customer_email'=>(string)($sub['email']??''),
        'idempotence_key'=>$idempotenceKey,
    ]);
    $orderId=(int)$pdo->lastInsertId();

    $payload=[
        'amount'=>[
            'value'=>number_format($amountMinor/100,2,'.',''),
            'currency'=>'RUB',
        ],
        'capture'=>true,
        'payment_method_id'=>(string)$sub['provider_payment_method_id'],
        'description'=>'UROVIA '.(string)$plans[$planCode]['name'].' — автопродление',
        'metadata'=>[
            'order_id'=>(string)$orderId,
            'user_id'=>(string)$userId,
            'plan_code'=>$planCode,
            'billing_period'=>$period,
            'renewal'=>'1',
        ],
    ];

    try{
        $payment=yookassa_request('POST','payments',$payload,$idempotenceKey);
        $paymentId=(string)($payment['id']??'');
        $status=(string)($payment['status']??'pending');
        $localStatus=$status==='succeeded'?'succeeded':($status==='canceled'?'cancelled':'pending');

        // Persist the provider ID before activation. The webhook can safely retry
        // if the process stops after this write but before completing the transaction.
        if($paymentId==='')throw new RuntimeException('Missing provider payment identifier');
        $pdo->prepare('UPDATE payment_orders SET provider_payment_id=?,status="pending" WHERE id=? AND status<>"succeeded"')->execute([$paymentId,$orderId]);
        if($status==='succeeded'){
            if((string)($payment['amount']['value']??'')!==number_format($amountMinor/100,2,'.','')||(string)($payment['amount']['currency']??'')!=='RUB')throw new RuntimeException('Unexpected payment amount');
            $pdo->beginTransaction();
            try{
                $claim=$pdo->prepare('UPDATE payment_orders SET status="succeeded",paid_at=CURRENT_TIMESTAMP WHERE id=? AND status<>"succeeded"');$claim->execute([$orderId]);
                if($claim->rowCount()===1){
                    $orderStmt=$pdo->prepare('SELECT * FROM payment_orders WHERE id=?');$orderStmt->execute([$orderId]);$order=$orderStmt->fetch();
                    if(!$order)throw new RuntimeException('Missing payment order');
                    subscription_activate_from_payment($pdo,$order,$payment);
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }elseif($status==='canceled'){
            $pdo->prepare('UPDATE payment_orders SET status="cancelled" WHERE id=? AND status<>"succeeded"')->execute([$orderId]);
        }

        $results[]=['user_id'=>$userId,'order_id'=>$orderId,'status'=>$localStatus];
    }catch(Throwable $e){
        // An uncertain provider response is not proof of a failed debit.
        // Leave the order created/pending for reconciliation rather than charging again.
        error_log('[UROVIA] Recurring payment requires reconciliation: order '.$orderId);
        $results[]=['user_id'=>$userId,'order_id'=>$orderId,'status'=>'requires_reconciliation'];
    }
    });
    }catch(Throwable $e){
        $results[]=['user_id'=>$userId,'status'=>'skipped_busy'];
    }
}

json_response(['ok'=>true,'processed'=>count($results),'results'=>$results]);
