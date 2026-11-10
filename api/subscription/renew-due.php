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

$pdo=app_db();
$now=time();
$threshold=gmdate('Y-m-d H:i:s',$now+24*3600);
$cutoff=gmdate('Y-m-d H:i:s',$now-48*3600);

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
    $planCode=(string)$sub['plan_code'];
    $period=(string)$sub['billing_period'];
    if(!isset($plans[$planCode]) || !in_array($period,['month','year'],true)) continue;

    $pending=$pdo->prepare(
        'SELECT id FROM payment_orders
         WHERE user_id=:user_id
           AND provider="yookassa"
           AND status IN ("created","pending")
           AND created_at >= :cutoff
         ORDER BY id DESC LIMIT 1'
    );
    $pending->execute(['user_id'=>$userId,'cutoff'=>$cutoff]);
    if($pending->fetchColumn()){
        $results[]=['user_id'=>$userId,'status'=>'skipped_pending'];
        continue;
    }

    $amountMinor=$period==='year'
        ? (int)$plans[$planCode]['yearly_price_minor']
        : (int)$plans[$planCode]['monthly_price_minor'];
    if($amountMinor<1) continue;

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

        $pdo->prepare(
            'UPDATE payment_orders
             SET provider_payment_id=:payment_id,status=:status,
                 paid_at=CASE WHEN :status_paid="succeeded" THEN CURRENT_TIMESTAMP ELSE paid_at END
             WHERE id=:id'
        )->execute([
            'payment_id'=>$paymentId,
            'status'=>$localStatus,
            'status_paid'=>$localStatus,
            'id'=>$orderId,
        ]);

        if($status==='succeeded'){
            $orderStmt=$pdo->prepare('SELECT * FROM payment_orders WHERE id=:id LIMIT 1');
            $orderStmt->execute(['id'=>$orderId]);
            $order=$orderStmt->fetch();
            if($order) subscription_activate_from_payment($pdo,$order,$payment);
        }

        $results[]=['user_id'=>$userId,'order_id'=>$orderId,'status'=>$localStatus];
    }catch(Throwable $e){
        $pdo->prepare('UPDATE payment_orders SET status="failed" WHERE id=:id')->execute(['id'=>$orderId]);
        error_log('[UROVIA] YooKassa recurring payment failed for user '.$userId.': '.$e->getMessage());
        $results[]=['user_id'=>$userId,'order_id'=>$orderId,'status'=>'failed'];
    }
}

json_response(['ok'=>true,'processed'=>count($results),'results'=>$results]);
