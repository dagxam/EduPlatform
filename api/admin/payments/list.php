<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';

$user=require_user(['admin']);
if(!is_platform_admin($user)) json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);

$includeArchived = isset($_GET['include_archived']) && (string)$_GET['include_archived'] === '1';
$pdo=app_db();
$sql =
    'SELECT p.id,p.user_id,p.plan_code,p.billing_period,p.amount_minor,p.currency,p.provider,
            p.provider_payment_id,p.customer_email,p.receipt_status,p.receipt_reference,
            p.status,p.created_at,p.paid_at,p.archived_at,
            u.first_name,u.last_name,u.email AS account_email
     FROM payment_orders p
     JOIN users u ON u.id=p.user_id';
if(!$includeArchived){
    $sql .= ' WHERE p.archived_at IS NULL';
}
$sql .= ' ORDER BY p.id DESC LIMIT 100';

$stmt=$pdo->query($sql);
$payments=$stmt->fetchAll();

foreach($payments as &$payment){
    $status=(string)($payment['status']??'');
    $providerPaymentId=trim((string)($payment['provider_payment_id']??''));
    $paidAt=trim((string)($payment['paid_at']??''));
    $payment['removal_mode'] = (
        in_array($status,['failed','cancelled'],true)
        || ($status==='created' && $providerPaymentId==='' && $paidAt==='')
    ) ? 'delete' : 'archive';
}
unset($payment);

json_response([
    'ok'=>true,
    'payments'=>$payments,
    'include_archived'=>$includeArchived,
]);
