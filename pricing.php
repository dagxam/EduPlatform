<?php
declare(strict_types=1);
require __DIR__ . '/api/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
$plans=subscription_plan_catalog();
function money(int $minor): string { return number_format($minor/100,0,',',' ') . ' ₽'; }
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Тарифы UROVIA</title><meta name="robots" content="index,follow">
<link rel="icon" href="favicon.png?v=3"><link rel="stylesheet" href="legal.css?v=2"></head><body><main class="legal-shell">
<header class="legal-head"><div class="legal-brand"><div class="legal-mark">U</div><div><b>UROVIA</b><span>Образовательная платформа</span></div></div>
<h1>Тарифы UROVIA</h1><p>Подписка предоставляет доступ к дополнительным возможностям образовательной платформы. Цифровая услуга активируется в аккаунте после подтверждения оплаты.</p></header>
<article class="legal-card"><section><h2>Стоимость</h2><div class="legal-pricing-grid">
<?php foreach ($plans as $plan): ?><article class="legal-price-card"><h3><?=htmlspecialchars((string)$plan['name'])?></h3>
<?php if ((int)$plan['monthly_price_minor']===0): ?><strong>0 ₽</strong><p>Базовый доступ.</p>
<?php else: ?><strong><?=money((int)$plan['monthly_price_minor'])?> / месяц</strong><p><?=money((int)$plan['yearly_price_minor'])?> / год.</p><?php endif; ?>
<ul><li>Классы: <?= $plan['max_classes']===null?'без ограничений':(int)$plan['max_classes'] ?></li>
<li>Ученики: <?= $plan['max_students']===null?'без ограничений':(int)$plan['max_students'] ?></li>
<li>Активные задания: <?= $plan['max_active_assignments']===null?'без ограничений':(int)$plan['max_active_assignments'] ?></li>
<li>Импорт PDF/DOCX/PPTX: <?= !empty($plan['features']['file_import'])?'да':'нет' ?></li>
<li>Расширенная аналитика: <?= !empty($plan['features']['advanced_analytics'])?'да':'нет' ?></li>
<li>AI-лимит: <?= (int)$plan['ai_monthly_limit']>0?(int)$plan['ai_monthly_limit'].' в месяц':'нет' ?></li></ul></article><?php endforeach; ?>
</div></section>
<section><h2>Как предоставляется услуга</h2><p>После успешного платежа платный тариф активируется в учетной записи пользователя. Доставка физического товара не осуществляется: UROVIA предоставляет цифровой доступ к функциям сервиса.</p></section>
<section><h2>Оплата и возврат</h2><p>Оплата производится через ЮKassa. Подробные условия, порядок отмены автопродления и возврата описаны в публичной оферте.</p><div class="legal-actions"><a class="primary" href="offer.php">Публичная оферта</a><a class="secondary" href="requisites.php">Реквизиты</a></div></section>
</article><footer class="legal-foot">UROVIA · Тарифы</footer></main></body></html>